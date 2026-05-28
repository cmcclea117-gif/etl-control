<?php
// ── includes/process_panel.php ────────────────────────────────────────────────
$isAdvanced = $proc['advanced'] ?? false;
$hasDocs    = !empty($proc['docs']);
$hasReports = !empty($proc['reports']);
$isFromDb   = $proc['_from_db'] ?? false;
?>
<div class="process-panel<?= $isAdvanced ? ' advanced-panel' : '' ?>" id="panel-<?= $key ?>">
    <div class="panel-top">
        <div class="panel-meta">
            <div class="panel-key"><?= htmlspecialchars($key) ?> // <?= htmlspecialchars($proc['log_process_name']) ?></div>
            <div class="panel-name-row">
                <div class="panel-name<?= $isAdvanced ? ' advanced-name' : '' ?>">
                    <?= htmlspecialchars($proc['name']) ?>
                </div>
                <?php if ($hasDocs || $hasReports): ?>
                <button class="info-btn" id="infobtn-<?= $key ?>"
                        onclick="toggleDocs('<?= $key ?>')">ⓘ INFO</button>
                <?php endif; ?>
                <?php if ($isFromDb): ?>
                <a href="add_process.php?edit=<?= urlencode($key) ?>"
                   class="info-btn">✎ EDIT</a>
                <?php endif; ?>
                <?php if (!empty($proc['remote_server']) || !empty($proc['ssis_server']) || !empty($proc['agent_server'])): ?>
                <a href="generate_wrapper.php?process=<?= urlencode($key) ?>"
                   class="info-btn"
                   title="Download pre-filled WinRM wrapper for production deployment"
                   style="color:var(--teal);border-color:#134e4a">
                   ↓ WRAPPER</a>
                <?php endif; ?>
                <?php
                $hasSrc = !empty($proc['source_type']) && $proc['source_type'] !== 'none';
                $hasDst = !empty($proc['dest_type'])   && $proc['dest_type']   !== 'none';
                if ($hasSrc || $hasDst): ?>
                <a href="generate_script.php?process=<?= urlencode($key) ?>&download=1"
                   class="info-btn"
                   title="Download generated ETL script for this source/destination"
                   style="color:var(--purple);border-color:#4c1d95">
                   ↓ SCRIPT</a>
                <?php endif; ?>
                <button class="info-btn edit-doc-btn" id="editbtn-<?= $key ?>"
                        onclick="openDocEditor('<?= $key ?>')"
                        title="Edit documentation for this process"
                        style="display:none">✎ EDIT DOCS</button>
            </div>
            <div class="panel-desc"><?= htmlspecialchars($proc['description']) ?></div>
            <div class="panel-status" id="status-<?= $key ?>">
                <span class="badge" id="badge-<?= $key ?>">
                    <span class="dot"></span>
                    <span id="badge-text-<?= $key ?>">LOADING...</span>
                </span>
                <span style="font-family:var(--mono);font-size:11px;color:var(--text-dim)"
                      id="last-run-<?= $key ?>"></span>
            </div>
        </div>
        <?php if ($proc['trigger'] ?? true): ?>
        <div class="btn-group">
            <?php if (!empty($proc['test_args'])): ?>
            <button class="btn btn-test" id="btn-test-<?= $key ?>"
                    onclick="triggerProcess('<?= $key ?>', 'test')"
                    title="<?= htmlspecialchars($proc['test_args']) ?>">
                ⚡ Test
            </button>
            <?php endif; ?>
            <button class="btn btn-prod<?= $isAdvanced ? ' danger' : '' ?>"
                    id="btn-prod-<?= $key ?>"
                    onclick="triggerProcess('<?= $key ?>', 'prod')">
                <?= $isAdvanced ? '⚠ Run' : '▶ Run' ?>
            </button>
        </div>
        <?php else: ?>
        <div style="font-family:var(--mono);font-size:10px;color:var(--text-dim);
                    border:1px solid var(--border);padding:6px 14px;letter-spacing:.1em;
                    text-transform:uppercase;align-self:center">
            ⟳ Auto
        </div>
        <?php endif; ?>
    </div>

    <?php if ($hasDocs || $hasReports): ?>
    <div class="docs-drawer" id="docs-<?= $key ?>">
        <div class="docs-grid">

            <?php if (!empty($proc['docs']['what'])): ?>
            <div class="docs-item full">
                <div class="docs-label">What it does</div>
                <div class="docs-text" id="doc-what-display-<?= $key ?>"><?= htmlspecialchars($proc['docs']['what']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($proc['docs']['schedule'])): ?>
            <div class="docs-item">
                <div class="docs-label">🕐 Scheduled</div>
                <div class="docs-text" id="doc-schedule-display-<?= $key ?>"><?= htmlspecialchars($proc['docs']['schedule']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($proc['docs']['duration'])): ?>
            <div class="docs-item">
                <div class="docs-label">⏱ Expected duration</div>
                <div class="docs-text" id="doc-duration-display-<?= $key ?>"><?= htmlspecialchars($proc['docs']['duration']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($proc['docs']['when'])): ?>
            <div class="docs-item full">
                <div class="docs-label">When to run manually</div>
                <div class="docs-text" id="doc-when-display-<?= $key ?>"><?= htmlspecialchars($proc['docs']['when']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($proc['docs']['warnings'])): ?>
            <div class="docs-item full">
                <div class="docs-label">⚠ Warnings</div>
                <div class="docs-warning" id="doc-warnings-display-<?= $key ?>"><?= htmlspecialchars($proc['docs']['warnings']) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($hasReports): ?>
            <div class="docs-item full">
                <div class="docs-label">📊 Impacted Reports</div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                    <?php foreach ($proc['reports'] as $report): ?>
                    <a href="<?= htmlspecialchars($report['url']) ?>" target="_blank"
                       style="font-family:var(--mono);font-size:11px;color:var(--blue);
                              border:1px solid #1e4060;padding:5px 14px;text-decoration:none;
                              background:rgba(56,189,248,0.06);letter-spacing:0.05em;
                              transition:border-color 0.15s,background 0.15s;"
                       onmouseover="this.style.borderColor='var(--blue)';this.style.background='rgba(56,189,248,0.12)'"
                       onmouseout="this.style.borderColor='#1e4060';this.style.background='rgba(56,189,248,0.06)'">
                        ↗ <?= htmlspecialchars($report['name']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

    <!-- Progress -->
    <div class="panel-progress" id="progress-<?= $key ?>">
        <div class="prog-header">
            <span class="prog-label" id="prog-label-<?= $key ?>">INITIALIZING...</span>
            <span class="prog-pct"   id="prog-pct-<?= $key ?>">0%</span>
        </div>
        <div class="prog-track">
            <div class="prog-fill" id="prog-fill-<?= $key ?>"></div>
        </div>
        <div class="prog-steps">
            <?php foreach ($proc['step_labels'] as $i => $label): ?>
            <div class="prog-step" id="step-<?= $key ?>-<?= $i ?>">
                <span class="si">○</span>
                <?= htmlspecialchars($label) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Result -->
    <div class="panel-result" id="result-<?= $key ?>">
        <div class="result-title"  id="result-title-<?= $key ?>"></div>
        <div class="result-detail" id="result-detail-<?= $key ?>"></div>
    </div>

    <!-- History -->
    <div class="panel-history">
        <button class="history-toggle" id="htoggle-<?= $key ?>"
                onclick="toggleHistory('<?= $key ?>')">
            <span class="arrow">▼</span>
            Recent Runs
        </button>
        <div class="history-table-wrap" id="history-<?= $key ?>">
            <table>
                <thead>
                    <tr><th>Status</th><th>Start</th><th>End</th><th>Duration</th><th>Records</th><th>Error</th></tr>
                </thead>
                <tbody id="runs-<?= $key ?>">
                    <tr><td colspan="6" style="color:var(--text-dim);padding:16px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>