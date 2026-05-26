<?php
$currentTab = 'etl';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
$config   = require __DIR__ . '/config/app.php';
$auth     = getAuthUser();
$badge    = htmlspecialchars($config['badge']    ?? 'ETL');
$orgName  = htmlspecialchars($config['org_name'] ?? 'ETL Control Panel');

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    $key = trim($_POST['process_key'] ?? '');
    if ($key) {
        $conn = getDbConnection();
        $conn->prepare('DELETE FROM ETL_Processes WHERE process_key = ?')->execute([$key]);
    }
    header('Location: index.php');
    exit;
}

// Load existing process for editing
$editing = null;
$editKey = trim($_GET['edit'] ?? '');
if ($editKey) {
    $conn = getDbConnection();
    $row  = $conn->prepare('SELECT * FROM ETL_Processes WHERE process_key = ?');
    $row->execute([$editKey]);
    $editing = $row->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $orgName ?> // <?= $editing ? 'Edit' : 'Add' ?> Process</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Barlow+Condensed:wght@300;400;600;700&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#07090d;--bg-panel:#0d1117;--bg-card:#111827;--border:#1e2d40;--border-lit:#2a4060;--amber:#e07b0f;--amber-dim:#7a4208;--amber-glow:rgba(224,123,15,0.15);--green:#22c55e;--green-dim:#14532d;--red:#ef4444;--red-dim:#7f1d1d;--blue:#38bdf8;--blue-dim:#0c4a6e;--teal:#2dd4bf;--orange:#f97316;--text:#c9d4e0;--text-dim:#4a6080;--text-label:#6b8aaa;--mono:'Share Tech Mono',monospace;--sans:'Barlow',sans-serif;--cond:'Barlow Condensed',sans-serif}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.07) 2px,rgba(0,0,0,0.07) 4px);pointer-events:none;z-index:9999}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:10px 24px;background:var(--bg-panel);border-bottom:1px solid var(--border);position:fixed;top:0;left:0;right:0;z-index:100;height:48px}
.topbar-left{display:flex;align-items:center;gap:14px}
.rmg-badge{font-family:var(--cond);font-weight:700;font-size:12px;letter-spacing:.15em;color:var(--amber);background:var(--amber-glow);border:1px solid var(--amber-dim);padding:3px 9px;text-transform:uppercase}
.topbar-title{font-family:var(--cond);font-weight:300;font-size:13px;letter-spacing:.2em;color:var(--text-label);text-transform:uppercase}
.topbar-right{font-family:var(--mono);font-size:11px;color:var(--text-dim);display:flex;align-items:center;gap:8px}
.user-dot{width:6px;height:6px;background:var(--green);border-radius:50%;box-shadow:0 0 6px var(--green);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.main{max-width:860px;margin:0 auto;padding:72px 24px 48px}
.page-header{margin-bottom:32px}
.page-header h1{font-family:var(--cond);font-weight:700;font-size:28px;letter-spacing:.08em;text-transform:uppercase;color:#fff}
.page-header h1 span{color:var(--amber)}
.back-link{font-family:var(--mono);font-size:11px;color:var(--text-dim);text-decoration:none;letter-spacing:.1em;text-transform:uppercase;display:inline-flex;align-items:center;gap:6px;margin-bottom:16px}
.back-link:hover{color:var(--text)}
.form-card{background:var(--bg-card);border:1px solid var(--border);padding:28px;position:relative}
.form-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--amber),transparent)}
.section-label{font-family:var(--mono);font-size:10px;letter-spacing:.2em;color:var(--text-dim);text-transform:uppercase;margin:24px 0 14px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.section-label:first-child{margin-top:0}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid.full{grid-template-columns:1fr}
.field{display:flex;flex-direction:column;gap:5px}
.field.full{grid-column:1/-1}
.field label{font-family:var(--mono);font-size:10px;letter-spacing:.15em;color:var(--text-label);text-transform:uppercase}
.field label .req{color:var(--amber)}
.field input,.field select,.field textarea{background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:var(--mono);font-size:12px;padding:7px 10px;outline:none;letter-spacing:.03em;transition:border-color .15s}
.field input:focus,.field select,.field textarea:focus{border-color:var(--blue)}
.field select{cursor:pointer}
.field textarea{resize:vertical;min-height:60px;line-height:1.5}
.field .hint{font-family:var(--mono);font-size:10px;color:var(--text-dim);margin-top:2px}
.exec-section{display:none}
.exec-section.active{display:contents}
.btn-row{display:flex;gap:12px;margin-top:28px;align-items:center}
.btn{font-family:var(--cond);font-weight:700;font-size:13px;letter-spacing:.15em;text-transform:uppercase;border:none;padding:11px 28px;cursor:pointer;transition:all .15s}
.btn-primary{color:var(--bg);background:var(--amber)}
.btn-primary:hover{box-shadow:0 0 20px var(--amber-glow)}
.btn-secondary{color:var(--text-dim);background:transparent;border:1px solid var(--border)}
.btn-secondary:hover{color:var(--text);border-color:var(--border-lit)}
.btn-danger{color:var(--red);background:transparent;border:1px solid var(--red-dim);margin-left:auto}
.btn-danger:hover{background:rgba(239,68,68,.08)}
.alert{padding:12px 16px;border:1px solid;font-family:var(--mono);font-size:12px;margin-bottom:20px}
.alert.success{border-color:var(--green-dim);background:rgba(34,197,94,.06);color:var(--green)}
.alert.error{border-color:var(--red-dim);background:rgba(239,68,68,.06);color:var(--red)}
</style>
</head>
<body>
<div class="topbar">
    <div class="topbar-left">
        <div class="rmg-badge"><?= $badge ?></div>
        <div class="topbar-title"><?= $orgName ?> // <?= $editing ? 'Edit' : 'Add' ?> Process</div>
    </div>
    <div class="topbar-right">
        <div class="user-dot"></div>
        <span><?= htmlspecialchars(strtoupper($auth['user'])) ?></span>
    </div>
</div>

<div class="main">
    <a href="index.php" class="back-link">← Back to Dashboard</a>

    <div class="page-header">
        <h1><?= $editing ? 'Edit' : 'Add New' ?> <span>ETL Process</span></h1>
    </div>

    <div id="alertBox"></div>

    <div class="form-card">
        <form id="processForm">
            <?php if ($editing): ?>
            <input type="hidden" name="process_key" value="<?= htmlspecialchars($editing['process_key']) ?>">
            <?php endif; ?>

            <!-- ── Basic Info ── -->
            <div class="section-label">Basic Info</div>
            <div class="form-grid">
                <div class="field">
                    <label>Process Name <span class="req">*</span></label>
                    <input type="text" name="name" id="nameInput" required
                           placeholder="e.g. Nightly Sales Sync"
                           value="<?= htmlspecialchars($editing['name'] ?? '') ?>">
                    <span class="hint">Displayed on the dashboard card</span>
                </div>
                <div class="field">
                    <label>Process Key <span class="req">*</span></label>
                    <input type="text" name="process_key" id="keyInput" required
                           placeholder="e.g. nightlysalessync"
                           pattern="[a-z0-9_]+"
                           <?= $editing ? 'readonly style="opacity:.5"' : '' ?>
                           value="<?= htmlspecialchars($editing['process_key'] ?? '') ?>">
                    <span class="hint">Lowercase letters/numbers/underscores only. Auto-filled from name.</span>
                </div>
                <div class="field full">
                    <label>Description</label>
                    <input type="text" name="description"
                           placeholder="Short description shown on the panel"
                           value="<?= htmlspecialchars($editing['description'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Log Process Name <span class="req">*</span></label>
                    <input type="text" name="log_process_name" id="logNameInput" required
                           placeholder="e.g. Nightly Sales Sync"
                           value="<?= htmlspecialchars($editing['log_process_name'] ?? '') ?>">
                    <span class="hint">Must exactly match what your script logs to ETL_Sync_Log</span>
                </div>
                <div class="field">
                    <label>Execution Type <span class="req">*</span></label>
                    <select name="exec_type" id="execTypeSelect" onchange="switchExecType(this.value)">
                        <option value="powershell" <?= ($editing['exec_type'] ?? 'powershell') === 'powershell' ? 'selected' : '' ?>>PowerShell Script</option>
                        <option value="python"     <?= ($editing['exec_type'] ?? '') === 'python'     ? 'selected' : '' ?>>Python Script</option>
                        <option value="ssis"       <?= ($editing['exec_type'] ?? '') === 'ssis'       ? 'selected' : '' ?>>SSIS Package</option>
                        <option value="sqlagent"   <?= ($editing['exec_type'] ?? '') === 'sqlagent'   ? 'selected' : '' ?>>SQL Agent Job</option>
                        <option value="cmd"        <?= ($editing['exec_type'] ?? '') === 'cmd'        ? 'selected' : '' ?>>Command / Executable</option>
                    </select>
                </div>
            </div>

            <!-- ── PowerShell fields ── -->
            <div class="section-label exec-fields" id="fields-powershell">PowerShell Configuration</div>
            <div class="form-grid exec-fields" id="grid-powershell">
                <div class="field">
                    <label>Remote Server</label>
                    <input type="text" name="remote_server" placeholder="your-sql-server"
                           value="<?= htmlspecialchars($editing['remote_server'] ?? $config['winrm_server'] ?? '') ?>">
                </div>
                <div class="field full">
                    <label>Remote Script Path</label>
                    <input type="text" name="remote_script"
                           placeholder="C:\Scripts\Invoke-MyProcess.ps1"
                           value="<?= htmlspecialchars($editing['remote_script'] ?? '') ?>">
                    <span class="hint">Full path to .ps1 on the remote server</span>
                </div>
                <div class="field full">
                    <label>Local Script Path <span style="color:var(--text-dim)">(local mode only)</span></label>
                    <input type="text" name="local_script"
                           placeholder="scripts/Invoke-MyProcess.ps1"
                           value="<?= htmlspecialchars($editing['local_script'] ?? '') ?>">
                    <span class="hint">Relative or absolute path for local dev mode</span>
                </div>
            </div>

            <!-- ── Python fields ── -->
            <div class="section-label exec-fields" id="fields-python" style="display:none">Python Configuration</div>
            <div class="form-grid exec-fields" id="grid-python" style="display:none">
                <div class="field">
                    <label>Remote Server</label>
                    <input type="text" name="py_remote_server" placeholder="your-sql-server"
                           value="<?= htmlspecialchars($editing['remote_server'] ?? $config['winrm_server'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Python Executable</label>
                    <input type="text" name="python_exe" placeholder="python.exe"
                           value="<?= htmlspecialchars($editing['python_exe'] ?? 'python.exe') ?>">
                </div>
                <div class="field full">
                    <label>Script Path</label>
                    <input type="text" name="py_remote_script" placeholder="C:\Scripts\my_etl.py"
                           value="<?= htmlspecialchars($editing['remote_script'] ?? '') ?>">
                </div>
            </div>

            <!-- ── SSIS fields ── -->
            <div class="section-label exec-fields" id="fields-ssis" style="display:none">SSIS Configuration</div>
            <div class="form-grid exec-fields" id="grid-ssis" style="display:none">
                <div class="field">
                    <label>SSIS Server</label>
                    <input type="text" name="ssis_server" placeholder="your-sql-server"
                           value="<?= htmlspecialchars($editing['ssis_server'] ?? $config['winrm_server'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Catalog</label>
                    <input type="text" name="ssis_catalog" placeholder="SSISDB"
                           value="<?= htmlspecialchars($editing['ssis_catalog'] ?? 'SSISDB') ?>">
                </div>
                <div class="field">
                    <label>Folder</label>
                    <input type="text" name="ssis_folder" placeholder="MyFolder"
                           value="<?= htmlspecialchars($editing['ssis_folder'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Project</label>
                    <input type="text" name="ssis_project" placeholder="MyProject"
                           value="<?= htmlspecialchars($editing['ssis_project'] ?? '') ?>">
                </div>
                <div class="field full">
                    <label>Package</label>
                    <input type="text" name="ssis_package" placeholder="MyPackage.dtsx"
                           value="<?= htmlspecialchars($editing['ssis_package'] ?? '') ?>">
                </div>
            </div>

            <!-- ── SQL Agent fields ── -->
            <div class="section-label exec-fields" id="fields-sqlagent" style="display:none">SQL Agent Configuration</div>
            <div class="form-grid exec-fields" id="grid-sqlagent" style="display:none">
                <div class="field">
                    <label>SQL Server</label>
                    <input type="text" name="agent_server" placeholder="your-sql-server"
                           value="<?= htmlspecialchars($editing['agent_server'] ?? $config['winrm_server'] ?? '') ?>">
                </div>
                <div class="field full">
                    <label>Job Name</label>
                    <input type="text" name="agent_job" placeholder="My Nightly Sync Job"
                           value="<?= htmlspecialchars($editing['agent_job'] ?? '') ?>">
                    <span class="hint">Exact name as it appears in SQL Server Agent</span>
                </div>
            </div>

            <!-- ── CMD fields ── -->
            <div class="section-label exec-fields" id="fields-cmd" style="display:none">Command Configuration</div>
            <div class="form-grid exec-fields" id="grid-cmd" style="display:none">
                <div class="field">
                    <label>Remote Server</label>
                    <input type="text" name="cmd_remote_server" placeholder="your-sql-server"
                           value="<?= htmlspecialchars($editing['remote_server'] ?? $config['winrm_server'] ?? '') ?>">
                </div>
                <div class="field full">
                    <label>Command</label>
                    <input type="text" name="remote_cmd"
                           placeholder='C:\Tools\myetl.exe --config C:\Config\prod.json'
                           value="<?= htmlspecialchars($editing['remote_cmd'] ?? '') ?>">
                    <span class="hint">Full command string to execute on the remote server</span>
                </div>
            </div>

            <!-- ── Run args ── -->
            <div class="section-label">Run Arguments</div>
            <div class="form-grid">
                <div class="field">
                    <label>Production Args</label>
                    <input type="text" name="prod_args" placeholder=""
                           value="<?= htmlspecialchars($editing['prod_args'] ?? '') ?>">
                    <span class="hint">Passed when ▶ Run is clicked (leave blank for none)</span>
                </div>
                <div class="field">
                    <label>Test Args</label>
                    <input type="text" name="test_args" placeholder="-TestMode"
                           value="<?= htmlspecialchars($editing['test_args'] ?? '-TestMode') ?>">
                    <span class="hint">Passed when ⚡ Test is clicked. Leave blank to hide Test button.</span>
                </div>
                <div class="field">
                    <label>Task Name (production)</label>
                    <input type="text" name="task_name" id="taskNameInput"
                           placeholder="MyProcess-OnDemand"
                           value="<?= htmlspecialchars($editing['task_name'] ?? '') ?>">
                    <span class="hint">Windows Task Scheduler task name (auto-filled)</span>
                </div>
            </div>

            <!-- ── Progress bar ── -->
            <div class="section-label">Progress Bar</div>
            <div class="form-grid">
                <div class="field">
                    <label>Expected Duration (seconds)</label>
                    <input type="number" name="expected_seconds" min="5" max="7200"
                           value="<?= htmlspecialchars($editing['expected_seconds'] ?? '60') ?>">
                </div>
                <div class="field">
                    <label>Poll Timeout (seconds)</label>
                    <input type="number" name="poll_timeout_secs" min="30" max="86400"
                           value="<?= htmlspecialchars($editing['poll_timeout_secs'] ?? '300') ?>">
                </div>
                <div class="field full">
                    <label>Step Labels</label>
                    <textarea name="step_labels" rows="4"
                              placeholder="One step per line:&#10;Connecting&#10;Extracting data&#10;Loading to target&#10;Completing"><?= htmlspecialchars(implode("\n", json_decode($editing['step_labels'] ?? '["Initializing","Processing","Completing"]', true))) ?></textarea>
                    <span class="hint">One label per line. Thresholds are auto-calculated evenly.</span>
                </div>
            </div>

            <!-- ── Power BI Report ── -->
            <div class="section-label">Power BI Report (optional)</div>
            <div class="form-grid">
                <div class="field">
                    <label>Report Name</label>
                    <input type="text" name="report_name" placeholder="My Dashboard"
                           value="<?= htmlspecialchars($editing['report_name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Report URL</label>
                    <input type="text" name="report_url" placeholder="https://app.powerbi.com/..."
                           value="<?= htmlspecialchars($editing['report_url'] ?? '') ?>">
                </div>
            </div>

            <!-- ── Documentation ── -->
            <div class="section-label">Documentation (shown in ⓘ Info panel)</div>
            <div class="form-grid full">
                <div class="field full">
                    <label>What it does</label>
                    <textarea name="doc_what" rows="3"
                              placeholder="Describe what this process does..."><?= htmlspecialchars($editing['doc_what'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label>Schedule</label>
                    <input type="text" name="doc_schedule" placeholder="Daily at 6:00 AM"
                           value="<?= htmlspecialchars($editing['doc_schedule'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Expected Duration</label>
                    <input type="text" name="doc_duration" placeholder="Typically 30-60 seconds"
                           value="<?= htmlspecialchars($editing['doc_duration'] ?? '') ?>">
                </div>
                <div class="field full">
                    <label>When to run manually</label>
                    <textarea name="doc_when" rows="2"
                              placeholder="Run manually when..."><?= htmlspecialchars($editing['doc_when'] ?? '') ?></textarea>
                </div>
                <div class="field full">
                    <label>Warnings</label>
                    <textarea name="doc_warnings" rows="2"
                              placeholder="Any warnings or cautions..."><?= htmlspecialchars($editing['doc_warnings'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- ── Advanced ── -->
            <div class="section-label">Settings</div>
            <div class="form-grid">
                <div class="field">
                    <label>
                        <input type="checkbox" name="advanced" value="1"
                               <?= ($editing['advanced'] ?? 0) ? 'checked' : '' ?>
                               style="width:auto;margin-right:6px">
                        Advanced (hidden behind easter egg)
                    </label>
                    <span class="hint">Advanced processes are resource-intensive and hidden by default</span>
                </div>
            </div>

            <div class="btn-row">
                <button type="button" class="btn btn-primary" onclick="submitForm()">
                    <?= $editing ? '✓ Save Changes' : '+ Add Process' ?>
                </button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <?php if ($editing): ?>
                <button type="button" class="btn btn-danger"
                        onclick="deleteProcess('<?= htmlspecialchars($editing['process_key']) ?>')">
                    ✕ Delete Process
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
// ── Auto-fill key and task name from process name ─────────────────────────────
document.getElementById('nameInput').addEventListener('input', function() {
    <?php if (!$editing): ?>
    const key      = this.value.toLowerCase().replace(/[^a-z0-9]/g, '');
    const taskName = key.charAt(0).toUpperCase() + key.slice(1) + '-OnDemand';
    document.getElementById('keyInput').value      = key;
    document.getElementById('logNameInput').value  = this.value;
    document.getElementById('taskNameInput').value = taskName;
    <?php endif; ?>
});

// ── Show/hide exec type fields ────────────────────────────────────────────────
function switchExecType(type) {
    const types = ['powershell', 'python', 'ssis', 'sqlagent', 'cmd'];
    types.forEach(t => {
        const label = document.getElementById('fields-' + t);
        const grid  = document.getElementById('grid-' + t);
        const show  = t === type;
        if (label) label.style.display = show ? '' : 'none';
        if (grid)  grid.style.display  = show ? '' : 'none';
    });
}
switchExecType(document.getElementById('execTypeSelect').value);

// ── Submit ────────────────────────────────────────────────────────────────────
function submitForm() {
    const form = document.getElementById('processForm');
    const data = new FormData(form);
    const alertBox = document.getElementById('alertBox');

    fetch('save_process.php', { method: 'POST', body: data, credentials: 'include' })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                alertBox.innerHTML = '<div class="alert success">✓ Process saved. Redirecting...</div>';
                setTimeout(() => window.location.href = 'index.php', 1000);
            } else {
                alertBox.innerHTML = '<div class="alert error">✕ ' + (res.error || 'Save failed') + '</div>';
                window.scrollTo(0, 0);
            }
        })
        .catch(err => {
            alertBox.innerHTML = '<div class="alert error">✕ Request failed: ' + err + '</div>';
            window.scrollTo(0, 0);
        });
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteProcess(key) {
    if (!confirm('Delete this process? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('_action', 'delete');
    fd.append('process_key', key);
    fetch('add_process.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(() => window.location.href = 'index.php');
}
</script>
</body>
</html>
