<?php
// ── includes/db.php ───────────────────────────────────────────────────────────
// Shared SQL Server connection. Reads server + database from config/app.php
// and credentials from config/credentials.php.
// ─────────────────────────────────────────────────────────────────────────────

function getDbConnection(): mixed {
    $config = require __DIR__ . '/../config/app.php';
    $creds  = require __DIR__ . '/../config/credentials.php';

    $conn = sqlsrv_connect($config['sql_server'], [
        'Database'               => $config['database'],
        'TrustServerCertificate' => true,
        'CharacterSet'           => 'UTF-8',
        'UID'                    => $creds['db_user'],
        'PWD'                    => $creds['db_pass'],
    ]);

    if (!$conn) {
        throw new RuntimeException('DB connection failed: ' . json_encode(sqlsrv_errors()));
    }

    return $conn;
}
