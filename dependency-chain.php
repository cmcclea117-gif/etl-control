<?php
// ── dependency-chain.php ──────────────────────────────────────────────────────
$currentTab  = 'depchain';
$appConfig = require __DIR__ . '/config/app.php';
$badge    = htmlspecialchars($appConfig['badge']    ?? 'ETL');
$orgName  = htmlspecialchars($appConfig['org_name'] ?? 'ETL Control Panel');
$config      = require __DIR__ . '/config/app.php';
$sqlServer   = $config['sql_server'];
$sqlDatabase = $config['database'];
$error       = null;
$tree        = [];
$meta        = ['last_view_refresh' => null, 'last_pbi_scan' => null, 'total_reports' => 0, 'total_views' => 0, 'total_connections' => 0];

require_once __DIR__ . '/includes/auth.php';
$auth = getAuthUser();

try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = getDbConnection();

    $sql = "
        SELECT
            p.Report_File,
            p.Import_Mode,
            p.Server,
            p.Database_Name     AS PBI_Database,
            p.Schema_Name       AS PBI_Schema,
            p.View_Or_Table     AS PBI_View,
            v.View_Schema,
            v.View_Name,
            v.Division_DB       AS Upstream_Division_DB,
            v.Approx_LineCount,
            v.Last_Refreshed    AS View_Map_Refreshed,
            p.Last_Scanned      AS PBI_Map_Scanned,
            p.Report_URL,
            CASE
                WHEN p.View_Or_Table IS NULL OR p.View_Or_Table = '' THEN 'Unmapped'
                WHEN v.View_Name IS NOT NULL THEN 'Mapped'
                ELSE 'View Not Found'
            END AS Chain_Status
        FROM PBI_Connection_Map p
        LEFT JOIN SQL_View_Division_Map v
            ON  v.View_Name       = p.View_Or_Table
            AND v.FoundInDatabase = p.Database_Name
        ORDER BY p.Server, p.Database_Name, p.Schema_Name, p.View_Or_Table, p.Report_File
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $server  = $row['Server']       ?: 'Unknown';
        $db      = $row['PBI_Database'] ?: 'Unknown';
        $schema  = $row['PBI_Schema']   ?: 'dbo';
        $view    = $row['PBI_View']     ?: '';
        $report  = $row['Report_File']  ?: '';
        $div     = $row['Upstream_Division_DB'] ?: '';

        if (!isset($tree[$server])) $tree[$server] = [];
        if (!isset($tree[$server][$db])) $tree[$server][$db] = [];
        if (!isset($tree[$server][$db][$schema])) $tree[$server][$db][$schema] = [];

        $vkey = $view ?: '__unmapped__';
        if (!isset($tree[$server][$db][$schema][$vkey])) {
            $tree[$server][$db][$schema][$vkey] = [
                'view_name'      => $view,
                'view_schema'    => $row['View_Schema'] ?: '',
                'line_count'     => $row['Approx_LineCount'] ?: 0,
                'chain_status'   => $row['Chain_Status'],
                'view_refreshed' => $row['View_Map_Refreshed'],
                'divisions'      => [],
                'reports'        => [],
            ];
        }

        if ($div && !in_array($div, $tree[$server][$db][$schema][$vkey]['divisions'])) {
            $tree[$server][$db][$schema][$vkey]['divisions'][] = $div;
        }

        if ($report && !isset($tree[$server][$db][$schema][$vkey]['reports'][$report])) {
            $tree[$server][$db][$schema][$vkey]['reports'][$report] = [
                'name'         => $report,
                'import_mode'  => $row['Import_Mode'] ?: '',
                'last_scanned' => $row['PBI_Map_Scanned'],
                'report_url'   => $row['Report_URL'] ?: '',
            ];
        }

        if ($row['View_Map_Refreshed'] && (!$meta['last_view_refresh'] || $row['View_Map_Refreshed'] > $meta['last_view_refresh'])) {
            $meta['last_view_refresh'] = $row['View_Map_Refreshed'];
        }
        if ($row['PBI_Map_Scanned'] && (!$meta['last_pbi_scan'] || $row['PBI_Map_Scanned'] > $meta['last_pbi_scan'])) {
            $meta['last_pbi_scan'] = $row['PBI_Map_Scanned'];
        }
    }

    $allReports = [];
    $allViews   = [];
    foreach ($rows as $row) {
        if ($row['Report_File']) $allReports[$row['Report_File']] = true;
        if ($row['PBI_View'])    $allViews[$row['PBI_View']]     = true;
    }
    $meta['total_reports']     = count($allReports);
    $meta['total_views']       = count($allViews);
    $meta['total_connections'] = count($rows);

} catch (Exception $e) {
    $error = $e->getMessage();
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        require_once __DIR__ . '/includes/db.php';
        $pdo = getDbConnection();
        $rows = $pdo->query("
            SELECT p.Report_File, p.Import_Mode, p.Server,
                p.Database_Name, p.Schema_Name, p.View_Or_Table,
                v.Division_DB AS Upstream_Division_DB, v.Approx_LineCount,
                CASE WHEN p.View_Or_Table IS NULL OR p.View_Or_Table = '' THEN 'Unmapped'
                     WHEN v.View_Name IS NOT NULL THEN 'Mapped' ELSE 'View Not Found' END AS Chain_Status,
                p.Last_Scanned, v.Last_Refreshed
            FROM PBI_Connection_Map p
            LEFT JOIN SQL_View_Division_Map v
                ON v.View_Name = p.View_Or_Table AND v.FoundInDatabase = p.Database_Name
            ORDER BY p.Server, p.Database_Name, p.View_Or_Table, p.Report_File
        ")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="dependency_chain_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
        }
        fclose($out);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Export failed: ' . $e->getMessage();
        exit;
    }
}

$treeJson = json_encode($tree);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $orgName ?> // Dependency Chain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Barlow+Condensed:wght@300;400;600;700&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
    --bg:         #07090d;
    --bg-panel:   #0d1117;
    --bg-card:    #111827;
    --border:     #1e2d40;
    --border-lit: #2a4060;
    --amber:      #e07b0f;
    --amber-dim:  #7a4208;
    --amber-glow: rgba(224,123,15,0.15);
    --green:      #22c55e;
    --green-dim:  #14532d;
    --red:        #ef4444;
    --red-dim:    #7f1d1d;
    --blue:       #38bdf8;
    --blue-dim:   #0c4a6e;
    --purple:     #a78bfa;
    --purple-dim: #4c1d95;
    --teal:       #2dd4bf;
    --teal-dim:   #134e4a;
    --orange:     #fb923c;
    --orange-dim: #7c2d12;
    --yellow:     #fbbf24;
    --text:       #c9d4e0;
    --text-dim:   #4a6080;
    --text-label: #6b8aaa;
    --mono:       'Share Tech Mono', monospace;
    --sans:       'Barlow', sans-serif;
    --cond:       'Barlow Condensed', sans-serif;
    --panel-w:    420px;
    --top-offset: 120px; /* topbar(48) + tabnav(36) + freshness(36) */
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.05) 2px,rgba(0,0,0,0.05) 4px);pointer-events:none;z-index:9999}

/* ── Topbar ── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:10px 24px;background:var(--bg-panel);border-bottom:1px solid var(--border);position:fixed;top:0;left:0;right:0;z-index:100;height:48px}
.topbar-left{display:flex;align-items:center;gap:14px}
.rmg-badge{font-family:var(--cond);font-weight:700;font-size:12px;letter-spacing:.15em;color:var(--amber);background:var(--amber-glow);border:1px solid var(--amber-dim);padding:3px 9px;text-transform:uppercase}
.topbar-title{font-family:var(--cond);font-weight:300;font-size:13px;letter-spacing:.2em;color:var(--text-label);text-transform:uppercase}
.topbar-right{display:flex;align-items:center;gap:16px;font-family:var(--mono);font-size:11px;color:var(--text-dim)}
.user-indicator{display:flex;align-items:center;gap:6px}
.user-dot{width:6px;height:6px;background:var(--green);border-radius:50%;box-shadow:0 0 6px var(--green);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.meta-chip{display:flex;align-items:center;gap:6px;padding:3px 10px;border:1px solid var(--border);background:var(--bg-card)}
.meta-chip .val{color:var(--text)}

/* ── Tab nav ── */
.tab-nav{display:flex;align-items:stretch;background:var(--bg-panel);border-bottom:1px solid var(--border);position:fixed;top:48px;left:0;right:0;z-index:99;height:36px;padding:0 24px}
.tab-link{font-family:var(--mono);font-size:11px;letter-spacing:.12em;text-transform:uppercase;text-decoration:none;color:var(--text-dim);padding:0 18px;display:flex;align-items:center;border-bottom:2px solid transparent;transition:color .15s,border-color .15s;white-space:nowrap}
.tab-link:hover{color:var(--text)}
.tab-link.active{color:var(--amber);border-bottom-color:var(--amber)}
.tab-link .tab-icon{margin-right:7px;font-size:12px;opacity:.7}

/* ── Freshness bar ── */
.freshness{background:var(--bg-panel);border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;gap:20px;font-family:var(--mono);font-size:11px;color:var(--text-dim);position:fixed;top:84px;left:0;right:0;z-index:98;height:36px}
.fresh-item{display:flex;align-items:center;gap:8px}
.fresh-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.fresh-dot.ok{background:var(--green);box-shadow:0 0 6px var(--green)}
.fresh-dot.warn{background:var(--yellow);box-shadow:0 0 6px var(--yellow)}
.fresh-dot.stale{background:var(--red);box-shadow:0 0 6px var(--red)}
.fresh-sep{color:var(--border)}

/* ── Controls ── */
.controls{background:var(--bg-panel);border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;gap:10px;position:fixed;top:120px;left:0;right:0;z-index:97;height:40px}
.search-wrap{position:relative;flex:1;max-width:380px}
.search-wrap input{width:100%;background:var(--bg-card);border:1px solid var(--border);color:var(--text);font-family:var(--mono);font-size:12px;padding:5px 10px 5px 30px;outline:none;letter-spacing:.05em;height:28px}
.search-wrap input:focus{border-color:var(--blue)}
.search-wrap::before{content:'⌕';position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:14px;pointer-events:none}
.search-wrap input::placeholder{color:var(--text-dim)}
.ctrl-btn{font-family:var(--mono);font-size:11px;letter-spacing:.1em;color:var(--text-dim);background:var(--bg-card);border:1px solid var(--border);padding:4px 12px;cursor:pointer;text-transform:uppercase;transition:color .15s,border-color .15s;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;height:28px}
.ctrl-btn:hover{color:var(--text);border-color:var(--border-lit)}
.ctrl-btn.blue{color:var(--blue);border-color:var(--blue-dim)}
.ctrl-btn.blue:hover{border-color:var(--blue)}
.results-count{font-family:var(--mono);font-size:11px;color:var(--text-dim);margin-left:auto}

/* ── Layout ── */
.layout{display:flex;height:calc(100vh - 160px);margin-top:160px}
.tree-pane{flex:1;overflow-y:auto;padding:16px 24px;min-width:0}
.detail-pane{width:var(--panel-w);flex-shrink:0;background:var(--bg-panel);border-left:1px solid var(--border);overflow-y:auto;transition:transform .25s;transform:translateX(0)}
.detail-pane.hidden{transform:translateX(var(--panel-w))}

/* ── Tree ── */
.tree-node{margin:0;padding:0;list-style:none}
.tree-item{margin:1px 0}
.tree-row{display:flex;align-items:center;gap:8px;padding:5px 8px;cursor:pointer;border-radius:2px;transition:background .1s;border-left:2px solid transparent}
.tree-row:hover{background:rgba(255,255,255,.03)}
.tree-row.selected{background:rgba(56,189,248,.06);border-left-color:var(--blue)}
.tree-row.highlighted{background:rgba(251,191,36,.06);border-left-color:var(--yellow)}
.tree-row.dimmed{opacity:.22}
.caret{width:14px;height:14px;flex-shrink:0;color:var(--text-dim);font-size:10px;transition:transform .15s;display:flex;align-items:center;justify-content:center}
.caret.open{transform:rotate(90deg)}
.caret.leaf{color:transparent}
.node-icon{width:16px;height:16px;flex-shrink:0;font-size:11px;display:flex;align-items:center;justify-content:center;border-radius:2px;font-family:var(--mono);font-weight:700}
.node-label{font-family:var(--mono);font-size:12px;letter-spacing:.04em;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.node-badges{display:flex;gap:4px;flex-shrink:0;align-items:center}
.badge{font-family:var(--mono);font-size:10px;padding:1px 5px;border:1px solid;letter-spacing:.04em}
.badge.import{color:var(--green);border-color:var(--green-dim)}
.badge.direct{color:var(--yellow);border-color:#713f12}
.badge.blast{color:var(--red);border-color:var(--red-dim)}
.badge.unmapped{color:var(--text-dim);border-color:var(--border)}
.badge.notfound{color:var(--orange);border-color:var(--orange-dim)}
.badge.divcount{color:var(--teal);border-color:var(--teal-dim)}
.badge.external{color:var(--red);border-color:var(--red-dim)}
.layer-server .node-icon{background:rgba(56,189,248,.15);color:var(--blue)}
.layer-server .node-label{color:var(--blue)}
.layer-db .node-icon{background:rgba(167,139,250,.15);color:var(--purple)}
.layer-db .node-label{color:var(--purple)}
.layer-schema .node-icon{background:rgba(45,212,191,.15);color:var(--teal)}
.layer-schema .node-label{color:var(--teal)}
.layer-view .node-icon{background:rgba(34,197,94,.15);color:var(--green)}
.layer-view .node-label{color:var(--text)}
.layer-report .node-icon{background:rgba(251,146,60,.15);color:var(--orange)}
.layer-report .node-label{color:var(--text-dim)}
.external-server .node-label{color:var(--red) !important}
.external-server .node-icon{background:rgba(239,68,68,.15) !important;color:var(--red) !important}
.tree-children{padding-left:20px;border-left:1px solid var(--border);margin-left:14px;display:none}
.tree-children.open{display:block}

/* ── Detail pane ── */
.detail-header{padding:18px 20px 12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg-panel);z-index:1}
.detail-close{float:right;font-family:var(--mono);font-size:11px;color:var(--text-dim);background:none;border:none;cursor:pointer;padding:2px 6px}
.detail-close:hover{color:var(--text)}
.detail-type{font-family:var(--mono);font-size:10px;letter-spacing:.2em;color:var(--text-dim);text-transform:uppercase;margin-bottom:4px}
.detail-name{font-family:var(--cond);font-weight:700;font-size:20px;letter-spacing:.08em;text-transform:uppercase;word-break:break-all;color:#fff}
.detail-body{padding:16px 20px}
.detail-section{margin-bottom:18px}
.detail-section-label{font-family:var(--mono);font-size:10px;letter-spacing:.15em;color:var(--text-dim);text-transform:uppercase;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--border)}
.detail-row{display:flex;gap:8px;margin-bottom:6px;font-family:var(--mono);font-size:11px}
.detail-key{color:var(--text-dim);min-width:100px;flex-shrink:0}
.detail-val{color:var(--text);word-break:break-all}
.detail-val.warn{color:var(--yellow)}
.detail-val.ok{color:var(--green)}
.detail-val.err{color:var(--red)}
.div-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.div-chip{font-family:var(--mono);font-size:10px;padding:2px 8px;border:1px solid var(--teal-dim);color:var(--teal)}
.report-list{display:flex;flex-direction:column;gap:3px}
.report-item{display:flex;align-items:center;gap:8px;padding:5px 8px;border:1px solid var(--border);background:rgba(255,255,255,.02)}
.report-mode{font-family:var(--mono);font-size:10px;padding:1px 6px;border:1px solid;flex-shrink:0}
.error-box{background:rgba(239,68,68,.08);border:1px solid var(--red-dim);padding:20px;margin:20px;font-family:var(--mono);font-size:12px;color:var(--red)}
.empty-box{padding:60px;text-align:center;font-family:var(--mono);font-size:12px;color:var(--text-dim)}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border)}
::-webkit-scrollbar-thumb:hover{background:var(--border-lit)}
</style>
</head>
<body>

<!-- Topbar — matches ETL control panel -->
<div class="topbar">
    <div class="topbar-left">
        <div class="rmg-badge"><?= $badge ?></div>
        <div class="topbar-title"><?= $orgName ?></div>
    </div>
    <div class="topbar-right">
        <div class="meta-chip"><span>Reports</span><span class="val"><?= $meta['total_reports'] ?></span></div>
        <div class="meta-chip"><span>Views</span><span class="val"><?= $meta['total_views'] ?></span></div>
        <div class="meta-chip"><span>Connections</span><span class="val"><?= $meta['total_connections'] ?></span></div>
        <div class="user-indicator">
            <div class="user-dot"></div>
            <span><?= htmlspecialchars(strtoupper($auth['user'])) ?></span>
        </div>
    </div>
</div>

<!-- Tab nav -->
<nav class="tab-nav">
    <a href="index.php" class="tab-link">
        <span class="tab-icon">⚡</span>ETL Processes
    </a>
    <a href="dependency-chain.php" class="tab-link active">
        <span class="tab-icon">◈</span>Dependency Chain
    </a>
</nav>

<!-- Freshness bar -->
<div class="freshness">
    <?php
    function freshness_status($dt) {
        if (!$dt) return 'stale';
        $age = (time() - strtotime($dt)) / 3600;
        if ($age < 25) return 'ok';
        if ($age < 49) return 'warn';
        return 'stale';
    }
    $vStatus = freshness_status($meta['last_view_refresh']);
    $pStatus = freshness_status($meta['last_pbi_scan']);
    ?>
    <div class="fresh-item">
        <div class="fresh-dot <?= $vStatus ?>"></div>
        <span>View map:</span>
        <span style="color:var(--text)"><?= $meta['last_view_refresh'] ? date('M j, H:i', strtotime($meta['last_view_refresh'])) : 'Never' ?></span>
    </div>
    <span class="fresh-sep">|</span>
    <div class="fresh-item">
        <div class="fresh-dot <?= $pStatus ?>"></div>
        <span>PBIX scan:</span>
        <span style="color:var(--text)"><?= $meta['last_pbi_scan'] ? date('M j, H:i', strtotime($meta['last_pbi_scan'])) : 'Never' ?></span>
    </div>
    <span class="fresh-sep">|</span>
    <span style="font-size:10px">● &lt;25h ok &nbsp;● &lt;49h warn &nbsp;● &gt;49h stale</span>
</div>

<!-- Search + controls -->
<div class="controls">
    <div class="search-wrap">
        <input type="text" id="searchInput" placeholder="Search reports or views..." autocomplete="off">
    </div>
    <button class="ctrl-btn" onclick="expandAll()">Expand All</button>
    <button class="ctrl-btn" onclick="collapseAll()">Collapse All</button>
    <a class="ctrl-btn blue" href="?export=csv">↓ Export CSV</a>
    <button class="ctrl-btn" id="refreshViewMapBtn" onclick="refreshViewMap()"
            style="color:var(--teal);border-color:#134e4a">⟳ Refresh View Map</button>
    <span id="refreshViewMapStatus" style="font-family:var(--mono);font-size:11px;color:var(--text-dim);margin-left:4px"></span>
    <div class="results-count" id="resultsCount"></div>
</div>

<!-- Main layout -->
<div class="layout">
    <div class="tree-pane" id="treePane">
        <?php if ($error): ?>
        <div class="error-box">
            <strong>Database error:</strong><br><?= htmlspecialchars($error) ?>
        </div>
        <?php elseif (empty($tree)): ?>
        <div class="empty-box">
            No dependency data found.<br><br>
            Run <code>EXEC dbo.sp_Refresh_ViewDivisionMap</code> and the PBIX scanner first.
        </div>
        <?php else: ?>
        <ul class="tree-node" id="rootTree"></ul>
        <?php endif; ?>
    </div>
    <div class="detail-pane hidden" id="detailPane">
        <div class="detail-header">
            <button class="detail-close" onclick="closeDetail()">✕ CLOSE</button>
            <div class="detail-type" id="detailType"></div>
            <div class="detail-name" id="detailName"></div>
        </div>
        <div class="detail-body" id="detailBody"></div>
    </div>
</div>

<script>
const TREE_DATA = <?= $treeJson ?>;

function buildTree() {
    const root = document.getElementById('rootTree');
    if (!root) return;

    Object.entries(TREE_DATA).forEach(([server, dbs]) => {
        const isExternal = false; // No truly external servers currently — all are RMG internal
        const serverItem = makeNode({
            label: server, layer: 'server', icon: '⬡',
            extra: isExternal ? 'external-server' : '',
            badges: isExternal ? [{cls:'external', text:'EXTERNAL'}] : [],
            data: {type:'server', server}
        });
        const serverChildren = makeChildren();

        Object.entries(dbs).forEach(([db, schemas]) => {
            const dbItem = makeNode({label:db, layer:'db', icon:'◈', data:{type:'database', server, db}});
            const dbChildren = makeChildren();

            Object.entries(schemas).forEach(([schema, views]) => {
                const schemaItem = makeNode({label:schema, layer:'schema', icon:'◎', data:{type:'schema', server, db, schema}});
                const schemaChildren = makeChildren();

                Object.entries(views).forEach(([vkey, vdata]) => {
                    const divCount   = vdata.divisions.length;
                    const isBlast    = divCount >= 3;
                    const isUnmapped = vdata.chain_status === 'Unmapped';
                    const isNotFound = vdata.chain_status === 'View Not Found';
                    const viewBadges = [];
                    if (isBlast) viewBadges.push({cls:'blast', text:'⚠ BLAST×'+divCount});
                    else if (divCount > 0) viewBadges.push({cls:'divcount', text:divCount+' div'});
                    if (isUnmapped) viewBadges.push({cls:'unmapped', text:'? UNMAPPED'});
                    if (isNotFound) viewBadges.push({cls:'notfound', text:'! NOT FOUND'});

                    const viewItem = makeNode({
                        label: isUnmapped ? '(unmapped)' : (vdata.view_name || vkey),
                        layer:'view', icon: isUnmapped ? '?' : '◇',
                        badges: viewBadges,
                        data: {type:'view', server, db, schema, view:vdata.view_name, vdata}
                    });
                    const viewChildren = makeChildren();

                    Object.values(vdata.reports).forEach(rpt => {
                        const isDQ = (rpt.import_mode||'').toLowerCase()==='directquery';
                        const rptItem = makeNode({
                            label: rpt.name, layer:'report', icon:'◉', leaf:true,
                            badges: isDQ ? [{cls:'direct', text:'⚡ DQ'}]
                                        : (rpt.import_mode ? [{cls:'import', text:rpt.import_mode.toUpperCase()}] : []),
                            data: {type:'report', name:rpt.name, import_mode:rpt.import_mode, last_scanned:rpt.last_scanned, report_url:rpt.report_url, server, db, schema, view:vdata.view_name}
                        });
                        viewChildren.appendChild(rptItem);
                    });

                    viewItem.appendChild(viewChildren);
                    schemaChildren.appendChild(viewItem);
                });
                schemaItem.appendChild(schemaChildren);
                dbChildren.appendChild(schemaItem);
            });
            dbItem.appendChild(dbChildren);
            serverChildren.appendChild(dbItem);
        });
        serverItem.appendChild(serverChildren);
        root.appendChild(serverItem);
    });
}

function makeNode({label, layer, icon, leaf=false, badges=[], extra='', data={}}) {
    const li = document.createElement('li');
    li.className = 'tree-item';
    li.dataset.label = (label||'').toLowerCase();
    li.dataset.nodedata = JSON.stringify(data);
    const row = document.createElement('div');
    row.className = 'tree-row layer-'+layer+(extra?' '+extra:'');
    if (!leaf) {
        const caret = document.createElement('span');
        caret.className = 'caret'; caret.innerHTML = '▶';
        caret.onclick = e => { e.stopPropagation(); toggleNode(li); };
        row.appendChild(caret);
    } else {
        const sp = document.createElement('span');
        sp.className = 'caret leaf'; sp.innerHTML = '·';
        row.appendChild(sp);
    }
    const ico = document.createElement('span');
    ico.className = 'node-icon'; ico.textContent = icon;
    row.appendChild(ico);
    const lbl = document.createElement('span');
    lbl.className = 'node-label'; lbl.textContent = label;
    row.appendChild(lbl);
    if (badges.length) {
        const bdg = document.createElement('span');
        bdg.className = 'node-badges';
        badges.forEach(b => {
            const s = document.createElement('span');
            s.className = 'badge '+b.cls; s.textContent = b.text;
            bdg.appendChild(s);
        });
        row.appendChild(bdg);
    }
    row.onclick = () => selectNode(row, data);
    li.appendChild(row);
    return li;
}

function makeChildren() {
    const ul = document.createElement('ul');
    ul.className = 'tree-node tree-children';
    return ul;
}

function toggleNode(li) {
    const ch = li.querySelector('.tree-children');
    const ca = li.querySelector('.tree-row > .caret');
    if (!ch) return;
    const open = ch.classList.toggle('open');
    if (ca) ca.classList.toggle('open', open);
}

function expandAll() {
    document.querySelectorAll('.tree-children').forEach(c => {
        c.classList.add('open');
        const ca = c.closest('li')?.querySelector(':scope > .tree-row > .caret');
        if (ca) ca.classList.add('open');
    });
}

function collapseAll() {
    document.querySelectorAll('.tree-children').forEach(c => {
        c.classList.remove('open');
        const ca = c.closest('li')?.querySelector(':scope > .tree-row > .caret');
        if (ca) ca.classList.remove('open');
    });
}

let searchTimeout = null;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => doSearch(this.value.trim()), 200);
});

function doSearch(q) {
    const counter = document.getElementById('resultsCount');
    const items   = document.querySelectorAll('.tree-item');
    if (!q) {
        items.forEach(li => {
            li.classList.remove('highlighted','dimmed');
            const lbl = li.querySelector('.node-label');
            if (lbl) lbl.innerHTML = lbl.textContent;
        });
        counter.textContent = '';
        return;
    }
    const ql = q.toLowerCase();
    const matched = new Set();
    items.forEach(li => {
        if ((li.dataset.label||'').includes(ql)) {
            matched.add(li);
            let p = li.parentElement;
            while (p) { if (p.classList.contains('tree-item')) matched.add(p); p = p.parentElement; }
        }
    });
    let hits = 0;
    items.forEach(li => {
        const isMatch = (li.dataset.label||'').includes(ql);
        li.classList.toggle('highlighted', isMatch);
        li.classList.toggle('dimmed', !matched.has(li));
        const lbl = li.querySelector('.node-label');
        if (lbl) {
            const orig = lbl.textContent;
            if (isMatch) {
                const re = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi');
                lbl.innerHTML = orig.replace(re, '<mark style="background:rgba(251,191,36,.3);color:var(--yellow)">$1</mark>');
                hits++;
            } else { lbl.innerHTML = orig; }
        }
        if (matched.has(li)) {
            const ch = li.querySelector('.tree-children');
            if (ch) { ch.classList.add('open'); const ca = li.querySelector(':scope > .tree-row > .caret'); if(ca) ca.classList.add('open'); }
        }
    });
    counter.textContent = hits ? `${hits} match${hits!==1?'es':''}` : 'No matches';
}

let selectedRow = null;
function selectNode(row, data) {
    if (selectedRow) selectedRow.classList.remove('selected');
    selectedRow = row; row.classList.add('selected');
    renderDetail(data);
}

function closeDetail() {
    document.getElementById('detailPane').classList.add('hidden');
    if (selectedRow) selectedRow.classList.remove('selected');
    selectedRow = null;
}

function renderDetail(data) {
    const pane = document.getElementById('detailPane');
    pane.classList.remove('hidden');
    const layers = {server:'SERVER', database:'DATABASE', schema:'SCHEMA', view:'VIEW / TABLE', report:'REPORT'};
    document.getElementById('detailType').textContent = layers[data.type]||data.type.toUpperCase();
    document.getElementById('detailName').textContent = data.view||data.name||data.schema||data.db||data.server||'—';
    let html = '';

    if (data.type === 'view' && data.vdata) {
        const v = data.vdata;
        const divCount = v.divisions.length;
        html += `<div class="detail-section"><div class="detail-section-label">Location</div>
            <div class="detail-row"><span class="detail-key">Server</span><span class="detail-val">${esc(data.server)}</span></div>
            <div class="detail-row"><span class="detail-key">Database</span><span class="detail-val">${esc(data.db)}</span></div>
            <div class="detail-row"><span class="detail-key">Schema</span><span class="detail-val">${esc(data.schema)}</span></div>
            <div class="detail-row"><span class="detail-key">Status</span><span class="detail-val ${v.chain_status==='Mapped'?'ok':v.chain_status==='Unmapped'?'':'warn'}">${esc(v.chain_status)}</span></div>
            ${v.line_count?`<div class="detail-row"><span class="detail-key">Approx lines</span><span class="detail-val">${v.line_count}</span></div>`:''}
        </div>`;
        if (divCount > 0) {
            html += `<div class="detail-section"><div class="detail-section-label">Division Dependencies ${divCount>=3?'⚠ HIGH BLAST RADIUS':''}</div>
                <div class="detail-row"><span class="detail-key">Divisions</span><span class="detail-val ${divCount>=3?'err':'ok'}">${divCount} division${divCount!==1?'s':''}</span></div>
                <div class="div-chips">${v.divisions.map(d=>`<span class="div-chip">${esc(d)}</span>`).join('')}</div>
            </div>`;
        }
        const reports = Object.values(v.reports);
        if (reports.length) {
            const dqCount = reports.filter(r=>(r.import_mode||'').toLowerCase()==='directquery').length;
            html += `<div class="detail-section"><div class="detail-section-label">Reports Using This View (${reports.length})</div>
                ${dqCount?`<div style="font-family:var(--mono);font-size:11px;color:var(--yellow);margin-bottom:8px">⚡ ${dqCount} DirectQuery — changes break these live</div>`:''}
                <div class="report-list">${reports.map(r=>{
                    const isDQ=(r.import_mode||'').toLowerCase()==='directquery';
                    return `<div class="report-item">
                        <span class="report-mode ${isDQ?'direct':'import'}">${isDQ?'⚡ DQ':esc(r.import_mode||'?')}</span>
                        <span style="font-family:var(--mono);font-size:11px;color:var(--text);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.name)}</span>
                    </div>`;
                }).join('')}</div>
            </div>`;
        }
        if (v.view_refreshed) {
            html += `<div class="detail-section"><div class="detail-section-label">Freshness</div>
                <div class="detail-row"><span class="detail-key">Last scanned</span><span class="detail-val">${esc(v.view_refreshed)}</span></div>
            </div>`;
        }
    } else if (data.type === 'report') {
        const isDQ = (data.import_mode||'').toLowerCase()==='directquery';
        html += `<div class="detail-section"><div class="detail-section-label">Connection</div>
            <div class="detail-row"><span class="detail-key">Server</span><span class="detail-val">${esc(data.server)}</span></div>
            <div class="detail-row"><span class="detail-key">Database</span><span class="detail-val">${esc(data.db)}</span></div>
            <div class="detail-row"><span class="detail-key">Schema</span><span class="detail-val">${esc(data.schema)}</span></div>
            <div class="detail-row"><span class="detail-key">View / Table</span><span class="detail-val">${esc(data.view||'—')}</span></div>
        </div>
        <div class="detail-section"><div class="detail-section-label">Import Mode</div>
            <div class="detail-row"><span class="detail-key">Mode</span><span class="detail-val ${isDQ?'warn':'ok'}">${isDQ?'⚡ DirectQuery — LIVE, no cache':esc(data.import_mode||'Import')}</span></div>
            ${isDQ?'<div style="font-family:var(--mono);font-size:11px;color:var(--text-dim);margin-top:4px;line-height:1.6">Schema changes to the upstream view break this report immediately.</div>':''}
        </div>`;
        if (data.report_url) {
            html += `<div class="detail-section">
                <div class="detail-section-label">Power BI Report</div>
                <a href="${esc(data.report_url)}" target="_blank"
                style="font-family:var(--mono);font-size:11px;color:var(--blue);
                        border:1px solid #1e4060;padding:6px 16px;text-decoration:none;
                        background:rgba(56,189,248,0.06);display:inline-block;margin-top:4px;
                        letter-spacing:0.05em;">
                    ↗ Open in Power BI
                </a>
            </div>`;
        } else {
            html += `<div class="detail-section">
                <div class="detail-section-label">Power BI Report</div>
                <span style="font-family:var(--mono);font-size:11px;color:var(--text-dim)">No link yet</span>
            </div>`;
        }
        if (data.last_scanned) html += `<div class="detail-section"><div class="detail-section-label">Scan Info</div>
            <div class="detail-row"><span class="detail-key">Last scanned</span><span class="detail-val">${esc(data.last_scanned)}</span></div>
        </div>`;
    } else {
        const info = countChildren(data);
        html += `<div class="detail-section"><div class="detail-section-label">Summary</div>
            ${Object.entries(info).map(([k,v])=>`<div class="detail-row"><span class="detail-key">${esc(k)}</span><span class="detail-val">${v}</span></div>`).join('')}
        </div>`;
    }
    document.getElementById('detailBody').innerHTML = html;
}

function countChildren(data) {
    const info = {};
    try {
        if (data.type==='server') {
            const dbs = TREE_DATA[data.server]||{};
            info['Databases'] = Object.keys(dbs).length;
            let v=0,r=0;
            Object.values(dbs).forEach(schemas=>Object.values(schemas).forEach(vs=>{
                v+=Object.keys(vs).length;
                Object.values(vs).forEach(vd=>{r+=Object.keys(vd.reports).length;});
            }));
            info['Views / Tables']=v; info['Report connections']=r;
        } else if (data.type==='database') {
            const schemas=TREE_DATA[data.server]?.[data.db]||{};
            info['Schemas']=Object.keys(schemas).length;
            let v=0,r=0;
            Object.values(schemas).forEach(vs=>{v+=Object.keys(vs).length;Object.values(vs).forEach(vd=>{r+=Object.keys(vd.reports).length;});});
            info['Views / Tables']=v; info['Report connections']=r;
        } else if (data.type==='schema') {
            const views=TREE_DATA[data.server]?.[data.db]?.[data.schema]||{};
            info['Views / Tables']=Object.keys(views).length;
            let r=0; Object.values(views).forEach(v=>{r+=Object.keys(v.reports).length;});
            info['Report connections']=r;
        }
    } catch(e){}
    return info;
}

function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Refresh View Map ─────────────────────────────────────────────────────────
function refreshViewMap() {
    const btn    = document.getElementById('refreshViewMapBtn');
    const status = document.getElementById('refreshViewMapStatus');
    btn.disabled = true;
    btn.textContent = '⟳ Refreshing...';
    status.textContent = '';

    const fd = new FormData();
    fd.append('process', 'refreshviewmap');
    fd.append('mode', 'prod');

    fetch('trigger.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                status.textContent = 'Error: ' + data.error;
                status.style.color = 'var(--red)';
                btn.disabled = false;
                btn.textContent = '⟳ Refresh View Map';
                return;
            }
            status.textContent = 'Running...';
            status.style.color = 'var(--amber)';
            // Poll for completion
            const pollStart = Date.now();
            const poll = setInterval(() => {
                const since = Math.floor(pollStart / 1000) - 5;
                fetch('status.php?process=refreshviewmap&since=' + since, { credentials: 'include' })
                    .then(r => r.json())
                    .then(s => {
                        if (s.status === 'Success') {
                            clearInterval(poll);
                            status.textContent = 'Done! Reloading...';
                            status.style.color = 'var(--green)';
                            setTimeout(() => location.reload(), 1200);
                        } else if (s.status === 'Failed') {
                            clearInterval(poll);
                            status.textContent = 'Failed: ' + (s.error_message || 'unknown error');
                            status.style.color = 'var(--red)';
                            btn.disabled = false;
                            btn.textContent = '⟳ Refresh View Map';
                        } else if ((Date.now() - pollStart) > 120000) {
                            clearInterval(poll);
                            status.textContent = 'Timed out';
                            status.style.color = 'var(--yellow)';
                            btn.disabled = false;
                            btn.textContent = '⟳ Refresh View Map';
                        }
                    });
            }, 2000);
        })
        .catch(err => {
            status.textContent = 'Failed: ' + err;
            status.style.color = 'var(--red)';
            btn.disabled = false;
            btn.textContent = '⟳ Refresh View Map';
        });
}

buildTree();
document.querySelectorAll('#rootTree > .tree-item').forEach(li=>{
    const ch=li.querySelector('.tree-children'); const ca=li.querySelector('.caret');
    if(ch)ch.classList.add('open'); if(ca)ca.classList.add('open');
});
</script>
</body>
</html>