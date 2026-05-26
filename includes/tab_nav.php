<?php
$currentTab = $currentTab ?? 'etl';
?>
<style>
.tab-nav {
    display: flex;
    align-items: stretch;
    background: var(--bg-panel);
    border-bottom: 1px solid var(--border);
    position: fixed;
    top: 48px;
    left: 0;
    right: 0;
    z-index: 97;
    height: 36px;
    padding: 0 24px;
}
.tab-link {
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    text-decoration: none;
    color: var(--text-dim);
    padding: 0 18px;
    display: flex;
    align-items: center;
    border-bottom: 2px solid transparent;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.tab-link:hover { color: var(--text); }
.tab-link.active {
    color: var(--amber);
    border-bottom-color: var(--amber);
}
.tab-link .tab-icon { margin-right: 7px; font-size: 12px; opacity: .7; }
</style>

<nav class="tab-nav">
    <a href="index.php" class="tab-link <?= $currentTab === 'etl' ? 'active' : '' ?>">
        <span class="tab-icon">⚡</span>ETL Processes
    </a>
    <a href="dependency-chain.php" class="tab-link <?= $currentTab === 'depchain' ? 'active' : '' ?>">
        <span class="tab-icon">◈</span>Dependency Chain
    </a>
</nav>