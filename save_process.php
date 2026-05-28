<?php
// ── save_process.php ──────────────────────────────────────────────────────────
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

// ── Validate ──────────────────────────────────────────────────────────────────
$key      = trim(preg_replace('/[^a-z0-9_]/', '', strtolower($p['process_key'] ?? '')));
$name     = trim($p['name']             ?? '');
$logName  = trim($p['log_process_name'] ?? '');
$execType = trim($p['exec_type']        ?? 'powershell');

if (!$key || !$name || !$logName) {
    http_response_code(400);
    echo json_encode(['error' => 'Process key, name, and log process name are required']);
    exit;
}

// ── Step labels + thresholds ──────────────────────────────────────────────────
$stepLabelsRaw  = trim($p['step_labels'] ?? '');
$stepLabels     = array_values(array_filter(array_map('trim', explode("\n", $stepLabelsRaw))));
if (empty($stepLabels)) $stepLabels = ['Initializing', 'Processing', 'Completing'];
$expectedSecs   = max(5, (int)($p['expected_seconds'] ?? 60));
$stepCount      = count($stepLabels);
$thresholds     = [];
for ($i = 0; $i < $stepCount; $i++) {
    $thresholds[] = (int)round(($i / $stepCount) * $expectedSecs * 0.9);
}

// ── Exec-type specific fields ─────────────────────────────────────────────────
$remoteServer = ''; $remoteScript = '';
switch ($execType) {
    case 'python': $remoteServer = trim($p['py_remote_server'] ?? ''); $remoteScript = trim($p['py_remote_script'] ?? ''); break;
    case 'r':      $remoteServer = trim($p['r_remote_server']  ?? ''); $remoteScript = trim($p['r_remote_script']  ?? ''); break;
    case 'node':   $remoteServer = trim($p['node_remote_server'] ?? ''); $remoteScript = trim($p['node_remote_script'] ?? ''); break;
    case 'cmd':    $remoteServer = trim($p['cmd_remote_server'] ?? ''); break;
    default:       $remoteServer = trim($p['remote_server'] ?? ''); $remoteScript = trim($p['remote_script'] ?? '');
}

try {
    $conn = getDbConnection();

    // Check if exists
    $existing = $conn->prepare('SELECT process_key FROM ETL_Processes WHERE process_key = ?');
    $existing->execute([$key]);
    $isUpdate = (bool)$existing->fetch();

    // ── Core fields ───────────────────────────────────────────────────────────
    $coreParams = [
        trim($name),
        trim($p['description']      ?? ''),
        trim($logName),
        trim($execType),
        trim($remoteServer),
        trim($remoteScript),
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
    ];  // 31 params

    if ($isUpdate) {
        $conn->prepare('UPDATE ETL_Processes SET
            name=?, description=?, log_process_name=?, exec_type=?,
            remote_server=?, remote_script=?, python_exe=?,
            ssis_server=?, ssis_catalog=?, ssis_folder=?, ssis_project=?, ssis_package=?,
            agent_server=?, agent_job=?, remote_cmd=?, local_script=?,
            prod_args=?, test_args=?, task_name=?,
            expected_seconds=?, poll_timeout_secs=?, advanced=?,
            step_labels=?, step_thresholds=?,
            report_name=?, report_url=?,
            doc_what=?, doc_schedule=?, doc_duration=?, doc_when=?, doc_warnings=?
            WHERE process_key=?'
        )->execute(array_merge($coreParams, [$key]));
    } else {
        $conn->prepare('INSERT INTO ETL_Processes
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
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute(array_merge($coreParams, [$key]));
    }

    // ── Source / Destination fields ───────────────────────────────────────────
    $conn->prepare('UPDATE ETL_Processes SET
        source_type=?, source_host=?, source_port=?, source_database=?,
        source_username=?, source_schema=?, source_query=?,
        dest_type=?, dest_host=?, dest_port=?, dest_database=?,
        dest_username=?, dest_schema=?, dest_table=?
        WHERE process_key=?'
    )->execute([
        trim($p['source_type']     ?? 'none'),
        trim($p['source_host']     ?? ''),
        trim($p['source_port']     ?? ''),
        trim($p['source_database'] ?? ''),
        trim($p['source_username'] ?? ''),
        trim($p['source_schema']   ?? ''),
        trim($p['source_query']    ?? ''),
        trim($p['dest_type']       ?? 'none'),
        trim($p['dest_host']       ?? ''),
        trim($p['dest_port']       ?? ''),
        trim($p['dest_database']   ?? ''),
        trim($p['dest_username']   ?? ''),
        trim($p['dest_schema']     ?? ''),
        trim($p['dest_table']      ?? ''),
        $key,
    ]);

    // ── Auto-generate ETL script if connections configured ────────────────────
    $srcType = trim($p['source_type'] ?? 'none');
    $dstType = trim($p['dest_type']   ?? 'none');
    $hasConnection = ($srcType !== 'none' || $dstType !== 'none');

    if ($hasConnection) {
        try {
            if (!defined('GENERATE_SCRIPT_INCLUDED')) {
                define('GENERATE_SCRIPT_INCLUDED', true);
            }
            $_GET['process'] = $key;
            ob_start();
            include __DIR__ . '/generate_script.php';
            ob_end_clean();
        } catch (Exception $e) {
            // Non-fatal -- user can click ↓ SCRIPT to generate manually
        }
    }

    echo json_encode([
        'ok'             => true,
        'process_key'    => $key,
        'action'         => $isUpdate ? 'updated' : 'created',
        'has_connection' => $hasConnection,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
