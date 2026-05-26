<?php
// ── save_process.php ──────────────────────────────────────────────────────────
// Saves a new or updated ETL process to the ETL_Processes table.
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$p = $_POST;

// ── Validate required fields ──────────────────────────────────────────────────
$key     = trim(preg_replace('/[^a-z0-9_]/', '', strtolower($p['process_key'] ?? '')));
$name    = trim($p['name']             ?? '');
$logName = trim($p['log_process_name'] ?? '');
$execType = trim($p['exec_type']       ?? 'powershell');

if (!$key || !$name || !$logName) {
    http_response_code(400);
    echo json_encode(['error' => 'Process key, name, and log process name are required']);
    exit;
}

// ── Parse step labels and auto-calculate thresholds ───────────────────────────
$stepLabelsRaw  = trim($p['step_labels'] ?? '');
$stepLabels     = array_values(array_filter(array_map('trim', explode("\n", $stepLabelsRaw))));
if (empty($stepLabels)) $stepLabels = ['Initializing', 'Processing', 'Completing'];

$stepCount      = count($stepLabels);
$expectedSecs   = max(5, (int)($p['expected_seconds'] ?? 60));
$thresholds     = [];
for ($i = 0; $i < $stepCount; $i++) {
    $thresholds[] = (int)round(($i / $stepCount) * $expectedSecs * 0.9);
}

// ── Resolve exec-type specific fields ────────────────────────────────────────
$remoteServer = '';
$remoteScript = '';
switch ($execType) {
    case 'python':
        $remoteServer = trim($p['py_remote_server'] ?? '');
        $remoteScript = trim($p['py_remote_script'] ?? '');
        break;
    case 'cmd':
        $remoteServer = trim($p['cmd_remote_server'] ?? '');
        break;
    default:
        $remoteServer = trim($p['remote_server'] ?? '');
        $remoteScript = trim($p['remote_script']  ?? '');
}

try {
    $conn = getDbConnection();

    // Check if key already exists
    $existing = $conn->prepare('SELECT process_key FROM ETL_Processes WHERE process_key = ?');
    $existing->execute([$key]);
    $isUpdate = (bool)$existing->fetch();

    $params = [
        $name,
        trim($p['description']      ?? ''),
        $logName,
        $execType,
        $remoteServer,
        $remoteScript,
        trim($p['python_exe']       ?? 'python.exe'),
        trim($p['ssis_server']      ?? ''),
        trim($p['ssis_catalog']     ?? 'SSISDB'),
        trim($p['ssis_folder']      ?? ''),
        trim($p['ssis_project']     ?? ''),
        trim($p['ssis_package']     ?? ''),
        trim($p['agent_server']     ?? ''),
        trim($p['agent_job']        ?? ''),
        trim($p['remote_cmd']       ?? ''),
        trim($p['local_script']     ?? ''),
        trim($p['prod_args']        ?? ''),
        trim($p['test_args']        ?? ''),
        trim($p['task_name']        ?? $key . '-OnDemand'),
        $expectedSecs,
        max(30, (int)($p['poll_timeout_secs'] ?? 300)),
        isset($p['advanced']) ? 1 : 0,
        json_encode($stepLabels),
        json_encode($thresholds),
        trim($p['report_name']      ?? ''),
        trim($p['report_url']       ?? ''),
        trim($p['doc_what']         ?? ''),
        trim($p['doc_schedule']     ?? ''),
        trim($p['doc_duration']     ?? ''),
        trim($p['doc_when']         ?? ''),
        trim($p['doc_warnings']     ?? ''),
    ];

    if ($isUpdate) {
        $sql = 'UPDATE ETL_Processes SET
            name=?, description=?, log_process_name=?, exec_type=?,
            remote_server=?, remote_script=?, python_exe=?,
            ssis_server=?, ssis_catalog=?, ssis_folder=?, ssis_project=?, ssis_package=?,
            agent_server=?, agent_job=?, remote_cmd=?, local_script=?,
            prod_args=?, test_args=?, task_name=?,
            expected_seconds=?, poll_timeout_secs=?, advanced=?,
            step_labels=?, step_thresholds=?,
            report_name=?, report_url=?,
            doc_what=?, doc_schedule=?, doc_duration=?, doc_when=?, doc_warnings=?
            WHERE process_key=?';
        $params[] = $key;
    } else {
        $sql = 'INSERT INTO ETL_Processes
            (name, description, log_process_name, exec_type,
             remote_server, remote_script, python_exe,
             ssis_server, ssis_catalog, ssis_folder, ssis_project, ssis_package,
             agent_server, agent_job, remote_cmd, local_script,
             prod_args, test_args, task_name,
             expected_seconds, poll_timeout_secs, advanced,
             step_labels, step_thresholds,
             report_name, report_url,
             doc_what, doc_schedule, doc_duration, doc_when, doc_warnings,
             process_key)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $params[] = $key;
    }

    $conn->prepare($sql)->execute($params);

    echo json_encode([
        'ok'          => true,
        'process_key' => $key,
        'action'      => $isUpdate ? 'updated' : 'created',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
