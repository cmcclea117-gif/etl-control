<?php
// ── includes/db.php ───────────────────────────────────────────────────────────
// ETL Control Panel database connection.
//
// The app ALWAYS uses SQLite for its own data:
//   ETL_Sync_Log, PBI_Connection_Map, SQL_View_Division_Map,
//   ETL_Process_Docs, ETL_Processes
//
// ETL scripts connect to whatever source/target they need (SQL Server,
// MySQL, PostgreSQL, APIs, etc) — that is the script's concern, not the app's.
//
// The SQLite database is auto-created at data/etl_control.db on first run.
// ─────────────────────────────────────────────────────────────────────────────

function getDbConnection(): PDO {
    $dbPath = __DIR__ . '/../data/etl_control.db';
    $dir    = dirname($dbPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    _ensureSchema($pdo);
    return $pdo;
}

function _ensureSchema(PDO $pdo): void {
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

        CREATE TABLE IF NOT EXISTS ETL_Processes (
            process_key        TEXT PRIMARY KEY,
            name               TEXT NOT NULL,
            description        TEXT,
            log_process_name   TEXT NOT NULL,
            exec_type          TEXT NOT NULL DEFAULT 'powershell',
            remote_server      TEXT,
            remote_script      TEXT,
            python_exe         TEXT,
            ssis_server        TEXT,
            ssis_catalog       TEXT,
            ssis_folder        TEXT,
            ssis_project       TEXT,
            ssis_package       TEXT,
            agent_server       TEXT,
            agent_job          TEXT,
            remote_cmd         TEXT,
            local_script       TEXT,
            prod_args          TEXT,
            test_args          TEXT,
            task_name          TEXT,
            expected_seconds   INTEGER DEFAULT 60,
            poll_timeout_secs  INTEGER DEFAULT 300,
            advanced           INTEGER DEFAULT 0,
            step_labels        TEXT,
            step_thresholds    TEXT,
            report_name        TEXT,
            report_url         TEXT,
            doc_what           TEXT,
            doc_schedule       TEXT,
            doc_duration       TEXT,
            doc_when           TEXT,
            doc_warnings       TEXT,
            created_at         TEXT NOT NULL DEFAULT (datetime('now')),

            -- Source connection
            source_type        TEXT,
            source_host        TEXT,
            source_port        TEXT,
            source_database    TEXT,
            source_username    TEXT,
            source_schema      TEXT,
            source_query       TEXT,

            -- Destination connection
            dest_type          TEXT,
            dest_host          TEXT,
            dest_port          TEXT,
            dest_database      TEXT,
            dest_username      TEXT,
            dest_schema        TEXT,
            dest_table         TEXT,

            -- Generated script path (auto-set by generate_script.php)
            generated_script   TEXT
        );

        -- Add new columns to existing ETL_Processes tables (safe on upgrade)
        CREATE TABLE IF NOT EXISTS _migration_check (id INTEGER PRIMARY KEY);
    ");

    // Run safe migrations for existing databases
    $cols = array_column($pdo->query('PRAGMA table_info(ETL_Processes)')->fetchAll(), 'name');
    $newCols = [
        'source_type'     => 'TEXT',
        'source_host'     => 'TEXT',
        'source_port'     => 'TEXT',
        'source_database' => 'TEXT',
        'source_username' => 'TEXT',
        'source_schema'   => 'TEXT',
        'source_query'    => 'TEXT',
        'dest_type'       => 'TEXT',
        'dest_host'       => 'TEXT',
        'dest_port'       => 'TEXT',
        'dest_database'   => 'TEXT',
        'dest_username'   => 'TEXT',
        'dest_schema'     => 'TEXT',
        'dest_table'      => 'TEXT',
        'generated_script'=> 'TEXT',
    ];
    foreach ($newCols as $col => $type) {
        if (!in_array($col, $cols)) {
            $pdo->exec("ALTER TABLE ETL_Processes ADD COLUMN $col $type");
        }
    }

    // Seed Hello World docs if not already present
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM ETL_Process_Docs WHERE process_key = 'helloworld'"
    )->fetchColumn();

    if (!$exists) {
        $pdo->exec("INSERT INTO ETL_Process_Docs
            (process_key, what, schedule, duration, when_to_run, warnings, updated_by)
            VALUES (
                'helloworld',
                'A self-contained example ETL that demonstrates the full control panel integration.',
                'Not scheduled -- run on demand only.',
                'Typically 10-15 seconds.',
                'Run manually to verify your ETL Control Panel installation is working end-to-end.',
                'This is a demo process. Remove or disable it in production once you have added your real processes.',
                'setup'
            )");
    }
}
