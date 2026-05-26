<?php
// ── config/credentials.php ────────────────────────────────────────────────────
// Copy this file to config/credentials.php and fill in your values.
// config/credentials.php is gitignored — never commit it.
//
// These credentials are used by the PHP app to connect to SQL Server.
// In production this is a low-privilege read/write account (not sa).
// In local mode this can be a dev account or use Windows auth (leave blank).
// ─────────────────────────────────────────────────────────────────────────────

return [
    'db_user' => 'your_db_user',      // SQL Server login name
    'db_pass' => 'your_db_password',  // SQL Server login password
];
