# CEFMS - Cultural Events and Festivals Management System
CSC2854 Final Project | Fazrique Isyraf Bin Abu Bakar (BCS2403-011, DCS 6B)

PHP 8.x + MySQL/MariaDB (PDO). Public site (Public / Event Participant) and Admin panel (Organizer / Event Manager).

## Run (easiest, Windows + XAMPP)
Install XAMPP, then double-click `START_CEFMS.bat`. It starts MySQL, imports the database the first time, starts the site and opens the browser.

## Run (manual)
1. Import the database: `mysql -u root < sql/cefms_demo.sql`  (empty schema only: `sql/schema.sql`)
2. DB login: set `CEFMS_DB_USER` / `CEFMS_DB_PASS` (defaults: root / empty = XAMPP defaults), optional `CEFMS_DB_HOST`, `CEFMS_DB_PORT`, `CEFMS_DB_NAME`.
3. Start: `./run_server.sh` (or `php -S 127.0.0.1:8080 -t public`), or point Apache's document root at `public/`.
4. Public site http://127.0.0.1:8080/  -  Admin panel http://127.0.0.1:8080/admin/login.php

## Event Manager extras
- **Reports** (`/admin/reports.php`): filter events by date, status and category; totals, summaries by status/category/organizer, print view and CSV download.
- **Event Archive** (`/admin/archive.php`): move completed or cancelled past events out of the working list, and restore them. Archived events stay in reports.
- Already imported an older database? Run `sql/upgrade_archive.sql` once (adds the `archived` column), or import the new `sql/cefms_demo.sql`.

## Demo accounts
| Role | E-mail | Password |
|---|---|---|
| Event Manager | manager@cefms.my | Manager@123 |
| Organizer | siti@organizer.my / daniel@organizer.my | Organizer@123 |
| Event Participant | aisyah@mail.com / farid@mail.com | Aisyah@123 / Farid@123 |

## Tests
`python3 tests/run_tests.py` (needs the server running and the demo data; it re-seeds the database). 101 test cases; results in `tests/results.json`. Run history: `results_run0.json` (82/83), `results_run1.json` (84/86), `results_final.json` (86/86, before Reports and Archive were added); the latest run is `results.json` (101/101).
`php sql/seed.php` recreates the demo data. Errors are logged to `logs/app.log`.

## Layout
`public/` pages and assets | `public/admin/` admin pages | `includes/` bootstrap, helpers, services, layouts | `sql/` schema, seed, demo dump | `tests/` test suite | `docs/` screenshots, video and report builders
"# CEFMS" 
