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
$execType  = $proc['exec_type'] ?? 'powershell';

// ── Local mode ────────────────────────────────────────────────────────────────
if (($config['mode'] ?? 'production') === 'local') {
    $localScript = $proc['local_script'] ?? null;

    // Auto-assign built-in local scripts for exec types that have them
    if (!$localScript) {
        $builtinScripts = [
            'ssis'      => __DIR__ . '/scripts/Invoke-SsisLocal.ps1',
            'sqlagent'  => __DIR__ . '/scripts/Invoke-SqlAgentLocal.ps1',
        ];
        $localScript = $builtinScripts[$execType] ?? null;
    }

    if (!$localScript) {
        http_response_code(500);
        echo json_encode(['error' => "No local_script defined for: $processKey. Add a local_script path in config/processes.php."]);
        exit;
    }

    $localScript = str_replace('/', DIRECTORY_SEPARATOR, $localScript);

    if (!file_exists($localScript)) {
        http_response_code(500);
        echo json_encode(['error' => "Script not found: $localScript"]);
        exit;
    }

    // Write Started entry to SQLite
    require_once __DIR__ . '/includes/db.php';
    try {
        $db = getDbConnection();
        $db->prepare(
            "INSERT INTO ETL_Sync_Log
                (Process_Name, Status, Record_Count, Error_Message, Start_Time, End_Time, Sync_Date)
             VALUES (?, 'Started', NULL, NULL, ?, ?, ?)"
        )->execute([
            $proc['log_process_name'],
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) { /* non-fatal */ }

    $port    = $_SERVER['SERVER_PORT'] ?? '8080';
    $logUrl  = 'http://localhost:' . $port . '/log.php';
    $logName = $proc['log_process_name'];

    // Write launcher file
    $tmpDir = sys_get_temp_dir();

    if (in_array($execType, ['python', 'r', 'node', 'ssis', 'sqlagent'])) {
        $exe = match($execType) {
            'python'   => $proc['python_exe'] ?? 'python.exe',
            'r'        => $proc['r_exe']      ?? 'Rscript.exe',
            'node'     => $proc['node_exe']   ?? 'node.exe',
            'ssis',
            'sqlagent' => 'powershell.exe',
            default    => 'powershell.exe',
        };
        $launcherPath = $tmpDir . DIRECTORY_SEPARATOR . 'etl_launch_' . $processKey . '.bat';
        $lines = ['@echo off'];
        if (in_array($execType, ['ssis', 'sqlagent'])) {
            // PS scripts use -Parameter syntax
            $line = 'powershell.exe -NonInteractive -NoProfile -ExecutionPolicy Bypass -File "' . $localScript . '"';
            if ($extraArgs) $line .= ' ' . $extraArgs;
            $line .= ' -LogUrl "' . $logUrl . '"';
            $line .= ' -LogProcessName "' . str_replace('"', '\"', $logName) . '"';
            // Pass connection params from process config
            if ($execType === 'ssis') {
                $line .= ' -SsisServer "' . ($proc['ssis_server'] ?? '') . '"';
                $line .= ' -SsisCatalog "' . ($proc['ssis_catalog'] ?? 'SSISDB') . '"';
                $line .= ' -SsisFolder "' . ($proc['ssis_folder'] ?? '') . '"';
                $line .= ' -SsisProject "' . ($proc['ssis_project'] ?? '') . '"';
                $line .= ' -SsisPackage "' . ($proc['ssis_package'] ?? '') . '"';
            } elseif ($execType === 'sqlagent') {
                $line .= ' -AgentServer "' . ($proc['agent_server'] ?? '') . '"';
                $line .= ' -AgentJob "' . str_replace('"', '\"', ($proc['agent_job'] ?? '')) . '"';
            }
        } else {
            $line  = '"' . $exe . '" "' . $localScript . '"';
            if ($extraArgs) $line .= ' ' . $extraArgs;
            $line .= ' --log-url "' . $logUrl . '"';
            $line .= ' --log-process-name "' . str_replace('"', '\"', $logName) . '"';
        }
        $lines[] = $line;
        file_put_contents($launcherPath, implode("\r\n", $lines));

        $cmd = 'powershell.exe -NonInteractive -NoProfile -ExecutionPolicy Bypass'
             . ' -Command "Start-Process cmd.exe -ArgumentList \'/c \\"' . $launcherPath . '\\"\' -WindowStyle Hidden"';
    } else {
        $launcherPath = $tmpDir . DIRECTORY_SEPARATOR . 'etl_launch_' . $processKey . '.ps1';
        $content  = "& '" . str_replace("'", "''", $localScript) . "'";
        if ($extraArgs) $content .= ' ' . $extraArgs;
        $content .= " -LogUrl '" . $logUrl . "'";
        $content .= " -LogProcessName '" . str_replace("'", "''", $logName) . "'";
        file_put_contents($launcherPath, $content);

        $cmd = 'powershell.exe -NonInteractive -NoProfile -ExecutionPolicy Bypass'
             . ' -Command "Start-Process powershell.exe'
             . ' -ArgumentList \'-NonInteractive -NoProfile -ExecutionPolicy Bypass -File \\"' . $launcherPath . '\\"\''
             . ' -WindowStyle Hidden"';
    }

    shell_exec($cmd);

    echo json_encode([
        'ok'           => true,
        'process'      => $processKey,
        'mode'         => $mode,
        'method'       => 'local',
        'exec_type'    => $execType,
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