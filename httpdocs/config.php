<?php
// ============================================================
//  config.php — Database & Application Configuration
//  For Plesk: update DB credentials directly below.
//  For Docker: set environment variables (env_file: .env).
// ============================================================

// ── Database ─────────────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME',    getenv('DB_NAME')    ?: 'nextgen_db');   // ← your Plesk DB name
define('DB_USER',    getenv('DB_USER')    ?: 'appuser');      // ← your Plesk DB username
define('DB_PASS',    getenv('DB_PASS')    ?: 'Str0ngP@ssw0rd!2026'); // ← your Plesk DB password
define('DB_CHARSET', 'utf8mb4');

// ── App ──────────────────────────────────────────────────────
define('APP_NAME',         'NextGen Technologies');
define('SESSION_LIFETIME', 28800);
define('CORS_ORIGIN',      '*');

// ── SMTP — updated by Settings panel ─────────────────────────
define('SMTP_HOST',      '');
define('SMTP_PORT',      587);
define('SMTP_SECURE',    'tls');
define('SMTP_USERNAME',  '');
define('SMTP_PASSWORD',  '');
define('SMTP_FROM',      '');
define('SMTP_FROM_NAME', 'NextGen Technologies');
