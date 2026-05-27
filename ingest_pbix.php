<?php
// ── ingest_pbix.php ───────────────────────────────────────────────────────────
// Accepts POST requests from Scan-PBIX_SharePoint.ps1 to write connection
// data into the local SQLite PBI_Connection_Map table.
//
// Called by the scanner in two ways:
//   action=clear  -- truncates PBI_Connection_Map before a fresh scan
//   action=insert -- inserts a single connection row
//   action=update_url -- updates Report_URL for a report by name
//
// This replaces the direct SQL Server write in the scanner, keeping all
// app data in SQLite regardless of where ETL scripts connect.
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$action = trim($_POST['action'] ?? 'insert');

try {
    $conn = getDbConnection();

    // ── Clear all rows before a fresh scan ────────────────────────────────────
    if ($action === 'clear') {
        $conn->exec('DELETE FROM PBI_Connection_Map');
        echo json_encode(['ok' => true, 'action' => 'cleared']);
        exit;
    }

    // ── Update report URL by report file name ─────────────────────────────────
    if ($action === 'update_url') {
        $reportFile = trim($_POST['report_file'] ?? '');
        $reportUrl  = trim($_POST['report_url']  ?? '');
        if (!$reportFile || !$reportUrl) {
            http_response_code(400);
            echo json_encode(['error' => 'report_file and report_url required']);
            exit;
        }
        $stmt = $conn->prepare(
            "UPDATE PBI_Connection_Map SET Report_URL = ?
             WHERE LOWER(REPLACE(Report_File, '.pbix', '')) = LOWER(?)"
        );
        $stmt->execute([$reportUrl, str_ireplace('.pbix', '', $reportFile)]);
        echo json_encode(['ok' => true, 'action' => 'url_updated', 'rows' => $stmt->rowCount()]);
        exit;
    }

    // ── Insert a single connection row ────────────────────────────────────────
    $required = ['report_file', 'server', 'database_name'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            exit;
        }
    }

    $conn->prepare(
        'INSERT INTO PBI_Connection_Map
            (Report_File, SharePoint_Path, SharePoint_Site, Server,
             Database_Name, View_Or_Table, Schema_Name, Import_Mode, Last_Scanned)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        trim($_POST['report_file']      ?? ''),
        trim($_POST['sharepoint_path']  ?? ''),
        trim($_POST['sharepoint_site']  ?? ''),
        trim($_POST['server']           ?? ''),
        trim($_POST['database_name']    ?? ''),
        trim($_POST['view_or_table']    ?? ''),
        trim($_POST['schema_name']      ?? ''),
        trim($_POST['import_mode']      ?? ''),
        date('Y-m-d H:i:s'),
    ]);

    echo json_encode(['ok' => true, 'action' => 'inserted']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
