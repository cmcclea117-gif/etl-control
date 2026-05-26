<?php
// ── trigger.php ───────────────────────────────────────────────────────────────
// Async trigger endpoint. Fires the appropriate scheduled task (production)
// or runs the script directly (local mode) for any registered ETL process.
//
// POST params:
//   process  (key)        — required, must exist in config/processes.php
//   mode     'prod'|'test' — defaults to 'prod'
// ─────────────────────────────────────────────────────────────────────────────

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

// ── Local mode: run script directly ──────────────────────────────────────────
if (($config['mode'] ?? 'production') === 'local') {
    $localScript = $proc['local_script'] ?? null;
    if (!$localScript) {
        http_response_code(500);
        echo json_encode(['error' => "No local_script defined for process: $processKey. Add it to config/processes.php."]);
        exit;
    }

    $cmd = 'powershell.exe -NonInteractive -NoProfile -ExecutionPolicy Bypass -File "' . $localScript . '"';
    if ($extraArgs) $cmd .= ' ' . $extraArgs;

    // Fire and forget — log polling handles completion detection
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B cmd /C "' . $cmd . '"', 'r'));
    } else {
        // Linux/Mac (for dev without PS — will fail gracefully in log polling)
        exec($cmd . ' > /dev/null 2>&1 &');
    }

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
$taskFolder = rtrim($config['task_folder'], '\\');
$taskName   = $proc['task_name'] ?? null;
$taskSuffix = ($mode === 'test' && !empty($proc['task_name_test']))
    ? $proc['task_name_test']
    : ($taskName . '-OnDemand');

$fullTaskName = $taskFolder . '\\' . $taskSuffix;

if (!$taskName) {
    http_response_code(500);
    echo json_encode(['error' => "No task_name defined for process: $processKey. Add it to config/processes.php."]);
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
