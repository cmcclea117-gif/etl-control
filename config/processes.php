<?php
// ── config/processes.php ──────────────────────────────────────────────────────
// Central registry of all ETL processes available in the control panel.
//
// To add a new process:
//   1. Write your ETL script (PowerShell, Python, SSIS, SQL Agent, etc.)
//   2. Add an entry here (copy an existing block as a template)
//   3. Visit /generate_wrapper.php?process=yourkey to download a pre-filled
//      Invoke-<Key>Remote.ps1 WinRM wrapper (production only)
//   4. Run Register-AllETLTasks.ps1 to register the scheduled task
//   5. The dashboard renders the new card automatically
//
// ── exec_type field ───────────────────────────────────────────────────────────
//   'powershell'  Direct PS script via Invoke-Command
//                 remote_script: full path to .ps1 on remote server
//
//   'python'      Python script via python.exe
//                 remote_script: full path to .py on remote server
//                 python_exe:    optional path to python (default: python.exe)
//
//   'ssis'        SSIS package via SSISDB catalog
//                 ssis_server, ssis_catalog, ssis_folder, ssis_project, ssis_package
//
//   'sqlagent'    SQL Server Agent job via Invoke-Sqlcmd
//                 agent_server: SQL Server instance
//                 agent_job:    exact job name
//
//   'cmd'         Any command-line executable or batch file
//                 remote_cmd: full command string to run on remote server
// ─────────────────────────────────────────────────────────────────────────────

$config       = require __DIR__ . '/app.php';
$remoteServer = $config['winrm_server'];
$scriptsRoot  = $config['scripts_root'];

$processes = [

    // ── Example 1: Hello World (PowerShell) ───────────────────────────────────
    'helloworld' => [
        'key'                  => 'helloworld',
        'name'                 => 'Hello World ETL',
        'description'          => 'Self-contained example: generates sample data, logs to ETL_Sync_Log',
        'log_process_name'     => 'Hello World ETL',
        'exec_type'            => 'powershell',
        'remote_server'        => $remoteServer,
        'remote_script'        => $scriptsRoot . '\etl-control\scripts\Invoke-HelloWorldETL.ps1',
        'local_script'         => __DIR__ . '/../scripts/Invoke-HelloWorldETL.ps1',
        'prod_args'            => '',
        'test_args'            => '-TestMode',
        'task_name'            => 'HelloWorldETL-OnDemand',
        'task_name_test'       => 'HelloWorldETL-OnDemand-Test',
        'expected_seconds'     => 15,
        'poll_timeout_seconds' => 60,
        'advanced'             => false,
        'trigger'              => true,
        'step_labels'          => [
            'Initializing',
            'Generating sample data',
            'Processing records',
            'Writing output',
            'Completing',
        ],
        'step_thresholds'      => [0, 3, 6, 10, 13],
        'reports'              => [],
        'docs'                 => [
            'what'     => 'A self-contained example ETL that demonstrates the full control panel integration. Generates sample data, simulates processing steps, and logs Started/Success/Failed to ETL_Sync_Log.',
            'when'     => 'Run manually to verify your ETL Control Panel setup is working end-to-end.',
            'schedule' => 'Not scheduled -- run on demand only.',
            'duration' => 'Typically 10-15 seconds.',
            'warnings' => 'This is a demo process. Remove or disable it in production once you have added your real processes.',
        ],
    ],

    // ── Example 2: CSV Import (PowerShell, file-based) ────────────────────────
    'csvimport' => [
        'key'                  => 'csvimport',
        'name'                 => 'CSV Import ETL',
        'description'          => 'Reads a CSV file, validates rows, logs record count',
        'log_process_name'     => 'CSV Import ETL',
        'exec_type'            => 'powershell',
        'remote_server'        => $remoteServer,
        'remote_script'        => $scriptsRoot . '\etl-control\scripts\Invoke-CsvImportETL.ps1',
        'local_script'         => __DIR__ . '/../scripts/Invoke-CsvImportETL.ps1',
        'prod_args'            => '',
        'test_args'            => '-TestMode',
        'task_name'            => 'CsvImportETL-OnDemand',
        'task_name_test'       => 'CsvImportETL-OnDemand-Test',
        'expected_seconds'     => 10,
        'poll_timeout_seconds' => 60,
        'advanced'             => false,
        'trigger'              => true,
        'step_labels'          => [
            'Locating source file',
            'Reading and validating CSV',
            'Processing records',
            'Completing',
        ],
        'step_thresholds'      => [0, 3, 5, 8],
        'reports'              => [],
        'docs'                 => [
            'what'     => 'Reads a CSV file from a configurable source path, validates rows, and logs a record count. Auto-generates a sample.csv for testing if no file exists.',
            'when'     => 'Run manually to test file-based ETL pipeline integration.',
            'schedule' => 'Not scheduled -- run on demand only.',
            'duration' => 'Typically 6-10 seconds.',
            'warnings' => 'This is a demo process. Replace the source path and processing logic with your real ETL.',
        ],
    ],

    // ── View Division Map Refresh ─────────────────────────────────────────────
    // Populates SQL_View_Division_Map for the Dependency Chain tab.
    // Local mode: seeds sample data via seed_viewmap.php
    // Production: runs sp_Refresh_ViewDivisionMap on SQL Server

    'refreshviewmap' => [
        'key'                  => 'refreshviewmap',
        'name'                 => 'Refresh View Map',
        'description'          => 'Scans databases for SQL views, populates Dependency Chain',
        'log_process_name'     => 'Refresh View Division Map',
        'exec_type'            => 'powershell',
        'remote_server'        => $remoteServer,
        'remote_script'        => $scriptsRoot . '\etl-control\scripts\Invoke-RefreshViewMapETL.ps1',
        'local_script'         => __DIR__ . '/../scripts/Invoke-RefreshViewMapETL.ps1',
        'prod_args'            => '',
        'test_args'            => '-TestMode',
        'task_name'            => 'RefreshViewMap-OnDemand',
        'expected_seconds'     => 15,
        'poll_timeout_seconds' => 120,
        'advanced'             => false,
        'trigger'              => true,
        'step_labels'          => [
            'Initializing',
            'Scanning databases for views',
            'Completing',
        ],
        'step_thresholds'      => [0, 3, 12],
        'reports'              => [],
        'docs'                 => [
            'what'     => 'Scans all configured databases for SQL views and populates the SQL_View_Division_Map table used by the Dependency Chain tab. In local mode, seeds sample data. In production, runs sp_Refresh_ViewDivisionMap on SQL Server.',
            'when'     => 'Run manually after adding new SQL views, or when the Dependency Chain tab shows stale data.',
            'schedule' => 'Daily after the PBIX scanner -- typically 4:30 AM.',
            'duration' => 'Typically 10-30 seconds in local mode. Production varies by number of databases.',
            'warnings' => null,
        ],
    ],

    // ── Example 3: Hello World Python ────────────────────────────────────────
    'helloworldpy' => [
        'key'                  => 'helloworldpy',
        'name'                 => 'Hello World Python ETL',
        'description'          => 'Python example: generates sample data, logs to ETL_Sync_Log',
        'log_process_name'     => 'Hello World Python ETL',
        'exec_type'            => 'python',
        'remote_server'        => $remoteServer,
        'remote_script'        => $scriptsRoot . '\etl-control\scripts\invoke_hello_world_py.py',
        'python_exe'           => 'python.exe',
        'local_script'         => __DIR__ . '/../scripts/invoke_hello_world_py.py',
        'prod_args'            => '',
        'test_args'            => '--test-mode',
        'task_name'            => 'HelloWorldPyETL-OnDemand',
        'expected_seconds'     => 15,
        'poll_timeout_seconds' => 60,
        'advanced'             => false,
        'trigger'              => true,
        'step_labels'          => [
            'Initializing',
            'Generating sample data',
            'Processing records',
            'Writing output',
            'Completing',
        ],
        'step_thresholds'      => [0, 3, 6, 10, 13],
        'reports'              => [],
        'docs'                 => [
            'what'     => 'A self-contained Python ETL example demonstrating the full control panel integration. Generates sample data and logs Started/Success/Failed to ETL_Sync_Log via HTTP.',
            'when'     => 'Run manually to verify Python ETL integration is working.',
            'schedule' => 'Not scheduled -- run on demand only.',
            'duration' => 'Typically 10-15 seconds.',
            'warnings' => 'Requires Python 3.x and the requests library (pip install requests).',
        ],
    ],

    // ── Example 3: Hello World R ──────────────────────────────────────────────
    'helloworldr' => [
        'key'                  => 'helloworldr',
        'name'                 => 'Hello World R ETL',
        'description'          => 'R example: generates sample data, logs to ETL_Sync_Log',
        'log_process_name'     => 'Hello World R ETL',
        'exec_type'            => 'r',
        'remote_server'        => $remoteServer,
        'remote_script'        => $scriptsRoot . '\\etl-control\\scripts\\invoke_hello_world_r.R',
        'r_exe'                => 'Rscript.exe',
        'local_script'         => __DIR__ . '/../scripts/invoke_hello_world_r.R',
        'prod_args'            => '',
        'test_args'            => '--test-mode',
        'task_name'            => 'HelloWorldRETL-OnDemand',
        'expected_seconds'     => 15,
        'poll_timeout_seconds' => 60,
        'advanced'             => false,
        'trigger'              => true,
        'step_labels'          => ['Initializing','Generating sample data','Processing records','Writing output','Completing'],
        'step_thresholds'      => [0, 3, 6, 10, 13],
        'reports'              => [],
        'docs'                 => [
            'what'     => 'A self-contained R ETL example. Generates sample data and logs Started/Success/Failed via HTTP.',
            'when'     => 'Run manually to verify R ETL integration is working.',
            'schedule' => 'Not scheduled -- run on demand only.',
            'duration' => 'Typically 10-15 seconds.',
            'warnings' => 'Requires R and the httr package: install.packages("httr")',
        ],
    ],

    // ── Example 4: Hello World Node.js ───────────────────────────────────────
    'helloworldnode' => [
        'key'                  => 'helloworldnode',
        'name'                 => 'Hello World Node ETL',
        'description'          => 'Node.js example: generates sample data, logs to ETL_Sync_Log',
        'log_process_name'     => 'Hello World Node ETL',
        'exec_type'            => 'node',
        'remote_server'        => $remoteServer,
        'remote_script'        => $scriptsRoot . '\\etl-control\\scripts\\invoke_hello_world_node.js',
        'node_exe'             => 'node.exe',
        'local_script'         => __DIR__ . '/../scripts/invoke_hello_world_node.js',
        'prod_args'            => '',
        'test_args'            => '--test-mode',
        'task_name'            => 'HelloWorldNodeETL-OnDemand',
        'expected_seconds'     => 15,
        'poll_timeout_seconds' => 60,
        'advanced'             => false,
        'trigger'              => true,
        'step_labels'          => ['Initializing','Generating sample data','Processing records','Writing output','Completing'],
        'step_thresholds'      => [0, 3, 6, 10, 13],
        'reports'              => [],
        'docs'                 => [
            'what'     => 'A self-contained Node.js ETL example. Uses only built-in modules for HTTP logging -- no npm packages needed.',
            'when'     => 'Run manually to verify Node.js ETL integration is working.',
            'schedule' => 'Not scheduled -- run on demand only.',
            'duration' => 'Typically 10-15 seconds.',
            'warnings' => 'Requires Node.js installed and node.exe in PATH.',
        ],
    ],

    // ── Add your real processes below ─────────────────────────────────────────
    //
    // PowerShell:
    // 'myprocess' => [
    //     'key'              => 'myprocess',
    //     'name'             => 'My ETL Process',
    //     'description'      => 'Short description shown on the panel',
    //     'log_process_name' => 'My ETL Process',   // must match what the script logs
    //     'exec_type'        => 'powershell',
    //     'remote_server'    => $remoteServer,
    //     'remote_script'    => $scriptsRoot . '\my-scripts\Invoke-MyProcess.ps1',
    //     'local_script'     => __DIR__ . '/../scripts/Invoke-MyProcess.ps1',
    //     'prod_args'        => '',
    //     'test_args'        => '-TestMode',
    //     'task_name'        => 'MyProcess-OnDemand',
    //     'expected_seconds' => 60,
    //     'poll_timeout_seconds' => 300,
    //     'advanced'         => false,
    //     'trigger'          => true,
    //     'step_labels'      => ['Connecting', 'Extracting', 'Loading', 'Completing'],
    //     'step_thresholds'  => [0, 15, 40, 55],
    //     'reports'          => [],
    //     'docs'             => ['what' => '...', 'schedule' => '...', 'duration' => '...'],
    // ],
    //
    // Python:
    // 'mypython' => [
    //     'exec_type'     => 'python',
    //     'remote_script' => $scriptsRoot . '\my-scripts\my_etl.py',
    //     'python_exe'    => 'C:\Python312\python.exe',
    //     ...
    // ],
    //
    // SSIS Package:
    // 'myssis' => [
    //     'exec_type'    => 'ssis',
    //     'ssis_server'  => $remoteServer,
    //     'ssis_catalog' => 'SSISDB',
    //     'ssis_folder'  => 'MyFolder',
    //     'ssis_project' => 'MyProject',
    //     'ssis_package' => 'MyPackage.dtsx',
    //     ...
    // ],
    //
    // SQL Agent Job:
    // 'myagentjob' => [
    //     'exec_type'    => 'sqlagent',
    //     'agent_server' => $remoteServer,
    //     'agent_job'    => 'My Nightly Sync Job',
    //     ...
    // ],
    //
    // Any command/executable:
    // 'mycmd' => [
    //     'exec_type'  => 'cmd',
    //     'remote_cmd' => 'C:\Tools\myetl.exe --config C:\Config\prod.json',
    //     ...
    // ],

];

// ── Merge DB-stored processes ─────────────────────────────────────────────────
// User-added processes are stored in ETL_Processes table and merged here.
// DB processes take precedence over hardcoded ones with the same key.
try {
    require_once __DIR__ . '/../includes/db.php';
    $conn = getDbConnection();
    $rows = $conn->query('SELECT * FROM ETL_Processes ORDER BY created_at ASC')->fetchAll();
    foreach ($rows as $row) {
        $key = $row['process_key'];
        $stepLabels     = $row['step_labels']     ? json_decode($row['step_labels'],     true) : ['Initializing', 'Processing', 'Completing'];
        $stepThresholds = $row['step_thresholds'] ? json_decode($row['step_thresholds'], true) : [0, 10, 50];
        $processes[$key] = [
            'key'                  => $key,
            'name'                 => $row['name'],
            'description'          => $row['description'] ?? '',
            'log_process_name'     => $row['log_process_name'],
            'exec_type'            => $row['exec_type'] ?? 'powershell',
            'remote_server'        => $row['remote_server'] ?? $remoteServer,
            'remote_script'        => $row['remote_script'] ?? '',
            'python_exe'           => $row['python_exe'] ?? 'python.exe',
            'ssis_server'          => $row['ssis_server'] ?? $remoteServer,
            'ssis_catalog'         => $row['ssis_catalog'] ?? 'SSISDB',
            'ssis_folder'          => $row['ssis_folder'] ?? '',
            'ssis_project'         => $row['ssis_project'] ?? '',
            'ssis_package'         => $row['ssis_package'] ?? '',
            'agent_server'         => $row['agent_server'] ?? $remoteServer,
            'agent_job'            => $row['agent_job'] ?? '',
            'remote_cmd'           => $row['remote_cmd'] ?? '',
            'local_script'         => $row['local_script'] ?? '',
            'prod_args'            => $row['prod_args'] ?? '',
            'test_args'            => $row['test_args'] ?? '',
            'task_name'            => $row['task_name'] ?? $key . '-OnDemand',
            'expected_seconds'     => (int)($row['expected_seconds'] ?? 60),
            'poll_timeout_seconds' => (int)($row['poll_timeout_secs'] ?? 300),
            'advanced'             => (bool)($row['advanced'] ?? false),
            'trigger'              => true,
            'step_labels'          => $stepLabels,
            'step_thresholds'      => $stepThresholds,
            'reports'              => $row['report_url'] ? [['name' => $row['report_name'] ?? 'Report', 'url' => $row['report_url']]] : [],
            'docs'                 => [
                'what'     => $row['doc_what']     ?? '',
                'schedule' => $row['doc_schedule'] ?? '',
                'duration' => $row['doc_duration'] ?? '',
                'when'     => $row['doc_when']     ?? '',
                'warnings' => $row['doc_warnings'] ?? null,
            ],
            '_from_db'             => true,  // flag so UI knows it can be deleted
        ];
    }
} catch (Exception $e) {
    // Silently fall back to hardcoded only -- dashboard still works
}

return $processes;