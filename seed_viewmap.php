<?php
// ── seed_viewmap.php ──────────────────────────────────────────────────────────
// Called by Invoke-RefreshViewMapETL.ps1 in local mode.
// Reads PBI_Connection_Map and creates matching entries in SQL_View_Division_Map
// so the dependency chain shows Mapped status for all known connections.
// Also seeds a set of sample views for demo purposes.
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

try {
    require_once __DIR__ . '/includes/db.php';
    $conn = getDbConnection();

    // Clear existing view map
    $conn->exec('DELETE FROM SQL_View_Division_Map');

    $now   = date('Y-m-d H:i:s');
    $count = 0;
    $stmt  = $conn->prepare(
        'INSERT INTO SQL_View_Division_Map
            (FoundInDatabase, View_Schema, View_Name, Division_DB, Approx_LineCount, Last_Refreshed)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    // ── Step 1: Seed from actual PBI_Connection_Map entries ───────────────────
    // This makes every scanned connection resolve as Mapped
    $connections = $conn->query(
        'SELECT DISTINCT Database_Name, Schema_Name, View_Or_Table, Server
         FROM PBI_Connection_Map
         WHERE View_Or_Table IS NOT NULL AND View_Or_Table != ""'
    )->fetchAll();

    foreach ($connections as $row) {
        $stmt->execute([
            $row['Database_Name'],
            $row['Schema_Name'] ?: 'dbo',
            $row['View_Or_Table'],
            $row['Database_Name'],  // use same db as upstream source
            null,
            $now,
        ]);
        $count++;
    }

    // ── Step 2: Seed sample views for demo purposes ───────────────────────────
    // These show a realistic dependency chain even without a real PBIX scan
    $samples = [
        ['analytics_db',  'dbo', 'vw_SalesOrders',       'source_erp',    45],
        ['analytics_db',  'dbo', 'vw_PurchaseOrders',    'source_erp',    52],
        ['analytics_db',  'dbo', 'vw_Inventory',         'source_erp',    38],
        ['analytics_db',  'dbo', 'vw_CustomerMaster',    'source_crm',    29],
        ['reporting_db',  'dbo', 'vw_MonthlySales',      'analytics_db',  67],
        ['reporting_db',  'dbo', 'vw_YearlySummary',     'analytics_db',  81],
        ['reporting_db',  'rpt', 'vw_ExecutiveDashboard', 'analytics_db', 95],
        ['staging_db',    'stg', 'vw_RawImport',          null,           15],
    ];

    foreach ($samples as $row) {
        // Don't duplicate if already seeded from PBI_Connection_Map
        $exists = $conn->prepare(
            'SELECT COUNT(*) FROM SQL_View_Division_Map
             WHERE FoundInDatabase = ? AND View_Name = ?'
        );
        $exists->execute([$row[0], $row[2]]);
        if ($exists->fetchColumn()) continue;

        $stmt->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $now]);
        $count++;
    }

    echo json_encode([
        'ok'           => true,
        'rows_inserted' => $count,
        'from_pbix'    => count($connections),
        'sample_rows'  => $count - count($connections),
        'message'      => "Seeded $count view map rows (${\count($connections)} from PBIX scan)",
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
