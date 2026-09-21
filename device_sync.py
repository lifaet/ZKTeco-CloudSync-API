"""
device_sync.py — Standard single-device sync, optional 2nd device via config

  Standard use (1 device):
    cp config.example.py config.py   # edit DEVICE_IP etc.
    python device_sync.py

  With 2 devices (when needed):
    edit config.py: ENABLE_SECOND_DEVICE = True + set DEVICE_IP2/PORT2/TABLE_NAME2
    python device_sync.py             # same file, spawns 2 threads

Replaces BOTH device_sync.py and device_sync2.py — no duplicated code.
Deletes api_server.py dependency (not used).

Improvements over original:
  - One file, DRY, single logger factory
  - Batch INSERT with executemany (not one-by-one)
  - Per-device last_ts tracking (no global shared state)
  - Threaded when 2 devices enabled, gracefull shutdown on SIGINT/SIGTERM
  - Caches working force_udp/ommit_ping after first probe
  - LOG_LEVEL via config, no duplicate handlers
  - UTC-naive DB timestamps use NOW() in SQL (no clock skew)
"""
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

# ---- Config ----
try:
    import config as cfg
except ImportError:
    print("ERROR: config.py not found. Copy config.example.py -> config.py and edit it.", file=sys.stderr)
    sys.exit(1)

STOP_EVENT = threading.Event()

def setup_logging(device_name: str, log_level: str = None):
    """One logger per device — no duplicate handlers on reload."""
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
            logger.info("DB connected")
            return conn
        except Exception as e:
            logger.warning(f"DB connect attempt {attempt}/{max_retries} failed: {e}")
            if attempt < max_retries:
                time.sleep(retry_delay)
    logger.error("DB connect failed after retries")
    return None

def ensure_db(logger):
    conn = connect_db(logger)
    while conn is None and not STOP_EVENT.is_set():
        logger.error("DB unavailable, retrying in 10s...")
        STOP_EVENT.wait(10)
        if STOP_EVENT.is_set(): return None
        conn = connect_db(logger)
    return conn

def check_db_alive(conn):
    try: conn.ping(reconnect=True); return True
    except: return False

def test_device_params(logger, ip, port):
    logger.info(f"Probing device {ip}:{port}")
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
                        logger.info(f"Probe ok force_udp={force_udp} ommit_ping={ommit_ping} records={cnt}")
                    finally:
                        conn.disconnect()
                    return {'force_udp': force_udp, 'ommit_ping': ommit_ping}
            except Exception as e:
                logger.debug(f"Probe force_udp={force_udp} ommit_ping={ommit_ping} failed: {e}")
    return None

def connect_device(logger, ip, port, params, timeout=20):
    try:
        zk = ZK(ip, port=port, timeout=timeout, force_udp=params.get('force_udp', False), ommit_ping=params.get('ommit_ping', False))
        conn = zk.connect()
        if conn:
            logger.info(f"Device {ip}:{port} connected")
            return conn
    except Exception as e:
        logger.warning(f"Device connect {ip}:{port} failed: {e}")
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
    """Batch insert — one round-trip per chunk."""
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
                    logger.info(f"Inserted {cur.rowcount} rows into {table} (chunk {i//CHUNK+1})")
                else:
                    logger.debug(f"No new rows in chunk {i//CHUNK+1} ({table})")
        except Exception as e:
            logger.error(f"Bulk insert {table} chunk failed: {e}")
    return inserted

def poll_device(device: dict):
    """
    device = {"name": "primary", "ip": "...", "port": 4370, "table": "attendances"}
    Runs forever until STOP_EVENT.
    """
    name = device['name']
    ip = device['ip']; port = device['port']; table = device['table']
    logger = setup_logging(name)
    logger.info(f"Starting poll for {name} -> {ip}:{port} -> table `{table}`")

    params = test_device_params(logger, ip, port)
    if not params:
        logger.error(f"[{name}] No working connection params for {ip}:{port} — skipping device")
        return
    logger.info(f"[{name}] using params {params}")

    conn_db = ensure_db(logger)
    if conn_db is None:
        logger.error(f"[{name}] No DB, exiting thread")
        return
    last_ts = get_last_timestamp(conn_db, table)
    logger.info(f"[{name}] last_ts in DB: {last_ts}")

    poll_interval = getattr(cfg, 'POLL_INTERVAL', 5)
    backoff = 5

    while not STOP_EVENT.is_set():
        conn_dev = None
        for _ in range(3):
            if STOP_EVENT.is_set(): break
            conn_dev = connect_device(logger, ip, port, params)
            if conn_dev: break
            logger.warning(f"[{name}] device connect failed, retry in {backoff}s")
            STOP_EVENT.wait(backoff)
            backoff = min(backoff*2, 60)
        if not conn_dev:
            logger.error(f"[{name}] device unreachable, backing off 30s")
            STOP_EVENT.wait(30)
            continue
        backoff = 5

        try:
            logger.info(f"[{name}] polling loop started")
            while not STOP_EVENT.is_set():
                if not check_db_alive(conn_db):
                    logger.warning(f"[{name}] DB lost, reconnecting...")
                    conn_db = ensure_db(logger)
                    if conn_db is None: break

                try:
                    attendance = conn_dev.get_attendance()
                except Exception as e:
                    logger.error(f"[{name}] get_attendance error: {e} — reconnecting device")
                    break

                if not attendance:
                    STOP_EVENT.wait(poll_interval)
                    continue

                # Filter to only new records
                new_records = []
                for rec in attendance:
                    if not isinstance(rec.timestamp, datetime):
                        logger.warning(f"[{name}] bad timestamp for user {getattr(rec,'user_id', '?')}")
                        continue
                    if last_ts and rec.timestamp <= last_ts:
                        continue
                    new_records.append(rec)

                if not new_records:
                    logger.debug(f"[{name}] {len(attendance)} fetched, 0 new (last_ts={last_ts})")
                    STOP_EVENT.wait(poll_interval)
                    continue

                # Sort to ensure last_ts advances monotonically
                new_records.sort(key=lambda r: r.timestamp)
                logger.info(f"[{name}] {len(attendance)} fetched, {len(new_records)} new — inserting...")
                bulk_insert(logger, conn_db, table, new_records)
                last_ts = new_records[-1].timestamp

                STOP_EVENT.wait(poll_interval)

        except Exception as e:
            logger.error(f"[{name}] polling loop crashed: {e}", exc_info=True)
            STOP_EVENT.wait(5)
        finally:
            try: conn_dev.disconnect()
            except: pass
            logger.info(f"[{name}] device disconnected, will reconnect")

    try: conn_db.close()
    except: pass
    logger.info(f"[{name}] stopped")

def main():
    # Handle Ctrl+C / systemd SIGTERM
    def _handle_stop(signum, frame):
        print(f"\nReceived signal {signum}, stopping...")
        STOP_EVENT.set()
    signal.signal(signal.SIGINT, _handle_stop)
    signal.signal(signal.SIGTERM, _handle_stop)

    # Build device list from config
    devices = []
    # primary — required
    if not hasattr(cfg, 'DEVICE_IP') or not hasattr(cfg, 'TABLE_NAME'):
        print("ERROR: config.py must define DEVICE_IP and TABLE_NAME", file=sys.stderr); sys.exit(1)
    devices.append({"name":"primary","ip":cfg.DEVICE_IP,"port":getattr(cfg,'DEVICE_PORT', getattr(cfg,'PORT',4370)),"table":cfg.TABLE_NAME})

    # optional second
    if getattr(cfg, 'ENABLE_SECOND_DEVICE', False):
        if not hasattr(cfg, 'DEVICE_IP2') or not hasattr(cfg, 'TABLE_NAME2'):
            print("ERROR: ENABLE_SECOND_DEVICE=True but DEVICE_IP2/TABLE_NAME2 missing", file=sys.stderr); sys.exit(1)
        devices.append({"name":"secondary","ip":cfg.DEVICE_IP2,"port":getattr(cfg,'DEVICE_PORT2', getattr(cfg,'PORT2',4370)),"table":cfg.TABLE_NAME2})

    print(f"ZKTeco sync — {len(devices)} device(s): {[d['name']+':'+d['ip'] for d in devices]}")
    print(f"Poll interval {getattr(cfg,'POLL_INTERVAL',5)}s | DB {cfg.DB_HOST}/{cfg.DB_NAME}")
    print("Press Ctrl+C to stop\n")

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
                # if any thread died unexpectedly, log
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
