# Primary device
DEVICE_IP = '192.168.1.201'
DEVICE_PORT = 4370
POLL_INTERVAL = 5

# Optional second device
ENABLE_SECOND_DEVICE = False
DEVICE_IP2 = '192.168.1.202'
DEVICE_PORT2 = 4370
TABLE_NAME2 = 'attendance2'

# Database
DB_HOST = '127.0.0.1'
DB_PORT = 3306
DB_USER = 'root'
DB_PASS = 'change_me'
DB_NAME = 'attendance_db'
TABLE_NAME = 'attendances'

# Logging
LOG_LEVEL = 'INFO'              # DEBUG only for troubleshooting
LOG_DIR = 'logs'

# Device timezone used in logs
DEVICE_TIMEZONE = 'Asia/Dhaka'
