"""
create_db_fixed.py — creates DB + tables if missing
Use Laravel migrations as source of truth when possible:
  cd dashboard && php artisan migrate
This script is a standalone fallback for pure-Python deploys.

Changes vs original:
 - Single entry for both tables, reuses PRIMARY standard config
 - Optional 2nd table only if ENABLE_SECOND_DEVICE
 - Uses IF NOT EXISTS + proper FK type alignment (user_id INT unsigned vs INT)
 - No insertion test that leaves rows
"""
import pymysql, os, sys, logging, time
from logging.handlers import RotatingFileHandler

try:
    import config as cfg
except ImportError:
    print("config.py missing — copy config.example.py", file=sys.stderr); sys.exit(1)

def log():
    l = logging.getLogger('create_db'); l.setLevel(logging.INFO)
    l.handlers.clear()
    h = logging.StreamHandler(sys.stdout); h.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))
    l.addHandler(h)
    if not os.path.exists('logs'): os.makedirs('logs')
    fh = RotatingFileHandler('logs/create_db.log', maxBytes=2*1024*1024, backupCount=3)
    fh.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')); l.addHandler(fh)
    return l

logger = log()

def conn(database=None):
    return pymysql.connect(host=cfg.DB_HOST, port=getattr(cfg,'DB_PORT',3306), user=cfg.DB_USER, password=cfg.DB_PASS,
                           database=database, charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor, autocommit=True, connect_timeout=10)

def ensure():
    c = None
    try:
        logger.info("Connecting to MySQL...")
        c = conn()
        with c.cursor() as cur:
            cur.execute(f"CREATE DATABASE IF NOT EXISTS `{cfg.DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
            cur.execute(f"USE `{cfg.DB_NAME}`")
            # users / settings are managed by Laravel — create here only if missing
            cur.execute("""
                CREATE TABLE IF NOT EXISTS users (
                  id INT NOT NULL PRIMARY KEY,
                  name VARCHAR(255) NOT NULL,
                  title VARCHAR(255) DEFAULT NULL,
                  department VARCHAR(255) DEFAULT NULL,
                  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
                  active TINYINT(1) NOT NULL DEFAULT 1,
                  view_order INT DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """)
            cur.execute("""
                CREATE TABLE IF NOT EXISTS settings (
                  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
                  `value` TEXT DEFAULT NULL,
                  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """)
            # primary attendances
            cur.execute(f"""
                CREATE TABLE IF NOT EXISTS `{cfg.TABLE_NAME}` (
                  id BIGINT AUTO_INCREMENT PRIMARY KEY,
                  user_id VARCHAR(50) NOT NULL,
                  timestamp DATETIME NOT NULL,
                  status VARCHAR(50) DEFAULT NULL, punch VARCHAR(50) DEFAULT NULL,
                  message VARCHAR(255) DEFAULT NULL,
                  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                  UNIQUE KEY uq_user_time (user_id, timestamp),
                  INDEX idx_timestamp (timestamp), INDEX idx_user_id (user_id), INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """)
            # optional second
            if getattr(cfg,'ENABLE_SECOND_DEVICE', False):
                cur.execute(f"""
                    CREATE TABLE IF NOT EXISTS `{cfg.TABLE_NAME2}` (
                      id BIGINT AUTO_INCREMENT PRIMARY KEY,
                      user_id VARCHAR(50) NOT NULL, timestamp DATETIME NOT NULL,
                      status VARCHAR(50) DEFAULT NULL, punch VARCHAR(50) DEFAULT NULL, message VARCHAR(255) DEFAULT NULL,
                      created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                      UNIQUE KEY uq_user_time (user_id, timestamp),
                      INDEX idx_timestamp (timestamp), INDEX idx_user_id (user_id), INDEX idx_created_at (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """)
                logger.info(f"Tables `{cfg.TABLE_NAME}` and `{cfg.TABLE_NAME2}` ready")
            else:
                logger.info(f"Table `{cfg.TABLE_NAME}` ready (second device disabled)")

            # verify write
            for t in [cfg.TABLE_NAME] + ([cfg.TABLE_NAME2] if getattr(cfg,'ENABLE_SECOND_DEVICE',False) else []):
                cur.execute(f"SELECT 1 FROM `{t}` LIMIT 1")
                cur.fetchone()
            logger.info("Verification OK")
            return True
    except Exception as e:
        logger.error(f"Failed: {e}", exc_info=True); return False
    finally:
        if c: c.close()

if __name__ == "__main__":
    ok = ensure()
    sys.exit(0 if ok else 1)
