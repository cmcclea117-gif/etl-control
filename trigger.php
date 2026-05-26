<?php
// ── trigger.php ───────────────────────────────────────────────────────────────
header('Content-Type: application/json');
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$auth       = requireAuth();
$config     = require __DIR__ . '/config/app.php';
$processes  = require __DIR__ . '/config/processes.php';
$processKey = trim($_POST['process'] ?? '');
$mode       = trim($_POST['mode'] ?? 'prod');

if (empty($processKey) || !isset($processes[$processKey])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown process: ' . htmlspecialchars($processKey)]);
    exit;
}

$proc      = $processes[$processKey];
$extraArgs = $mode === 'test' ? ($proc['test_args'] ?? '') : ($proc['prod_args'] ?? '');

// ── Local mode: run PowerShell script directly ────────────────────────────────
if (($config['mode'] ?? 'production') === 'local') {
    $localScript = $proc['local_script'] ?? null;
    if (!$localScript) {
        http_response_code(500);
        echo json_encode(['error' => "No local_script defined for: $processKey"]);
        exit;
    }

    $localScript = str_replace('/', DIRECTORY_SEPARATOR, $localScript);

    if (!file_exists($localScript)) {
        http_response_code(500);
        echo json_encode(['error' => "Script not found: $localScript"]);
        exit;
    }

    // Write Started entry to SQLite directly from PHP
    require_once __DIR__ . '/includes/db.php';
    try {
        $db = getDbConnection();
        $db->prepare(
            "INSERT INTO ETL_Sync_Log (Process_Name, Status, Record_Count, Error_Message, Start_Time, End_Time, Sync_Date)
             VALUES (?, 'Started', NULL, NULL, ?, ?, ?)"
        )->execute([
            $proc['log_process_name'],
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) { /* non-fatal */ }

    // Log endpoint URL for the PS script to POST completion back to
    $port    = $_SERVER['SERVER_PORT'] ?? '8080';
    $logUrl  = 'http://localhost:' . $port . '/log.php';
    $logName = $proc['log_process_name'];

    // Write a temp launcher script to avoid quoting nightmares
    $launcherPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'etl_launch_' . $processKey . '.ps1';
    $launcherContent  = "& '" . str_replace("'", "''", $localScript) . "'";
    if ($extraArgs) $launcherContent .= " $extraArgs";
    $launcherContent .= " -LogUrl '" . $logUrl . "'";
    $launcherContent .= " -LogProcessName '" . str_replace("'", "''", $logName) . "'";
    file_put_contents($launcherPath, $launcherContent);

    // Launch via Start-Process — fully detached, hidden window
    $cmd = 'powershell.exe -NonInteractive -NoProfile -ExecutionPolicy Bypass'
         . ' -Command "Start-Process powershell.exe'
         . ' -ArgumentList \'-NonInteractive -NoProfile -ExecutionPolicy Bypass -File """"' . $launcherPath . '""""\'  '
         . ' -WindowStyle Hidden"';
    shell_exec($cmd);

    echo json_encode([
        'ok'           => true,
        'process'      => $processKey,
        'mode'         => $mode,
        'method'       => 'local',
        'script'       => $localScript,
        'triggered_at' => date('c'),
        'triggered_by' => $auth['full'],
    ]);
    exit;
}

// ── Production mode: fire via Windows Task Scheduler ─────────────────────────
$taskFolder   = rtrim($config['task_folder'], '\\');
$taskName     = $proc['task_name'] ?? null;
$taskSuffix   = ($mode === 'test' && !empty($proc['task_name_test']))
    ? $proc['task_name_test']
    : ($taskName . '-OnDemand');
$fullTaskName = $taskFolder . '\\' . $taskSuffix;

if (!$taskName) {
    http_response_code(500);
    echo json_encode(['error' => "No task_name defined for: $processKey"]);
    exit;
}

$output = shell_exec('schtasks /Run /TN "' . $fullTaskName . '" 2>&1');

echo json_encode([
    'ok'           => true,
    'process'      => $processKey,
    'mode'         => $mode,
    'method'       => 'schtasks',
    'task'         => $fullTaskName,
    'schtasks_out' => trim($output),
    'triggered_at' => date('c'),
    'triggered_by' => $auth['full'],
]);
