# start-local.ps1
# ─────────────────────────────────────────────────────────────────────────────
# Launches the ETL Control Panel locally using PHP's built-in web server.
# No IIS, no Windows Auth, no WinRM needed.
#
# Prerequisites:
#   - PHP 8.x installed and in PATH (php --version to verify)
#   - config/app.php exists with mode = 'local'
#   - config/credentials.php exists with SQL Server credentials
#   - SQL Server accessible at the host configured in app.php
#
# Usage:
#   .\start-local.ps1
#   .\start-local.ps1 -Port 9000
# ─────────────────────────────────────────────────────────────────────────────

param(
    [int]   $Port = 8080,
    [string]$Host = 'localhost'
)

$appDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$appFile = Join-Path $appDir 'config/app.php'
$credFile = Join-Path $appDir 'config/credentials.php'

# ── Pre-flight checks ─────────────────────────────────────────────────────────
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Error "PHP not found in PATH. Install PHP 8.x and add it to your PATH."
    exit 1
}

if (-not (Test-Path $appFile)) {
    Write-Warning "config/app.php not found."
    Write-Host "  Copy config/app.example.php to config/app.php and fill in your values."
    exit 1
}

if (-not (Test-Path $credFile)) {
    Write-Warning "config/credentials.php not found."
    Write-Host "  Copy config/credentials.example.php to config/credentials.php and fill in your values."
    exit 1
}

# ── Check mode ────────────────────────────────────────────────────────────────
$appContent = Get-Content $appFile -Raw
if ($appContent -notmatch "'mode'\s*=>\s*'local'") {
    Write-Warning "app.php mode is not set to 'local'."
    Write-Host "  Set 'mode' => 'local' in config/app.php for local development."
}

# ── Launch ────────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "Starting ETL Control Panel..." -ForegroundColor Cyan
Write-Host "  URL:  http://$Host`:$Port" -ForegroundColor Green
Write-Host "  Mode: local (no IIS/WinRM required)" -ForegroundColor Green
Write-Host "  Press Ctrl+C to stop" -ForegroundColor Yellow
Write-Host ""

Set-Location $appDir
php -S "$Host`:$Port" -t $appDir
