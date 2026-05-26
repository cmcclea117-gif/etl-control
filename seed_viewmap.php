<?php
// ── seed_viewmap.php ──────────────────────────────────────────────────────────
// Local-mode only endpoint. Called by Invoke-RefreshViewMapETL.ps1 to populate
// SQL_View_Division_Map with sample data so the Dependency Chain tab has
// something to display without a real SQL Server to scan.
//
// In production, sp_Refresh_ViewDivisionMap handles this instead.
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');

$config = require __DIR__ . '/config/app.php';

if (($config['mode'] ?? 'production') !== 'local') {
    http_response_code(403);
    echo json_encode(['error' => 'seed_viewmap.php is only available in local mode']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

try {
    require_once __DIR__ . '/includes/db.php';
    $conn = getDbConnection();

    // Clear existing data
    $conn->exec('DELETE FROM SQL_View_Division_Map');

    // Seed sample views -- realistic enough to show the dependency chain UI
    $samples = [
        // [FoundInDatabase, View_Schema, View_Name, Division_DB, Approx_LineCount]
        ['analytics_db',  'dbo', 'vw_SalesOrders',         'source_erp',    45],
        ['analytics_db',  'dbo', 'vw_PurchaseOrders',      'source_erp',    52],
        ['analytics_db',  'dbo', 'vw_Inventory',           'source_erp',    38],
        ['analytics_db',  'dbo', 'vw_CustomerMaster',      'source_crm',    29],
        ['analytics_db',  'dbo', 'vw_ProductCatalog',      'source_erp',    33],
        ['reporting_db',  'dbo', 'vw_MonthlySales',        'analytics_db',  67],
        ['reporting_db',  'dbo', 'vw_YearlySummary',       'analytics_db',  81],
        ['reporting_db',  'dbo', 'vw_RegionalBreakdown',   'analytics_db',  74],
        ['reporting_db',  'rpt', 'vw_ExecutiveDashboard',  'analytics_db',  95],
        ['reporting_db',  'rpt', 'vw_OperationalKPIs',     'analytics_db',  88],
        ['staging_db',    'stg', 'vw_RawImport',           null,            15],
        ['staging_db',    'stg', 'vw_CleanedRecords',      null,            28],
    ];

    $stmt = $conn->prepare(
        'INSERT INTO SQL_View_Division_Map
            (FoundInDatabase, View_Schema, View_Name, Division_DB, Approx_LineCount, Last_Refreshed)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $now = date('Y-m-d H:i:s');
    foreach ($samples as $row) {
        $stmt->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $now]);
    }

    $count = count($samples);
    echo json_encode([
        'ok'           => true,
        'rows_inserted' => $count,
        'message'      => "Seeded $count sample view map rows",
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
