# BuildFlow — XAMPP Deployment Guide (Apache + PHP + MySQL/MariaDB)

This folder contains the complete, ready-to-run **XAMPP** edition of BuildFlow with a compiled React frontend, a PHP 8+ PDO REST API, and a MySQL/MariaDB relational database.

## Folder Contents

- `index.html` & `assets/` — Compiled production frontend (relative paths so it runs in any `htdocs` subfolder)
- `.htaccess` — Apache rewrite rules routing `/api/*` to `api/index.php`
- `api/` — Modular PHP 8+ REST backend (`auth.php`, `projects.php`, `workspace.php`, `budget_collab.php`)
- `config/database.php` — MySQL PDO connection settings (default XAMPP: `127.0.0.1`, user `root`, blank password)
- `database/buildflow_mysql.sql` — Complete MySQL/MariaDB schema + seeded demo data
- `uploads/` — Protected upload folders (`receipts/`, `documents/`, `attachments/`) blocked from direct URL browsing via `.htaccess`

---

## Setup Instructions (4 Steps)

### 1. Start Apache & MySQL in XAMPP
Open **XAMPP Control Panel** and click **Start** on both **Apache** and **MySQL**.

### 2. Copy Files to `htdocs`
Copy the contents of this package into a folder named `buildflow` inside your XAMPP `htdocs` directory:
- **Windows:** `C:\xampp\htdocs\buildflow\`
- **macOS:** `/Applications/XAMPP/htdocs/buildflow/`
- **Linux:** `/opt/lampp/htdocs/buildflow/`

*(Verify that `index.html`, `.htaccess`, `api/`, `config/`, `database/`, and `uploads/` sit directly inside `htdocs/buildflow/`.)*

### 3. Import the Database in phpMyAdmin
1. Open **`http://localhost/phpmyadmin`** in your browser.
2. Click the **Import** tab at the top.
3. Click **Choose File** and select `database/buildflow_mysql.sql` inside your `buildflow` folder.
4. Scroll down and click **Import** (or **Go**).
   - This automatically creates the `buildflow` database, all 14 tables with foreign keys, and pre-seeded demo accounts, project rooms, BOM components, tasks, meetings, and receipts.

*(Optional: If your MySQL `root` user has a custom password, edit `config/database.php` and set `BF_DB_PASS`.)*

### 4. Open BuildFlow
Visit:
**`http://localhost/buildflow/`**

---

## Pre-Seeded Demo Accounts

| Role | Name | Email | Password |
| :--- | :--- | :--- | :--- |
| Project Leader (`Robotics Lab 04`) | Alex Chen | `alex.chen@uni.edu` | `demo1234` |
| Member (`Robotics Lab 04`) / Leader (`IoT Greenhouse`) | Maya Patel | `maya.patel@uni.edu` | `demo1234` |
| Member (`Robotics Lab 04`) | Liam Silva | `liam.silva@uni.edu` | `demo1234` |

- **Join Room Test**: Room ID `BF-ROVR04`, Password `rover2026` (or `BF-HYDR02` / `hydro2026`).

---

## Troubleshooting

- **404 on `/api/...` calls**: Ensure Apache `mod_rewrite` is enabled in `httpd.conf` (`LoadModule rewrite_module modules/mod_rewrite.so`) and `AllowOverride All` is set for `htdocs` (both are enabled by default in modern XAMPP).
- **Database connection error**: Confirm MySQL is running in XAMPP on port `3306` and `database/buildflow_mysql.sql` was imported.
- **File upload permissions (macOS/Linux)**: Ensure `uploads/` and `config/` are writable by the Apache user (`chmod -R 775 uploads config`).
