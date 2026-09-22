"""Sync attendance records from one or two ZKTeco devices."""
import time
import sys
import os
import signal
import threading
from datetime import datetime
from zk import ZK
import pymysql
import logging
from logging.handlers import RotatingFileHandler

try:
    import config as cfg
except ImportError:
    print("ERROR: config.py not found. Copy config.example.py to config.py and edit it.", file=sys.stderr)
    sys.exit(1)

STOP_EVENT = threading.Event()

def setup_logging(device_name: str, log_level: str = None):
    """Create a logger with file and console output."""
    level_name = (log_level or getattr(cfg, 'LOG_LEVEL', 'INFO')).upper()
    level = getattr(logging, level_name, logging.INFO)
    logger = logging.getLogger(f'device_sync.{device_name}')
    logger.setLevel(level)
    logger.handlers.clear()
    logger.propagate = False

    log_dir = getattr(cfg, 'LOG_DIR', 'logs')
    os.makedirs(log_dir, exist_ok=True)

    fh = RotatingFileHandler(f'{log_dir}/device_sync_{device_name}.log', maxBytes=5*1024*1024, backupCount=7)
    fh.setLevel(level)
    ch = logging.StreamHandler(sys.stdout)
    ch.setLevel(level)
    fmt = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    fh.setFormatter(fmt); ch.setFormatter(fmt)
    logger.addHandler(fh); logger.addHandler(ch)
    return logger

def connect_db(logger, max_retries=5, retry_delay=5):
    for attempt in range(1, max_retries+1):
        try:
            conn = pymysql.connect(
                host=cfg.DB_HOST, port=getattr(cfg,'DB_PORT',3306),
                user=cfg.DB_USER, password=cfg.DB_PASS, database=cfg.DB_NAME,
                charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor,
                autocommit=True, connect_timeout=10
            )
            logger.info("Database connected")
            return conn
        except Exception as e:
            logger.warning(f"Database connection {attempt}/{max_retries} failed: {e}")
            if attempt < max_retries:
                time.sleep(retry_delay)
    logger.error("Database connection failed after retries")
    return None

def ensure_db(logger):
    conn = connect_db(logger)
    while conn is None and not STOP_EVENT.is_set():
        logger.error("Database unavailable; retrying in 10s")
        STOP_EVENT.wait(10)
        if STOP_EVENT.is_set(): return None
        conn = connect_db(logger)
    return conn

def check_db_alive(conn):
    try: conn.ping(reconnect=True); return True
    except: return False

def test_device_params(logger, ip, port):
    logger.info(f"Probing {ip}:{port}")
    for force_udp in (False, True):
        for ommit_ping in (False, True):
            if STOP_EVENT.is_set(): return None
            try:
                zk = ZK(ip, port=port, timeout=5, force_udp=force_udp, ommit_ping=ommit_ping)
                conn = zk.connect()
                if conn:
                    try:
                        att = conn.get_attendance()
                        cnt = len(att) if att else 0
                        logger.info(f"Probe succeeded: force_udp={force_udp}, ommit_ping={ommit_ping}, records={cnt}")
                    finally:
                        conn.disconnect()
                    return {'force_udp': force_udp, 'ommit_ping': ommit_ping}
            except Exception as e:
                logger.debug(f"Probe failed: force_udp={force_udp}, ommit_ping={ommit_ping}: {e}")
    return None

def connect_device(logger, ip, port, params, timeout=20):
    try:
        zk = ZK(ip, port=port, timeout=timeout, force_udp=params.get('force_udp', False), ommit_ping=params.get('ommit_ping', False))
        conn = zk.connect()
        if conn:
            logger.info(f"Connected to {ip}:{port}")
            return conn
    except Exception as e:
        logger.warning(f"Connection to {ip}:{port} failed: {e}")
    return None

def get_last_timestamp(conn, table):
    try:
        with conn.cursor() as cur:
            cur.execute(f"SELECT MAX(timestamp) AS last_ts FROM `{table}`")
            row = cur.fetchone()
            return row['last_ts'] if row and row['last_ts'] else None
    except Exception as e:
        logging.getLogger('device_sync').error(f"get_last_timestamp {table} failed: {e}")
        return None

def bulk_insert(logger, conn, table, records):
    """Insert records in batches."""
    if not records: return 0
    CHUNK = 200
    inserted = 0
    for i in range(0, len(records), CHUNK):
        chunk = records[i:i+CHUNK]
        try:
            with conn.cursor() as cur:
                cur.executemany(
                    f"INSERT IGNORE INTO `{table}` (user_id, timestamp, status, punch, message, created_at, updated_at) "
                    "VALUES (%s,%s,%s,%s,%s,NOW(),NOW())",
                    [(r.user_id, r.timestamp, getattr(r,'status',''), getattr(r,'punch',''), getattr(r,'message','')) for r in chunk]
                )
                if cur.rowcount > 0:
                    inserted += cur.rowcount
                    for record in chunk:
                        logger.info(
                            "Inserted attendance: user_id=%s, timestamp=%s, status=%s, punch=%s",
                            record.user_id,
                            record.timestamp,
                            getattr(record, 'status', ''),
                            getattr(record, 'punch', ''),
                        )
                else:
                    logger.debug(f"No new rows inserted into {table}")
        except Exception as e:
            logger.error(f"Insert into {table} failed: {e}")
            return None
    return inserted

def poll_device(device: dict):
    """
    device = {"name": "primary", "ip": "...", "port": 4370, "table": "attendances"}
    Runs forever until STOP_EVENT.
    """
    name = device['name']
    ip = device['ip']; port = device['port']; table = device['table']
    logger = setup_logging(name)
    logger.info(f"Polling {ip}:{port} into `{table}`")

    params = None
    while params is None and not STOP_EVENT.is_set():
        params = test_device_params(logger, ip, port)
        if params is None:
            logger.error(f"No working connection parameters for {ip}:{port}; retrying in 30s")
            STOP_EVENT.wait(30)
    if params is None:
        return
    logger.info(f"Using connection parameters {params}")

    conn_db = ensure_db(logger)
    if conn_db is None:
        logger.error("Database unavailable; stopping")
        return
    last_ts = get_last_timestamp(conn_db, table)
    logger.info(f"Last database timestamp: {last_ts}")

    poll_interval = getattr(cfg, 'POLL_INTERVAL', 5)
    backoff = 5

    while not STOP_EVENT.is_set():
        conn_dev = None
        for _ in range(3):
            if STOP_EVENT.is_set(): break
            conn_dev = connect_device(logger, ip, port, params)
            if conn_dev: break
            logger.warning(f"Device connection failed; retrying in {backoff}s")
            STOP_EVENT.wait(backoff)
            backoff = min(backoff*2, 60)
        if not conn_dev:
            logger.error("Device unreachable; retrying in 30s")
            STOP_EVENT.wait(30)
            continue
        backoff = 5

        try:
            logger.info("Polling started")
            while not STOP_EVENT.is_set():
                if not check_db_alive(conn_db):
                    logger.warning("Database connection lost; reconnecting")
                    conn_db = ensure_db(logger)
                    if conn_db is None: break

                try:
                    attendance = conn_dev.get_attendance()
                except Exception as e:
                    logger.error(f"Attendance fetch failed: {e}; reconnecting")
                    break

                if not attendance:
                    STOP_EVENT.wait(poll_interval)
                    continue

                new_records = []
                for rec in attendance:
                    if not isinstance(rec.timestamp, datetime):
                        logger.warning(f"Invalid timestamp for user {getattr(rec,'user_id', '?')}")
                        continue
                    if last_ts and rec.timestamp <= last_ts:
                        continue
                    new_records.append(rec)

                if not new_records:
                    logger.debug(f"Fetched {len(attendance)} records; no new records")
                    STOP_EVENT.wait(poll_interval)
                    continue

                new_records.sort(key=lambda r: r.timestamp)
                inserted = bulk_insert(logger, conn_db, table, new_records)
                if inserted is None:
                    logger.warning("Database write failed; reconnecting")
                    try:
                        conn_db.close()
                    except Exception:
                        pass
                    conn_db = ensure_db(logger)
                    if conn_db is None:
                        break
                    continue
                last_ts = new_records[-1].timestamp

                STOP_EVENT.wait(poll_interval)

        except Exception as e:
            logger.error(f"Polling failed: {e}", exc_info=True)
            STOP_EVENT.wait(5)
        finally:
            try: conn_dev.disconnect()
            except: pass
            logger.info("Device disconnected; reconnecting")

    try: conn_db.close()
    except: pass
    logger.info("Stopped")

def main():
    def _handle_stop(signum, frame):
        print(f"\nSignal {signum} received; stopping")
        STOP_EVENT.set()
    signal.signal(signal.SIGINT, _handle_stop)
    signal.signal(signal.SIGTERM, _handle_stop)

    devices = []
    if not hasattr(cfg, 'DEVICE_IP') or not hasattr(cfg, 'TABLE_NAME'):
        print("ERROR: config.py must define DEVICE_IP and TABLE_NAME", file=sys.stderr); sys.exit(1)
    devices.append({"name":"primary","ip":cfg.DEVICE_IP,"port":getattr(cfg,'DEVICE_PORT', getattr(cfg,'PORT',4370)),"table":cfg.TABLE_NAME})

    if getattr(cfg, 'ENABLE_SECOND_DEVICE', False):
        if not hasattr(cfg, 'DEVICE_IP2') or not hasattr(cfg, 'TABLE_NAME2'):
            print("ERROR: ENABLE_SECOND_DEVICE=True but DEVICE_IP2/TABLE_NAME2 missing", file=sys.stderr); sys.exit(1)
        devices.append({"name":"secondary","ip":cfg.DEVICE_IP2,"port":getattr(cfg,'DEVICE_PORT2', getattr(cfg,'PORT2',4370)),"table":cfg.TABLE_NAME2})

    print(f"ZKTeco sync: {len(devices)} device(s) {[d['name']+':'+d['ip'] for d in devices]}")
    print(f"Poll interval {getattr(cfg,'POLL_INTERVAL',5)}s | DB {cfg.DB_HOST}/{cfg.DB_NAME}")
    print("Press Ctrl+C to stop.\n")

    if len(devices) == 1:
        poll_device(devices[0])
    else:
        threads = []
        for dev in devices:
            t = threading.Thread(target=poll_device, args=(dev,), daemon=True, name=f"sync-{dev['name']}")
            t.start(); threads.append(t)
        try:
            while not STOP_EVENT.is_set():
                time.sleep(1)
                for t in threads:
                    if not t.is_alive() and not STOP_EVENT.is_set():
                        logging.getLogger('device_sync').error(f"Thread {t.name} died unexpectedly")
        except KeyboardInterrupt:
            STOP_EVENT.set()
        for t in threads:
            t.join(timeout=5)
        print("All devices stopped.")

if __name__ == "__main__":
    main()
