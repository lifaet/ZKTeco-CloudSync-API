# config.example.py — copy to config.py and fill real values
# Standard: ONE device. Set ENABLE_SECOND_DEVICE = True only when you need 2nd device.

# --- Primary Device (required) ---
DEVICE_IP = '192.168.1.201'
DEVICE_PORT = 4370              # renamed from PORT (clearer)
POLL_INTERVAL = 5               # seconds between device reads

# --- Optional Second Device (off by default) ---
ENABLE_SECOND_DEVICE = False
DEVICE_IP2 = '192.168.1.202'
DEVICE_PORT2 = 4370
TABLE_NAME2 = 'attendance2'

# --- Database (single DB for both devices) ---
DB_HOST = '127.0.0.1'
DB_PORT = 3306
DB_USER = 'root'
DB_PASS = 'change_me'
DB_NAME = 'attendance_db'
TABLE_NAME = 'attendances'      # primary device table

# --- Logging ---
LOG_LEVEL = 'INFO'              # DEBUG only for troubleshooting
LOG_DIR = 'logs'

# --- Misc ---
# Timezone of devices (for logging only — DB stores as DATETIME without TZ)
DEVICE_TIMEZONE = 'Asia/Dhaka'
