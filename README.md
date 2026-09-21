# ZK Attendance

Single-device standard attendance system for ZKTeco devices. Python sync writes punches to MySQL, Laravel dashboard displays Daily / Monthly / User views.

> **Standard is 1 device.** Second device is optional — same `device_sync.py` handles both when `ENABLE_SECOND_DEVICE=True`. `api_server.py` (Flask) was removed — not used in production (see `DEPRECATED_api_server.md`).

### Architecture
```
ZKTeco Device (TCP 4370) ──► device_sync.py ──► MySQL (attendances) ──► Laravel Dashboard (DataTables)
                         └─► (optional) attendance2 when 2 devices enabled
```

### Repository layout
```
.
├── device_sync.py              # Unified sync — 1 device by default, optional 2nd via config flag
├── example.config.py           # Copy to config.py — single-device standard
├── create_db_.py               # Create DB/tables if missing (idempotent)
├── requirements.txt / req.txt  # Python deps (pymysql, pyzk)
├── dashboard/                  # Laravel 12 app
│   ├── app/Http/Middleware/DashboardAuth.php
│   ├── app/Http/Controllers/AttendanceController.php  # SQL-grouped monthly/user, validated
│   ├── database/migrations/    # now includes attendances table (was missing)
│   └── resources/views/dashboard.blade.php
├── logs/                       # device_sync_primary.log / _secondary.log
└── DEPRECATED_api_server.md
```
Deleted: `api_server.py`, `device_sync2.py` (merged).

### Requirements
- Python 3.8+ (`python3`), MySQL 8+, PHP 8.2+, Composer

### Quick start — 1 device (standard)

**Python sync**
```bash
cp example.config.py config.py
nano config.py   # set DEVICE_IP, DB_HOST/USER/PASS/NAME, leave ENABLE_SECOND_DEVICE=False
pip3 install -r requirements.txt --user
# or: python3 -m venv .venv && source .venv/bin/activate && pip install -r requirements.txt

python3 create_db_.py
python3 device_sync.py   # polls every POLL_INTERVAL s, batch inserts 200 rows, logs to logs/device_sync_primary.log
```

**Laravel dashboard**
```bash
cd dashboard
composer install
cp .env.example .env
nano .env   # set DB_* and DASHBOARD_PASSWORD_HASH (see below)
php artisan key:generate
php artisan migrate --force   # now idempotent — users/attendance2 have hasTable guards
php artisan config:clear
php artisan serve --host=0.0.0.0 --port=8081
# visit http://127.0.0.1:8081/  login with password that matches DASHBOARD_PASSWORD_HASH
```

### Config — Python (`config.py`)

| Key | Default | Notes |
|-----|---------|-------|
| `DEVICE_IP` / `DEVICE_PORT` | `192.168.1.201:4370` | Primary device — required |
| `ENABLE_SECOND_DEVICE` | `False` | Set `True` to enable 2nd device |
| `DEVICE_IP2` / `DEVICE_PORT2` / `TABLE_NAME2` | — | Used only when enabled |
| `DB_HOST/PORT/USER/PASS/NAME` | | Same DB for both devices |
| `TABLE_NAME` | `attendances` | Primary table |
| `POLL_INTERVAL` | `5` | Seconds between `get_attendance()` |
| `LOG_LEVEL` / `LOG_DIR` | `INFO` / `logs` | Per-device `device_sync_primary.log` |

Example for 2 devices:
```python
ENABLE_SECOND_DEVICE = True
DEVICE_IP2 = '192.168.1.202'
DEVICE_PORT2 = 4370
TABLE_NAME2 = 'attendance2'
```

### Config — Laravel (`dashboard/.env`)

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_db
DB_USERNAME=root
DB_PASSWORD=...

# Auth — use bcrypt hash (recommended)
# Generate: php artisan tinker --execute "echo Hash::make('your-password');"
DASHBOARD_PASSWORD_HASH=$2y$12$...

# Fallback plain for dev only:
DASHBOARD_PASSWORD=secret
```

`DashboardAuth` middleware protects all `/api/*` + `/`. Login now does `Hash::check` + `session()->regenerate()` and throttles `10/min`.

### Dashboard features

- **Filters:** Daily (date), Monthly (`YYYY-MM`), User-wise (search)
- **Columns:** User, Date, Last Day Last Punch, First/Last Punch, Work Time, Type, VerifyID, Actions
- **Actions:** Edit (first/last punch, punch/status), Delete (all punches for user+date), Add Attendance
- **User Directory:** CRUD via `/api/users` (id, name, title, department, view_order, active)
- **Settings:** Weekend days (0=Sun…6=Sat, default Fri+Sat) + holidays (`YYYY-MM-DD`) → controls “Last Working Day”
- **Copy/Export:** Client-side TSV/CSV of filtered view

### Performance & security fixes (2026-09-21 patch)

- One `device_sync.py` instead of duplicated `device_sync.py` / `device_sync2.py`
- Batch `executemany(200)` + `NOW()` in SQL (no clock skew), per-device `last_ts`, threaded when 2 devices
- Flask `api_server.py` removed (unused)
- `req.txt` fixed (removed stdlib entries) + `requirements.txt` added
- Laravel: `DashboardAuth` middleware, `config/dashboard.php`, bcrypt hash, `throttle:10,1`, removed duplicate `GET /api/users` and hardcoded `GET /api-test` token
- MySQL fix: `strftime` (SQLite) → `TIMESTAMPDIFF` / `DATE_FORMAT`, SQL `GROUP BY` + pagination (was full-table `get()` → OOM)
- Validation on `add/update/delete`, added missing `attendances` migration, idempotent `users`/`attendance2` migrations
- Sanitized `.env.example` `APP_KEY` placeholder

### Troubleshooting

**Migrate says `Base table or view already exists: users`**
```bash
php artisan migrate:status   # if users shows Pending but table exists:
mysql -u root -p -e "INSERT INTO attendance_db.migrations (migration,batch) VALUES ('2025_12_17_000001_create_users_table',1);"
php artisan migrate --force
```
Or fresh: `php artisan migrate:fresh --seed` (drops data).

**Device not connecting**
```bash
tail -f logs/device_sync_primary.log
# increase LOG_LEVEL=DEBUG in config.py, retry
```

**Dashboard 401 on /api/* when logged out** — expected, login first. Routes are now behind `dashboard.auth`.

### Running in production (no Docker)

Systemd example (`/etc/systemd/system/zkteco-sync.service`):
```ini
[Unit]
Description=ZKTeco single-device sync
After=network.target mysql.service
[Service]
WorkingDirectory=/opt/ZKTeco-api
ExecStart=/opt/ZKTeco-api/.venv/bin/python /opt/ZKTeco-api/device_sync.py
Restart=always
RestartSec=10
[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now zkteco-sync
```

### Git

Base patch: `ZKTeco-single-device.patch` (`git apply`) / `ZKTeco-single-device.gitpatch` (`git am`) from `b99218d`. README was updated after that patch — apply `README_update.patch` if you already applied the base.
