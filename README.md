# ZK Attendance

> An application that securely syncs physical ZKTeco device data with a web application over the internet. It uses Python to fetch and sync data to the server database, with network routing to expose a local API online, and displays everything through a PHP Laravel-based interactive web application.

[![Python 3.8+](https://img.shields.io/badge/python-3.8+-blue)](https://python.org) [![Laravel 12](https://img.shields.io/badge/laravel-12-red)](https://laravel.com) [![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

### How It Works
```
ZKTeco Device (TCP 4370) → Python Sync → Network Routing → MySQL → Laravel Dashboard
```

### Features
- **Dashboard** — Present / Absent / Late / Avg Hours + attendance charts
- **Daily** — Filter by date & department, flag Late / Early / On Time
- **Monthly / User-wise** — Grouped reports with pagination
- **User Directory** — Manage users, departments, display order
- **Door Monitor** — Live total today + last 5 punches (2nd device optional)
- **Settings** — Weekend days, holidays, office hours & grace period

### Tech Stack
- **Sync:** Python 3, `pyzk`, `PyMySQL`
- **Routing:** Network routing to expose local API online
- **Backend:** Laravel 12, MySQL 8
- **Frontend:** Bootstrap 5, DataTables, Chart.js

### Quick Start

**1. Device sync (Python)**
```bash
git clone https://github.com/your-org/zk-attendance.git
cd zk-attendance
cp example.config.py config.py
# edit config.py: DEVICE_IP, DB_*, leave ENABLE_SECOND_DEVICE=False
pip install -r requirements.txt
python create_db_.py
python device_sync.py
```

**2. Dashboard (Laravel)**
```bash
cd dashboard
composer install
cp .env.example .env
# edit .env: DB_* and DASHBOARD_PASSWORD_HASH
php artisan key:generate
php artisan migrate --force
php artisan serve --host=0.0.0.0 --port=8081
# open http://localhost:8081
```

Create password hash:
```bash
php artisan tinker --execute "echo Hash::make('your-password');"
```

### Configuration

**`config.py`**
```python
DEVICE_IP = "192.168.1.201"
DEVICE_PORT = 4370
DB_HOST = "127.0.0.1"
DB_NAME = "attendance_db"
ENABLE_SECOND_DEVICE = False
POLL_INTERVAL = 5
```

**`dashboard/.env`**
```env
DB_DATABASE=attendance_db
DB_USERNAME=root
DB_PASSWORD=secret
DASHBOARD_PASSWORD_HASH=$2y$12$...
ATTENDANCE2_ENABLED=false
```

### Production Deployment

```bash
sudo tee /etc/systemd/system/zkteco-sync.service >/dev/null <<'UNIT'
[Unit]
Description=ZKTeco sync
After=network.target mysql.service
[Service]
User=ubuntu
WorkingDirectory=/var/www/zk
ExecStart=/usr/bin/python3 /var/www/zk/device_sync.py
Restart=always
[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload && sudo systemctl enable --now zkteco-sync
```

### License
MIT
