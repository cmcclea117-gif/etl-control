<?php
$currentTab = 'etl';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
$appConfig = require __DIR__ . '/config/app.php';
$badge    = htmlspecialchars($appConfig['badge']    ?? 'ETL');
$orgName  = htmlspecialchars($appConfig['org_name'] ?? 'ETL Control Panel');

$processes = require __DIR__ . '/config/processes.php';
$auth      = getAuthUser();

// ── Load docs from SQL (takes precedence over hardcoded fallback) ─────────────
try {
    $conn = getDbConnection();
    $rows = $conn->query('SELECT * FROM ETL_Process_Docs')->fetchAll();
    foreach ($rows as $row) {
        $k = $row['process_key'];
        if (!isset($processes[$k])) continue;
        if (!isset($processes[$k]['docs'])) $processes[$k]['docs'] = [];
        // DB overrides hardcoded — only replace non-empty values
        if (!empty($row['what']))        $processes[$k]['docs']['what']     = $row['what'];
        if (!empty($row['schedule']))    $processes[$k]['docs']['schedule'] = $row['schedule'];
        if (!empty($row['duration']))    $processes[$k]['docs']['duration'] = $row['duration'];
        if (!empty($row['when_to_run'])) $processes[$k]['docs']['when']     = $row['when_to_run'];
        if (!empty($row['warnings']))    $processes[$k]['docs']['warnings'] = $row['warnings'];
    }
} catch (Exception $e) {
    // Silently fall back to hardcoded docs — dashboard still works without the table
}

$standard = array_filter($processes, fn($p) => !$p['advanced']);
$advanced = array_filter($processes, fn($p) => $p['advanced']);

// Build docs map for JS (pre-populate modal fields)
$docsForJs = [];
foreach ($processes as $k => $p) {
    $docsForJs[$k] = [
        'what'       => $p['docs']['what']     ?? '',
        'schedule'   => $p['docs']['schedule'] ?? '',
        'duration'   => $p['docs']['duration'] ?? '',
        'when_to_run'=> $p['docs']['when']     ?? '',
        'warnings'   => $p['docs']['warnings'] ?? '',
        'name'       => $p['name'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $orgName ?> // ETL Control Panel</title>
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
            --teal:       #2dd4bf;
            --orange:     #f97316;
            --text:       #c9d4e0;
            --text-dim:   #4a6080;
            --text-label: #6b8aaa;
            --mono:       'Share Tech Mono', monospace;
            --sans:       'Barlow', sans-serif;
            --cond:       'Barlow Condensed', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--sans);
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed; inset: 0;
            background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,0,0,0.07) 2px, rgba(0,0,0,0.07) 4px);
            pointer-events: none;
            z-index: 9999;
        }

        /* ── Top bar ── */
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 32px;
            background: var(--bg-panel);
            border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 100;
        }

        .topbar-left { display: flex; align-items: center; gap: 16px; }

        .rmg-badge {
            font-family: var(--cond); font-weight: 700; font-size: 13px;
            letter-spacing: 0.15em; color: var(--amber);
            background: var(--amber-glow); border: 1px solid var(--amber-dim);
            padding: 4px 10px; text-transform: uppercase;
            cursor: pointer; user-select: none;
            transition: box-shadow 0.1s;
        }

        .rmg-badge.activated {
            box-shadow: 0 0 20px var(--red), 0 0 40px rgba(239,68,68,0.3);
            border-color: var(--red);
            color: var(--red);
        }

        .topbar-title {
            font-family: var(--cond); font-weight: 300; font-size: 13px;
            letter-spacing: 0.2em; color: var(--text-label); text-transform: uppercase;
        }

        .topbar-right {
            display: flex; align-items: center; gap: 20px;
            font-family: var(--mono); font-size: 11px; color: var(--text-dim);
        }

        .user-indicator { display: flex; align-items: center; gap: 6px; }
        .user-dot {
            width: 6px; height: 6px; background: var(--green);
            border-radius: 50%; box-shadow: 0 0 6px var(--green);
            animation: blink 2s infinite;
        }

        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }

        /* ── Main ── */
        .main { max-width: 1100px; margin: 0 auto; padding: 84px 32px 48px; }

        /* ── Page header ── */
        .page-header { margin-bottom: 48px; }
        .page-header::before {
            content: 'SYS.ETL.CTRL';
            font-family: var(--mono); font-size: 10px;
            color: var(--text-dim); letter-spacing: 0.15em;
            display: block; margin-bottom: 8px;
        }
        .page-header h1 {
            font-family: var(--cond); font-weight: 700; font-size: 36px;
            letter-spacing: 0.08em; text-transform: uppercase; color: #fff; line-height: 1;
        }
        .page-header h1 span { color: var(--amber); }
        .page-header .subtitle {
            margin-top: 8px; font-family: var(--cond); font-weight: 300;
            font-size: 14px; letter-spacing: 0.2em; color: var(--text-label); text-transform: uppercase;
        }
        .header-rule {
            margin-top: 20px; height: 1px;
            background: linear-gradient(90deg, var(--amber) 0%, var(--border) 40%, transparent 100%);
        }

        /* ── Section label ── */
        .section-header {
            display: flex; align-items: center; gap: 12px;
            margin: 32px 0 16px;
            font-family: var(--mono); font-size: 10px; letter-spacing: 0.2em;
            color: var(--text-dim); text-transform: uppercase;
        }
        .section-header::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        /* ── Advanced section ── */
        .advanced-section {
            display: none;
            animation: slideDown 0.4s ease;
        }
        .advanced-section.visible { display: block; }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .advanced-warning {
            background: rgba(239,68,68,0.08);
            border: 1px solid var(--red-dim);
            padding: 14px 20px;
            margin-bottom: 16px;
            font-family: var(--mono);
            font-size: 11px;
            color: var(--red);
            letter-spacing: 0.05em;
        }

        .advanced-warning strong { font-size: 12px; letter-spacing: 0.1em; }

        /* ── Process panels ── */
        .processes { display: flex; flex-direction: column; gap: 16px; }

        .process-panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            position: relative; overflow: hidden;
        }

        .process-panel.advanced-panel { border-color: rgba(239,68,68,0.3); }
        .process-panel.advanced-panel::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 2px; background: linear-gradient(90deg, var(--red), transparent);
        }
        .process-panel:not(.advanced-panel)::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 2px; background: linear-gradient(90deg, var(--amber), transparent);
        }

        .panel-top {
            display: grid; grid-template-columns: 1fr auto;
            gap: 24px; padding: 24px 28px; align-items: start;
        }

        .panel-meta {}

        .panel-name-row {
            display: flex; align-items: center; gap: 10px; margin-bottom: 4px;
        }

        .panel-key {
            font-family: var(--mono); font-size: 10px; letter-spacing: 0.2em;
            color: var(--text-dim); text-transform: uppercase; margin-bottom: 4px;
        }

        .panel-name {
            font-family: var(--cond); font-weight: 700; font-size: 24px;
            letter-spacing: 0.1em; text-transform: uppercase; color: #fff;
        }

        .panel-name.advanced-name { color: #fca5a5; }

        .info-btn {
            font-family: var(--mono); font-size: 11px; color: var(--text-dim);
            background: none; border: 1px solid var(--border); padding: 2px 8px;
            cursor: pointer; letter-spacing: 0.05em; transition: all 0.15s;
            flex-shrink: 0;
        }
        .info-btn:hover { color: var(--blue); border-color: var(--blue); }
        .info-btn.active { color: var(--blue); border-color: var(--blue); background: rgba(56,189,248,0.08); }

        /* Edit docs button — amber variant, only shown in advanced mode */
        .edit-doc-btn {
            color: var(--amber) !important;
            border-color: var(--amber-dim) !important;
        }
        .edit-doc-btn:hover {
            color: var(--amber) !important;
            border-color: var(--amber) !important;
            background: var(--amber-glow) !important;
        }

        .panel-desc {
            font-family: var(--mono); font-size: 11px; color: var(--text-dim);
        }

        .panel-status {
            margin-top: 10px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }

        /* ── Docs drawer ── */
        .docs-drawer {
            display: none;
            border-top: 1px solid var(--border);
            padding: 20px 28px;
            background: rgba(56,189,248,0.03);
        }
        .docs-drawer.open { display: block; }

        .docs-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .docs-item {}
        .docs-item.full { grid-column: 1 / -1; }

        .docs-label {
            font-family: var(--mono); font-size: 10px; letter-spacing: 0.15em;
            color: var(--blue); text-transform: uppercase; margin-bottom: 6px;
        }

        .docs-text {
            font-family: var(--sans); font-size: 12px; color: var(--text);
            line-height: 1.6; opacity: 0.85;
        }

        .docs-warning {
            font-family: var(--mono); font-size: 11px; color: var(--orange);
            line-height: 1.5; background: rgba(249,115,22,0.08);
            border-left: 2px solid var(--orange); padding: 8px 12px;
        }

        /* ── Buttons ── */
        .btn-group { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }

        .btn {
            font-family: var(--cond); font-weight: 700; font-size: 13px;
            letter-spacing: 0.15em; text-transform: uppercase; border: none;
            padding: 12px 24px; cursor: pointer; transition: all 0.15s; white-space: nowrap;
        }

        .btn-prod {
            color: var(--bg); background: var(--amber); position: relative; overflow: hidden;
        }
        .btn-prod::before {
            content: ''; position: absolute; inset: 0;
            background: rgba(255,255,255,0.15); transform: translateX(-100%); transition: transform 0.3s;
        }
        .btn-prod:hover::before { transform: translateX(0); }
        .btn-prod:hover { box-shadow: 0 0 20px var(--amber-glow); }
        .btn-prod.danger { background: var(--red); }
        .btn-prod.danger:hover { box-shadow: 0 0 20px rgba(239,68,68,0.3); }

        .btn-test {
            color: var(--teal); background: transparent; border: 1px solid #1a3a40;
        }
        .btn-test:hover { border-color: var(--teal); background: rgba(45,212,191,0.06); }

        .btn:disabled { opacity: 0.35; cursor: not-allowed; box-shadow: none; }
        .btn:disabled::before { display: none; }

        /* ── Progress ── */
        .panel-progress { padding: 0 28px 20px; display: none; }
        .panel-progress.visible { display: block; }

        .prog-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .prog-label { font-family: var(--mono); font-size: 11px; color: var(--text-label); letter-spacing: 0.1em; }
        .prog-pct   { font-family: var(--mono); font-size: 11px; color: var(--amber); }

        .prog-track { height: 3px; background: var(--border); position: relative; overflow: hidden; }
        .prog-fill {
            height: 100%; width: 0%;
            background: linear-gradient(90deg, var(--amber-dim), var(--amber));
            transition: width 0.5s ease; position: relative;
        }
        .prog-fill::after {
            content: ''; position: absolute; right: 0; top: 0; bottom: 0; width: 40px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.35));
        }
        .prog-steps { display: flex; flex-direction: column; gap: 3px; margin-top: 10px; }
        .prog-step {
            font-family: var(--mono); font-size: 11px; color: var(--text-dim);
            display: flex; align-items: center; gap: 8px; opacity: 0.4; transition: opacity 0.3s, color 0.3s;
        }
        .prog-step.active { color: var(--amber); opacity: 1; }
        .prog-step.done   { color: var(--green); opacity: 0.7; }
        .prog-step .si    { width: 12px; text-align: center; }

        /* ── Result ── */
        .panel-result {
            margin: 0 28px 20px; padding: 14px 18px;
            border: 1px solid; font-family: var(--mono); font-size: 12px; display: none;
        }
        .panel-result.visible { display: block; }
        .panel-result.success { border-color: var(--green-dim); background: rgba(34,197,94,0.06); color: var(--green); }
        .panel-result.failed  { border-color: var(--red-dim);   background: rgba(239,68,68,0.06);  color: var(--red); }
        .result-title { font-weight: bold; font-size: 13px; margin-bottom: 4px; letter-spacing: 0.1em; }
        .result-detail { opacity: 0.8; }

        /* ── History ── */
        .panel-history { border-top: 1px solid var(--border); padding: 0 28px; }
        .history-toggle {
            font-family: var(--mono); font-size: 10px; letter-spacing: 0.15em;
            color: var(--text-dim); text-transform: uppercase; background: none; border: none;
            cursor: pointer; padding: 12px 0; display: flex; align-items: center; gap: 8px; width: 100%;
        }
        .history-toggle:hover { color: var(--text-label); }
        .history-toggle .arrow { transition: transform 0.2s; }
        .history-toggle.open .arrow { transform: rotate(180deg); }
        .history-table-wrap { display: none; padding-bottom: 16px; }
        .history-table-wrap.open { display: block; }

        table { width: 100%; border-collapse: collapse; font-family: var(--mono); font-size: 11px; }
        th { text-align: left; padding: 7px 10px; color: var(--text-dim); letter-spacing: 0.1em; text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: normal; }
        td { padding: 9px 10px; border-bottom: 1px solid rgba(30,45,64,0.4); color: var(--text); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.015); }

        /* ── Status badge ── */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-family: var(--mono); font-size: 11px; letter-spacing: 0.08em;
            padding: 2px 8px; border: 1px solid;
        }
        .badge .dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge.success { color: var(--green); border-color: var(--green-dim); background: rgba(34,197,94,0.08); }
        .badge.failed  { color: var(--red);   border-color: var(--red-dim);   background: rgba(239,68,68,0.08); }
        .badge.started { color: var(--blue);  border-color: #1e4060;          background: rgba(56,189,248,0.08); }
        .badge.running { color: var(--amber); border-color: var(--amber-dim); background: var(--amber-glow); }
        .badge.running .dot, .badge.started .dot { animation: blink 1s infinite; }

        /* ── Footer ── */
        .footer {
            margin-top: 48px; padding-top: 20px; border-top: 1px solid var(--border);
            display: flex; justify-content: space-between;
            font-family: var(--mono); font-size: 10px; color: var(--text-dim); letter-spacing: 0.1em;
        }

        /* ── Doc Editor Modal ────────────────────────────────────────────────── */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 500;
            background: rgba(0,0,0,0.75);
            display: none; align-items: center; justify-content: center;
            backdrop-filter: blur(2px);
        }
        .modal-overlay.open { display: flex; }

        .modal {
            background: var(--bg-panel);
            border: 1px solid var(--border-lit);
            width: 680px; max-width: calc(100vw - 48px);
            max-height: calc(100vh - 80px);
            display: flex; flex-direction: column;
            box-shadow: 0 0 60px rgba(0,0,0,0.6);
            animation: modalIn 0.18s ease;
        }
        @keyframes modalIn {
            from { opacity:0; transform: translateY(-12px) scale(0.98); }
            to   { opacity:1; transform: translateY(0)     scale(1); }
        }

        .modal-header {
            padding: 18px 24px 14px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-shrink: 0;
        }
        .modal-header-left {}
        .modal-eyebrow {
            font-family: var(--mono); font-size: 10px; letter-spacing: 0.2em;
            color: var(--amber); text-transform: uppercase; margin-bottom: 4px;
        }
        .modal-title {
            font-family: var(--cond); font-weight: 700; font-size: 20px;
            letter-spacing: 0.08em; text-transform: uppercase; color: #fff;
        }
        .modal-close {
            font-family: var(--mono); font-size: 12px; color: var(--text-dim);
            background: none; border: 1px solid var(--border); cursor: pointer;
            padding: 4px 10px; flex-shrink: 0; margin-left: 16px;
            transition: color 0.15s, border-color 0.15s;
        }
        .modal-close:hover { color: var(--text); border-color: var(--border-lit); }

        .modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-body::-webkit-scrollbar { width: 4px; }
        .modal-body::-webkit-scrollbar-thumb { background: var(--border); }

        .field-group { margin-bottom: 16px; }
        .field-label {
            font-family: var(--mono); font-size: 10px; letter-spacing: 0.15em;
            color: var(--text-label); text-transform: uppercase;
            display: flex; align-items: center; gap: 8px; margin-bottom: 6px;
        }
        .field-label span { opacity: 0.5; font-size: 9px; }
        .field-input, .field-textarea {
            width: 100%; background: var(--bg-card);
            border: 1px solid var(--border); color: var(--text);
            font-family: var(--sans); font-size: 12px; line-height: 1.6;
            padding: 8px 12px; outline: none; resize: vertical;
            transition: border-color 0.15s;
        }
        .field-input:focus, .field-textarea:focus { border-color: var(--amber); }
        .field-input  { height: 34px; resize: none; }
        .field-textarea { min-height: 72px; }
        .field-textarea.tall { min-height: 96px; }
        .field-textarea.warning-field {
            border-left: 2px solid var(--orange);
            color: var(--orange);
        }
        .field-textarea.warning-field:focus { border-color: var(--orange); }

        .modal-footer {
            padding: 14px 24px;
            border-top: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .modal-footer-left {
            font-family: var(--mono); font-size: 10px; color: var(--text-dim);
            letter-spacing: 0.08em;
        }
        .modal-footer-left.saved { color: var(--green); }
        .modal-footer-left.error { color: var(--red); }
        .modal-footer-right { display: flex; gap: 8px; }

        .modal-btn {
            font-family: var(--cond); font-weight: 700; font-size: 12px;
            letter-spacing: 0.15em; text-transform: uppercase;
            padding: 8px 20px; cursor: pointer; border: none; transition: all 0.15s;
        }
        .modal-btn-save {
            background: var(--amber); color: var(--bg);
            position: relative; overflow: hidden;
        }
        .modal-btn-save::before {
            content: ''; position: absolute; inset: 0;
            background: rgba(255,255,255,0.15); transform: translateX(-100%); transition: transform 0.25s;
        }
        .modal-btn-save:hover::before { transform: translateX(0); }
        .modal-btn-save:disabled { opacity: 0.4; cursor: not-allowed; }
        .modal-btn-save:disabled::before { display: none; }
        .modal-btn-cancel {
            background: none; color: var(--text-dim);
            border: 1px solid var(--border);
        }
        .modal-btn-cancel:hover { color: var(--text); border-color: var(--border-lit); }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <div class="rmg-badge" id="rmgBadge" onclick="handleBadgeClick()"><?= $badge ?></div>
        <div class="topbar-title">ELT Control Panel</div>
    </div>
    <div class="topbar-right">
        <div class="user-indicator">
            <div class="user-dot"></div>
            <span><?= htmlspecialchars(strtoupper($auth['user'])) ?></span>
        </div>
        <div id="clock">--:--:--</div>
    </div>
</div>

<?php include __DIR__ . '/includes/tab_nav.php'; ?>

<div class="main">
    <div class="page-header">
        <h1><?= $orgName ?> <span>Extract/Load/Transform</span><br>Control Panel</h1>
        <div class="subtitle">Operational Data Pipeline Management</div>
        <div class="header-rule"></div>
    </div>

    <!-- Standard processes -->
    <div class="section-header" style="justify-content:space-between">
        Standard Processes
        <a href="add_process.php"
           style="font-family:var(--mono);font-size:11px;letter-spacing:.1em;color:var(--amber);
                  border:1px solid var(--amber-dim);padding:4px 14px;text-decoration:none;
                  background:var(--amber-glow);text-transform:uppercase;margin-left:auto">
            + Add Process
        </a>
    </div>
    <div class="processes" id="standardProcesses">
    <?php foreach ($standard as $key => $proc): ?>
        <?php include __DIR__ . '/includes/process_panel.php'; ?>
    <?php endforeach; ?>
    </div>

    <!-- Advanced processes (hidden behind easter egg) -->
    <div class="advanced-section" id="advancedSection">
        <div class="section-header" style="color:var(--red);border-color:var(--red)">⚠ Advanced — Resource Intensive</div>
        <div class="advanced-warning">
            <strong>⚠ ADVANCED OPERATIONS</strong><br>
            These jobs are scheduled to run overnight and put significant load on your-etl-server.
            Only trigger manually if you know what you are doing and have confirmed with IT.
            Both are fire-and-forget — check Recent Runs for completion status.
        </div>
        <div class="processes" id="advancedProcesses">
        <?php foreach ($advanced as $key => $proc): ?>
            <?php include __DIR__ . '/includes/process_panel.php'; ?>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="footer">
        <span><?= strtoupper($orgName) ?> ELT CONTROL PANEL</span>
        <span id="footer-ts">—</span>
    </div>
</div>

<!-- ── Doc Editor Modal ────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="docModal" onclick="handleModalOverlayClick(event)">
    <div class="modal" id="docModalInner">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-eyebrow">✎ EDIT PROCESS DOCS</div>
                <div class="modal-title" id="modalTitle">—</div>
            </div>
            <button class="modal-close" onclick="closeDocEditor()">✕ CLOSE</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="modalProcessKey">

            <div class="field-group">
                <div class="field-label">What it does <span>Shown in the INFO drawer</span></div>
                <textarea class="field-textarea tall" id="modalWhat"
                          placeholder="Describe what this process does, what data it moves, and what depends on it."></textarea>
            </div>

            <div class="field-group">
                <div class="field-label">🕐 Scheduled <span>e.g. "Daily at 8:00 AM and 8:00 PM"</span></div>
                <input class="field-input" id="modalSchedule"
                       type="text" placeholder="Daily at …">
            </div>

            <div class="field-group">
                <div class="field-label">⏱ Expected duration <span>e.g. "Typically 45–60 seconds"</span></div>
                <input class="field-input" id="modalDuration"
                       type="text" placeholder="Typically … seconds / minutes">
            </div>

            <div class="field-group">
                <div class="field-label">When to run manually <span>Guidance for operators</span></div>
                <textarea class="field-textarea" id="modalWhen"
                          placeholder="Run manually when…"></textarea>
            </div>

            <div class="field-group">
                <div class="field-label">⚠ Warnings <span>Leave blank if none</span></div>
                <textarea class="field-textarea warning-field" id="modalWarnings"
                          placeholder="Any caveats, preconditions, or risks operators should know."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <div class="modal-footer-left" id="modalStatus">Changes are saved to SQL Server.</div>
            <div class="modal-footer-right">
                <button class="modal-btn modal-btn-cancel" onclick="closeDocEditor()">Cancel</button>
                <button class="modal-btn modal-btn-save" id="modalSaveBtn" onclick="saveDocEditor()">▶ Save</button>
            </div>
        </div>
    </div>
</div>

<script>
const PROCESSES = <?= json_encode(array_values(array_map(fn($p) => [
    'key'                  => $p['key'],
    'expected_seconds'     => $p['expected_seconds'],
    'poll_timeout_seconds' => $p['poll_timeout_seconds'],
    'step_thresholds'      => $p['step_thresholds'],
    'step_count'           => count($p['step_labels']),
    'advanced'             => $p['advanced'],
], $processes))) ?>;

// Docs data — pre-populated from PHP (SQL merge already applied)
<?php
$docsJson = json_encode($docsForJs, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
if (!$docsJson) $docsJson = '{}';
?>
const DOCS_DATA = <?= $docsJson ?>;

const state = {};

// ── Clock ─────────────────────────────────────────────────────────────────────
function tick() {
    document.getElementById('clock').textContent =
        new Date().toLocaleTimeString('en-US', { hour12: false });
}
setInterval(tick, 1000); tick();

// ── Easter egg — 5x click on RMG badge ───────────────────────────────────────
let badgeClicks = 0;
let badgeTimer  = null;

function handleBadgeClick() {
    badgeClicks++;
    clearTimeout(badgeTimer);
    badgeTimer = setTimeout(() => { badgeClicks = 0; }, 3000);
    if (badgeClicks >= 5) {
        badgeClicks = 0;
        toggleAdvanced();
    }
}

function toggleAdvanced() {
    const section  = document.getElementById('advancedSection');
    const badge    = document.getElementById('rmgBadge');
    const visible  = section.classList.toggle('visible');
    badge.classList.toggle('activated', visible);

    // Show/hide all edit-doc buttons across every panel
    document.querySelectorAll('.edit-doc-btn').forEach(btn => {
        btn.style.display = visible ? '' : 'none';
    });

    if (visible) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Docs toggle (INFO drawer) ─────────────────────────────────────────────────
function toggleDocs(key) {
    const drawer = document.getElementById('docs-' + key);
    const btn    = document.getElementById('infobtn-' + key);
    const open   = drawer.classList.toggle('open');
    btn.classList.toggle('active', open);
    btn.textContent = open ? '✕ CLOSE' : 'ⓘ INFO';
}

// ── Doc Editor Modal ──────────────────────────────────────────────────────────
function openDocEditor(key) {
    const data  = DOCS_DATA[key] || {};
    document.getElementById('modalProcessKey').value = key;
    document.getElementById('modalTitle').textContent = data.name || key.toUpperCase();
    document.getElementById('modalWhat').value      = data.what        || '';
    document.getElementById('modalSchedule').value  = data.schedule    || '';
    document.getElementById('modalDuration').value  = data.duration    || '';
    document.getElementById('modalWhen').value      = data.when_to_run || '';
    document.getElementById('modalWarnings').value  = data.warnings    || '';

    const status = document.getElementById('modalStatus');
    status.textContent = 'Changes are saved to SQL Server.';
    status.className   = 'modal-footer-left';

    document.getElementById('modalSaveBtn').disabled = false;
    document.getElementById('docModal').classList.add('open');
    document.getElementById('modalWhat').focus();
}

function closeDocEditor() {
    document.getElementById('docModal').classList.remove('open');
}

function handleModalOverlayClick(e) {
    if (e.target === document.getElementById('docModal')) closeDocEditor();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDocEditor();
});

function saveDocEditor() {
    const key     = document.getElementById('modalProcessKey').value;
    const saveBtn = document.getElementById('modalSaveBtn');
    const status  = document.getElementById('modalStatus');

    if (!key) return;

    const payload = new FormData();
    payload.append('process_key', key);
    payload.append('what',        document.getElementById('modalWhat').value.trim());
    payload.append('schedule',    document.getElementById('modalSchedule').value.trim());
    payload.append('duration',    document.getElementById('modalDuration').value.trim());
    payload.append('when_to_run', document.getElementById('modalWhen').value.trim());
    payload.append('warnings',    document.getElementById('modalWarnings').value.trim());

    saveBtn.disabled  = true;
    saveBtn.textContent = '…';
    status.textContent  = 'Saving…';
    status.className    = 'modal-footer-left';

    fetch('save_docs.php', { method: 'POST', body: payload, credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (data.error) throw new Error(data.error);

            // Update in-memory DOCS_DATA so re-opening modal shows new values
            if (DOCS_DATA[key]) {
                DOCS_DATA[key].what        = document.getElementById('modalWhat').value.trim();
                DOCS_DATA[key].schedule    = document.getElementById('modalSchedule').value.trim();
                DOCS_DATA[key].duration    = document.getElementById('modalDuration').value.trim();
                DOCS_DATA[key].when_to_run = document.getElementById('modalWhen').value.trim();
                DOCS_DATA[key].warnings    = document.getElementById('modalWarnings').value.trim();
            }

            // Patch the visible docs drawer live (no page reload needed)
            patchDocsDrawer(key, DOCS_DATA[key]);

            status.textContent = '✓ Saved by ' + (data.updated_by || '—');
            status.className   = 'modal-footer-left saved';
            saveBtn.textContent = '▶ Save';
            saveBtn.disabled    = false;

            // Auto-close after a beat
            setTimeout(() => closeDocEditor(), 1200);
        })
        .catch(err => {
            status.textContent = '✕ ' + err.message;
            status.className   = 'modal-footer-left error';
            saveBtn.textContent = '▶ Save';
            saveBtn.disabled    = false;
        });
}

function patchDocsDrawer(key, docs) {
    // Update visible text in the docs drawer without a reload
    const fields = {
        'doc-what-display-'      : docs.what,
        'doc-schedule-display-'  : docs.schedule,
        'doc-duration-display-'  : docs.duration,
        'doc-when-display-'      : docs.when_to_run,
        'doc-warnings-display-'  : docs.warnings,
    };
    Object.entries(fields).forEach(([prefix, val]) => {
        const el = document.getElementById(prefix + key);
        if (el && val !== undefined) el.textContent = val;
    });
}

// ── Trigger ───────────────────────────────────────────────────────────────────
function triggerProcess(key, mode) {
    const s = getState(key);
    if (s.running) return;

    s.running   = true;
    s.startTime = Date.now();
    s.mode      = mode;

    setButtons(key, true);
    resetProgress(key);
    showProgress(key, true);
    hideResult(key);

    const fd = new FormData();
    fd.append('process', key);
    fd.append('mode', mode);

    fetch('trigger.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (data.error) { finish(key, false, 'Failed to start: ' + data.error, 0); return; }
            const modeLabel = mode === 'test' ? ' [TEST]' : '';
            updateProgLabel(key, 'STARTING' + modeLabel + '...');
            startTimer(key);
            startPoll(key);
        })
        .catch(err => finish(key, false, 'Trigger failed: ' + err, 0));
}

// ── State ─────────────────────────────────────────────────────────────────────
function getState(key) {
    if (!state[key]) state[key] = { running: false, timer: null, poll: null, startTime: null };
    return state[key];
}

function setButtons(key, disabled) {
    ['btn-prod-' + key, 'btn-test-' + key].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = disabled;
    });
}

// ── Progress ──────────────────────────────────────────────────────────────────
function startTimer(key) {
    const proc = PROCESSES.find(p => p.key === key);
    const s    = getState(key);
    s.timer = setInterval(() => {
        const elapsed = (Date.now() - s.startTime) / 1000;
        const pct     = Math.min((elapsed / proc.expected_seconds) * 95, 95);
        document.getElementById('prog-fill-' + key).style.width = pct + '%';
        document.getElementById('prog-pct-' + key).textContent  = Math.round(pct) + '%';
        proc.step_thresholds.forEach((threshold, i) => {
            const next = proc.step_thresholds[i + 1] ?? 9999;
            const el   = document.getElementById('step-' + key + '-' + i);
            if (!el) return;
            if (elapsed >= next) { el.className = 'prog-step done'; el.querySelector('.si').textContent = '✓'; }
            else if (elapsed >= threshold) { el.className = 'prog-step active'; el.querySelector('.si').textContent = '►'; }
        });
    }, 500);
}

function updateProgLabel(key, label) {
    const el = document.getElementById('prog-label-' + key);
    if (el) el.textContent = label;
}

function showProgress(key, v) { document.getElementById('progress-' + key)?.classList.toggle('visible', v); }

function resetProgress(key) {
    const proc = PROCESSES.find(p => p.key === key);
    document.getElementById('prog-fill-' + key).style.width = '0%';
    document.getElementById('prog-pct-' + key).textContent  = '0%';
    for (let i = 0; i < proc.step_count; i++) {
        const el = document.getElementById('step-' + key + '-' + i);
        if (el) { el.className = 'prog-step'; el.querySelector('.si').textContent = '○'; }
    }
}

// ── Polling ───────────────────────────────────────────────────────────────────
function startPoll(key) {
    const s = getState(key);
    s.poll = setInterval(() => checkStatus(key), 3000);
}

function checkStatus(key) {
    const s     = getState(key);
    const since = Math.floor(s.startTime / 1000);
    const proc  = PROCESSES.find(p => p.key === key);

    const timeout = proc.poll_timeout_seconds ?? 300;
    if ((Date.now() - s.startTime) / 1000 > timeout) {
        clearState(key);
        finish(key, false,
            `Timed out after ${Math.round(timeout/60)} minutes — no response from ETL. ` +
            `Check Task Scheduler on your-web-server or ETL logs on your-etl-server.`, 0);
        return;
    }

    fetch('status.php?process=' + key + '&since=' + since, { credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (!data.status) return;
            if (data.status === 'Success') {
                clearState(key);
                snapComplete(key);
                const recs = parseInt(data.record_count) || 0;
                finish(key, true, durStr(data.start_time, data.end_time) + ' // ' + recs.toLocaleString() + ' records exported', recs);
                loadHistory(key);
            } else if (data.status === 'Failed') {
                clearState(key);
                finish(key, false, data.error_message || 'Unknown error', 0);
                loadHistory(key);
            }
        })
        .catch(() => {});
}

function snapComplete(key) {
    const proc = PROCESSES.find(p => p.key === key);
    document.getElementById('prog-fill-' + key).style.width = '100%';
    document.getElementById('prog-pct-' + key).textContent  = '100%';
    updateProgLabel(key, 'COMPLETE');
    for (let i = 0; i < proc.step_count; i++) {
        const el = document.getElementById('step-' + key + '-' + i);
        if (el) { el.className = 'prog-step done'; el.querySelector('.si').textContent = '✓'; }
    }
}

function clearState(key) {
    const s = getState(key);
    s.running = false;
    clearInterval(s.timer); s.timer = null;
    clearInterval(s.poll);  s.poll  = null;
    setButtons(key, false);
}

function finish(key, ok, detail, records) {
    clearState(key);
    const banner = document.getElementById('result-' + key);
    const title  = document.getElementById('result-title-' + key);
    const det    = document.getElementById('result-detail-' + key);
    banner.className = 'panel-result visible ' + (ok ? 'success' : 'failed');
    title.textContent = ok ? '✓  RUN COMPLETE' : '✕  RUN FAILED';
    det.textContent   = detail;
    updateStatusBadge(key, ok ? 'Success' : 'Failed');
}

function hideResult(key) { document.getElementById('result-' + key).className = 'panel-result'; }

// ── Status badge ──────────────────────────────────────────────────────────────
function updateStatusBadge(key, status, lastRun) {
    const badge     = document.getElementById('badge-' + key);
    const badgeText = document.getElementById('badge-text-' + key);
    const lastRunEl = document.getElementById('last-run-' + key);
    const map = { Success: 'success', Failed: 'failed', Started: 'started', Running: 'running' };
    badge.className = 'badge ' + (map[status] ?? '');
    badgeText.textContent = status.toUpperCase();
    if (lastRun && lastRunEl) lastRunEl.textContent = '// last: ' + lastRun;
}

// ── History ───────────────────────────────────────────────────────────────────
function toggleHistory(key) {
    const wrap   = document.getElementById('history-' + key);
    const toggle = document.getElementById('htoggle-' + key);
    const isOpen = wrap.classList.toggle('open');
    toggle.classList.toggle('open', isOpen);
    if (isOpen) loadHistory(key);
}

function loadHistory(key) {
    fetch('status.php?process=' + key + '&history=1', { credentials: 'include' })
        .then(r => r.json())
        .then(data => {
            if (!data.runs) return;
            renderRuns(key, data.runs);
            const latest = data.runs.find(r => r.status === 'Success' || r.status === 'Failed');
            if (latest) {
                const d = new Date(latest.start_time);
                const fmt = d.toLocaleDateString('en-US', { month:'short', day:'numeric' })
                          + ' ' + d.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit', hour12:false });
                updateStatusBadge(key, latest.status, fmt);
            }
            document.getElementById('footer-ts').textContent =
                'LAST REFRESHED // ' + new Date().toLocaleTimeString('en-US', { hour12: false });
        });
}

function renderRuns(key, runs) {
    const body = document.getElementById('runs-' + key);
    if (!runs.length) {
        body.innerHTML = '<tr><td colspan="6" style="color:var(--text-dim);padding:16px;">No runs found.</td></tr>';
        return;
    }
    body.innerHTML = runs.map(r => {
        const cls = { Success:'success', Failed:'failed', Started:'started' }[r.status] ?? 'running';
        const dur = (r.start_time && r.end_time)
            ? Math.round((new Date(r.end_time) - new Date(r.start_time)) / 1000) + 's' : '—';
        const recs = r.record_count ? parseInt(r.record_count).toLocaleString() : '—';
        const err  = r.error_message
            ? `<span title="${esc(r.error_message)}" style="color:var(--red);cursor:help">${esc(r.error_message.substring(0,45))}${r.error_message.length > 45 ? '…' : ''}</span>`
            : '—';
        const startFmt = r.start_time
            ? new Date(r.start_time).toLocaleString('en-US', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false })
            : '—';
        const endFmt = r.end_time
            ? new Date(r.end_time).toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false })
            : '—';
        return `<tr>
            <td><span class="badge ${cls}"><span class="dot"></span>${esc(r.status.toUpperCase())}</span></td>
            <td>${startFmt}</td><td>${endFmt}</td>
            <td>${dur}</td><td>${recs}</td><td>${err}</td>
        </tr>`;
    }).join('');
}

function durStr(start, end) {
    if (!start || !end) return '—';
    return Math.round((new Date(end) - new Date(start)) / 1000) + 's';
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────────
PROCESSES.forEach(p => loadHistory(p.key));
</script>
</body>
</html>