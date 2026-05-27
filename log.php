<?php
// ── log.php ───────────────────────────────────────────────────────────────────
// Local-mode only endpoint. Called by PowerShell ETL scripts via HTTP to write
// Started/Success/Failed entries to ETL_Sync_Log without needing sqlite3.exe.
//
// POST params:
//   process_name   — must match log_process_name in processes.php
//   status         — Started | Success | Failed
//   record_count   — optional integer
//   error_message  — optional string
//   start_time     — optional ISO datetime string
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');

$config = require __DIR__ . '/config/app.php';

// Only allow in local mode
if (($config['mode'] ?? 'production') !== 'local') {
    http_response_code(403);
    echo json_encode(['error' => 'log.php is only available in local mode']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$processName  = trim($_POST['process_name']  ?? '');
$status       = trim($_POST['status']        ?? '');
$recordCount  = isset($_POST['record_count'])  ? (int)$_POST['record_count']  : null;
$errorMessage = trim($_POST['error_message'] ?? '') ?: null;
$startTimeRaw = trim($_POST['start_time'] ?? '');
// Normalize to Y-m-d H:i:s regardless of format (handles ISO 8601 from Node/Python)
$startTime    = $startTimeRaw ? date('Y-m-d H:i:s', strtotime($startTimeRaw)) : date('Y-m-d H:i:s');

if (!$processName || !$status) {
    http_response_code(400);
    echo json_encode(['error' => 'process_name and status are required']);
    exit;
}

try {
    require_once __DIR__ . '/includes/db.php';
    $conn = getDbConnection();
    $conn->prepare(
        'INSERT INTO ETL_Sync_Log
            (Process_Name, Status, Record_Count, Error_Message, Start_Time, End_Time, Sync_Date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $processName,
        $status,
        $recordCount,
        $errorMessage,
        $startTime,
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
    ]);

    echo json_encode(['ok' => true, 'status' => $status, 'process' => $processName]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
