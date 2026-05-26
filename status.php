<?php
// ── status.php ────────────────────────────────────────────────────────────────
// Generic status endpoint for any registered ETL process.
// GET params:
//   process  (key)     — required
//   since    (unix ts) — poll mode: return latest row after this time
//   history  (1)       — history mode: return last 10 runs
// ─────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

$processes  = require __DIR__ . '/config/processes.php';
$processKey = trim($_GET['process'] ?? '');

if (empty($processKey) || !isset($processes[$processKey])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown process: ' . htmlspecialchars($processKey)]);
    exit;
}

$logName = $processes[$processKey]['log_process_name'];

try {
    $conn = getDbConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ── History mode ──────────────────────────────────────────────────────────────
if (isset($_GET['history'])) {
    $stmt = $conn->prepare(
        'SELECT Log_ID, Status, Start_Time, End_Time, Record_Count, Error_Message
         FROM ETL_Sync_Log
         WHERE Process_Name = ?
         ORDER BY Log_ID DESC
         LIMIT 10'
    );
    $stmt->execute([$logName]);
    $runs = array_map(fn($r) => [
        'log_id'        => $r['Log_ID'],
        'status'        => $r['Status'],
        'start_time'    => $r['Start_Time'],
        'end_time'      => $r['End_Time'],
        'record_count'  => $r['Record_Count'],
        'error_message' => $r['Error_Message'],
    ], $stmt->fetchAll());

    echo json_encode(['runs' => $runs]);
    exit;
}

// ── Poll mode ─────────────────────────────────────────────────────────────────
$since   = isset($_GET['since']) ? (int)$_GET['since'] : (time() - 300);
$sinceTs = date('Y-m-d H:i:s', $since);

$stmt = $conn->prepare(
    'SELECT Status, Start_Time, End_Time, Record_Count, Error_Message
     FROM ETL_Sync_Log
     WHERE Process_Name = ? AND Sync_Date >= ?
     ORDER BY Log_ID DESC
     LIMIT 1'
);
$stmt->execute([$logName, $sinceTs]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['status' => null]);
    exit;
}

echo json_encode([
    'status'        => $row['Status'],
    'start_time'    => $row['Start_Time'],
    'end_time'      => $row['End_Time'],
    'record_count'  => $row['Record_Count'],
    'error_message' => $row['Error_Message'],
]);
