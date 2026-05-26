<?php
// ── includes/db.php ───────────────────────────────────────────────────────────
// Universal database connection — returns a PDO instance.
// Supports two drivers configured in config/app.php:
//
//   'sqlite'  — uses a local .db file (default for SDK / local mode)
//               No SQL Server needed. Ships with a pre-built etl_control.db.
//
//   'sqlsrv'  — connects to SQL Server (production)
//               Requires the sqlsrv PHP extension and config/credentials.php.
//
// All PHP files use getDbConnection() and standard PDO — no sqlsrv_* calls.
// ─────────────────────────────────────────────────────────────────────────────

function getDbConnection(): PDO {
    $config = require __DIR__ . '/../config/app.php';
    $driver = $config['db_driver'] ?? 'sqlite';

    if ($driver === 'sqlite') {
        $dbPath = __DIR__ . '/../data/etl_control.db';
        $dir    = dirname($dbPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');   // safe for concurrent reads
        $pdo->exec('PRAGMA foreign_keys=ON');

        // Auto-create schema on first run if tables don't exist
        _ensureSqliteSchema($pdo);

        return $pdo;
    }

    if ($driver === 'sqlsrv') {
        $creds  = require __DIR__ . '/../config/credentials.php';
        $server = $config['sql_server'];
        $db     = $config['database'];
        $dsn    = "sqlsrv:Server=$server;Database=$db;TrustServerCertificate=1";
        $pdo    = new PDO($dsn, $creds['db_user'], $creds['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    throw new RuntimeException("Unknown db_driver: $driver. Use 'sqlite' or 'sqlsrv'.");
}

// ── SQLite schema bootstrap ───────────────────────────────────────────────────
// Creates tables on first run so the user doesn't need to run any SQL manually
// when using SQLite mode. Safe to call on every request — uses IF NOT EXISTS.
function _ensureSqliteSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ETL_Sync_Log (
            Log_ID        INTEGER PRIMARY KEY AUTOINCREMENT,
            Process_Name  TEXT    NOT NULL,
            Record_Count  INTEGER,
            Sync_Date     TEXT    NOT NULL DEFAULT (datetime('now')),
            Status        TEXT    NOT NULL,
            Error_Message TEXT,
            Start_Time    TEXT,
            End_Time      TEXT,
            Updated_Rows  INTEGER,
            Inserted_Rows INTEGER
        );

        CREATE TABLE IF NOT EXISTS ETL_Process_Docs (
            process_key  TEXT PRIMARY KEY,
            what         TEXT,
            schedule     TEXT,
            duration     TEXT,
            when_to_run  TEXT,
            warnings     TEXT,
            updated_by   TEXT,
            updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS PBI_Connection_Map (
            MapID           INTEGER PRIMARY KEY AUTOINCREMENT,
            Report_File     TEXT,
            SharePoint_Path TEXT,
            SharePoint_Site TEXT,
            Server          TEXT,
            Database_Name   TEXT,
            View_Or_Table   TEXT,
            Query_Text      TEXT,
            Source_Type     TEXT,
            Last_Scanned    TEXT DEFAULT (datetime('now')),
            Schema_Name     TEXT,
            Import_Mode     TEXT,
            Report_URL      TEXT
        );

        CREATE TABLE IF NOT EXISTS SQL_View_Division_Map (
            MapID            INTEGER PRIMARY KEY AUTOINCREMENT,
            FoundInDatabase  TEXT,
            View_Schema      TEXT,
            View_Name        TEXT,
            Division_DB      TEXT,
            Approx_LineCount INTEGER,
            Last_Refreshed   TEXT DEFAULT (datetime('now'))
        );
    ");

    // Seed Hello World docs if not already present
    $exists = $pdo->query("SELECT COUNT(*) FROM ETL_Process_Docs WHERE process_key = 'helloworld'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("INSERT INTO ETL_Process_Docs
            (process_key, what, schedule, duration, when_to_run, warnings, updated_by)
            VALUES (
                'helloworld',
                'A self-contained example ETL that demonstrates the full control panel integration. Generates sample data and logs Started/Success/Failed to ETL_Sync_Log.',
                'Not scheduled — run on demand only.',
                'Typically 10-15 seconds.',
                'Run manually to verify your ETL Control Panel installation is working end-to-end.',
                'This is a demo process. Remove or disable it in production once you have added your real processes.',
                'setup'
            )");
    }
}
