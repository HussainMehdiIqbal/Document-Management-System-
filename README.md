# Document-Management-System-# DHA Document Management System (DHA DMS)

A PHP/MySQL web application for the Defence Housing Authority (DHA) to
digitize, index, rename, validate, and manage corporate and land records.
It replaces manual physical filing with a role-based digital workflow:
scan → standardized rename → validation → reporting.

## Tech Stack

- **Backend:** PHP 8.0+ (MySQLi)
- **Database:** MySQL 5.7+ / MariaDB 10.3+
- **Frontend:** Bootstrap 5, vanilla CSS/JS, Font Awesome
- **Analytics:** Chart.js
- **Hardware scanning:** NAPS2 + a local PowerShell scan agent (see
  `scan-agent/README.md`)

## Roles

| Role | ID | Access |
|---|---|---|
| Admin | 1 | Full access — manage users, view all reports/logs, everything below |
| Scanner (Operator) | 2 | Upload scanned files, enter initial metadata (`scan.php`, `folders.php`) |
| Validator | 3 | Review and Approve / Validate-after-Correction documents (`validate.php`) |
| Renamer | 4 | Standardize raw filenames into the tagged naming convention (`rename.php`) |

## Core Workflow

1. **Login** (`login.php`) — `password_verify()` against a bcrypt hash;
   role ID is loaded into the session and an activity log entry is written.
2. **Folder setup** (`pages/folders.php`) — create a logical folder mapped
   to a physical cabinet/collection (e.g. "DHA Lahore — Phase 1 Records").
3. **Scan & upload** (`pages/scan.php`) — raw PDFs/images are stored under
   `uploads/`; a `documents` row is created with status `pending`.
4. **Standardized rename** (`pages/rename.php`) — Branch, Doc Type, File
   Number, Phase, Plot, and Doc Year tags are combined into a clean name
   (e.g. `DHA-LHR-P1-CNIC-001.pdf`).
5. **Validation** (`pages/validate.php`) — Approve, Validate after
   Correction, or request revision; writes a `validations` record.
6. **Reporting** (`pages/reports.php`, `dashboard.php`) — scan volume,
   approval rates, and per-operator activity via Chart.js.

## Project Layout

```
dhadms_users/
├── index.php               # Entry point → redirects to login/dashboard
├── login.php / logout.php
├── dashboard.php            # Admin dashboard shell
├── user_dashboard.php       # Non-admin dashboard shell
├── pages/                   # Feature pages (scan, rename, validate, users, reports...)
├── admin/ajax/, ajax/       # AJAX endpoints (search, folder browse, stats, session check)
├── includes/                # config.php, auth.php, header/footer partials
├── database/                # dha_dms.sql (schema), migration script
├── assets/                  # CSS, JS, Bootstrap/Font Awesome/Inter vendor files
├── scan-agent/               # Local Windows scan agent (NAPS2 bridge) — see its own README
├── uploads/, scans/          # Stored documents and raw scans
└── project_details.html     # Full project documentation + viva Q&A
```

## Setup

1. **Database**
   - Create the schema by importing `database/dha_dms.sql` (this drops and
     recreates `dms_database`, so back up first if it already exists).
   - It seeds one account: **username `admin` / password `admin123`** —
     change this immediately after first login.
2. **Configuration** — edit `includes/config.php`:
   - `DB_HOST`, `DB_PORT` (defaults to `3307`), `DB_USER`, `DB_PASS`,
     `DB_NAME`
   - `UPLOAD_DIR` / `MAX_FILE_SIZE` (20MB) / `ALLOWED_TYPES` (PDF, JPEG,
     PNG, TIFF)
   - Scanner settings (`NAPS2_CONSOLE_PATH`, `SCANNER_DEVICE_NAME`,
     `SCAN_AGENT_PORT`) — only needed on machines doing live scanning
3. **Web server** — point your document root at this folder; requires PHP
   8.0+ with the `mysqli` extension enabled.
4. **Scanning hardware** — install the local scan agent from
   `scan-agent/` on each PC with a physical scanner attached (a browser
   can't talk to a TWAIN/WIA driver directly). Full instructions are in
   `scan-agent/README.md`.

## Security Notes

- Passwords are hashed with `password_hash()` (bcrypt) and checked with
  `password_verify()` — never stored in plain text.
- All pages enforce RBAC via `$_SESSION['user_role_id']` checks (e.g.
  `users.php` redirects any non-Admin away).
- Input is sanitized via a `clean()` helper (`trim()` + `strip_tags()`) and
  queries use prepared statements (`$conn->prepare()` / `bind_param()`).
- Every meaningful action (login, upload, rename, validation, folder
  changes) is written to the `activity_log` table via `logActivity()`.

## Further Reading

`project_details.html` in the project root is a full project-documentation
/ viva-preparation document — open it in a browser (or print to PDF) for
the complete database schema, architecture rationale, and a Q&A guide
covering common examiner questions about this system.
