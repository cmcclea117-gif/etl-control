<?php
// ── config/processes.php ──────────────────────────────────────────────────────
// Central registry of all ETL processes available in the control panel.
//
// To add a new process:
//   1. Add an entry to this array (copy 'helloworld' as a template)
//   2. Visit /generate_wrapper.php?process=yourkey → downloads Invoke-YourRemote.ps1
//   3. Deploy the wrapper to your web server
//   4. Run Register-AllETLTasks.ps1 to register the scheduled task
//   5. Done — the dashboard renders the new card automatically
//
// Key fields:
//   task_name          Windows Task Scheduler task name (without folder prefix)
//   task_name_test     Optional separate task name for Test mode
//   remote_server      WinRM target server (production)
//   remote_script      Full path to ETL script on remote server (production)
//   local_script       Full path to ETL script for local mode
//   trigger            false = auto-run only (shows ⟳ Auto badge, no Run button)
//   advanced           true = hidden behind the 5-click easter egg
//   poll_timeout_seconds  how long UI polls before timeout warning
// ─────────────────────────────────────────────────────────────────────────────

$config       = require __DIR__ . '/app.php';
$remoteServer = $config['winrm_server'];
$scriptsRoot  = $config['scripts_root'];

return [

    // ── Example process (ships with the SDK — remove or keep as a smoke test) ─

    'helloworld' => [
        'key'                  => 'helloworld',
        'name'                 => 'Hello World ETL',
        'description'          => 'Self-contained example: generates sample data, logs to ETL_Sync_Log',
        'log_process_name'     => 'Hello World ETL',
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
            'what'     => 'A self-contained example ETL that demonstrates the full control panel integration. Generates sample data, simulates processing steps, and logs Started/Success/Failed to ETL_Sync_Log. Use this as a template when building new processes.',
            'when'     => 'Run manually to verify your ETL Control Panel setup is working end-to-end.',
            'schedule' => 'Not scheduled — run on demand only.',
            'duration' => 'Typically 10-15 seconds.',
            'warnings' => 'This is a demo process. Remove or disable it in production once you have added your real processes.',
        ],
    ],

    // ── Add your real processes below ─────────────────────────────────────────
    // Copy the helloworld block above, change the key, name, and script paths.
    //
    // Example:
    //
    // 'myprocess' => [
    //     'key'              => 'myprocess',
    //     'name'             => 'My ETL Process',
    //     'description'      => 'Short description shown on the panel',
    //     'log_process_name' => 'My ETL Process',   ← must match what the script logs
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
    //     'step_labels'      => ['Step 1', 'Step 2', 'Step 3'],
    //     'step_thresholds'  => [0, 20, 45],
    //     'reports'          => [
    //         ['name' => 'My Report', 'url' => 'https://app.powerbi.com/...'],
    //     ],
    //     'docs'             => [
    //         'what'     => 'What this process does.',
    //         'when'     => 'When to run it manually.',
    //         'schedule' => 'Daily at 6:00 AM.',
    //         'duration' => 'Typically 45-60 seconds.',
    //         'warnings' => null,
    //     ],
    // ],

];
