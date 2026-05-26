<?php
// ── save_docs.php ─────────────────────────────────────────────────────────────
// Accepts POST from the inline docs editor modal.
// Upserts into ETL_Process_Docs.
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$auth = requireAuth();

$processes  = require __DIR__ . '/config/processes.php';
$processKey = trim($_POST['process_key'] ?? '');

if (empty($processKey) || !isset($processes[$processKey])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown process key: ' . htmlspecialchars($processKey)]);
    exit;
}

$fields = ['what', 'schedule', 'duration', 'when_to_run', 'warnings'];
$data   = [];
foreach ($fields as $f) {
    $data[$f] = trim($_POST[$f] ?? '');
}

try {
    $conn = getDbConnection();

    // DELETE + INSERT works for both SQLite and SQL Server
    $conn->prepare('DELETE FROM ETL_Process_Docs WHERE process_key = ?')
         ->execute([$processKey]);

    $conn->prepare(
        'INSERT INTO ETL_Process_Docs
            (process_key, what, schedule, duration, when_to_run, warnings, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $processKey,
        $data['what'],
        $data['schedule'],
        $data['duration'],
        $data['when_to_run'],
        $data['warnings'],
        $auth['full'],
        date('Y-m-d H:i:s'),
    ]);

    echo json_encode([
        'ok'          => true,
        'process_key' => $processKey,
        'updated_by'  => $auth['full'],
        'updated_at'  => date('c'),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
