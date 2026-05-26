#Requires -Version 5.1
<#
.SYNOPSIS
    Registers all ETL Control Panel on-demand scheduled tasks on the web server.
    Run this script ONCE on the web server as Administrator after deploying
    your Invoke-*Remote.ps1 wrapper files.

.PARAMETER WrapperRoot
    Directory where Invoke-*Remote.ps1 wrappers are deployed.
    Default: C:\inetpub\wwwroot\etl-control

.PARAMETER TaskFolder
    Windows Task Scheduler folder to register tasks under.
    Default: ETL  (tasks appear as \ETL\ProcessName-OnDemand)

.PARAMETER ServiceAccount
    Domain\User account that runs the tasks.
    Prompted interactively if not supplied.

.NOTES
    Prerequisites:
    - All Invoke-*Remote.ps1 wrappers must exist in WrapperRoot
    - Run as Administrator
    - Service account must have Read/Execute on WrapperRoot
#>

param(
    [string]$WrapperRoot    = 'C:\inetpub\wwwroot\etl-control',
    [string]$TaskFolder     = 'ETL',
    [string]$ServiceAccount = ''
)

$ErrorActionPreference = 'Stop'
$taskPath = '\' + $TaskFolder.Trim('\') + '\'
$ps       = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'

# Prompt for credentials if not supplied
if (-not $ServiceAccount) {
    $cred           = Get-Credential -Message 'Enter the service account that will run ETL tasks'
    $ServiceAccount = $cred.UserName
    $password       = $cred.GetNetworkCredential().Password
} else {
    $cred     = Get-Credential -UserName $ServiceAccount -Message "Enter password for $ServiceAccount"
    $password = $cred.GetNetworkCredential().Password
}

# Common task settings — one-time trigger in the past (tasks run on-demand only)
$trigger  = New-ScheduledTaskTrigger -Once -At '2000-01-01T00:00:00'
$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 4) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable $true

# ── Discover wrappers automatically ──────────────────────────────────────────
# Any Invoke-*Remote.ps1 in WrapperRoot gets registered as <Name>-OnDemand.
# The task name is derived from the wrapper filename:
#   Invoke-RailMasterRemote.ps1 → RailMaster-OnDemand
#   Invoke-FastmarketsRemote.ps1 → Fastmarkets-OnDemand

$wrappers = Get-ChildItem -Path $WrapperRoot -Filter 'Invoke-*Remote.ps1' -ErrorAction SilentlyContinue

if (-not $wrappers) {
    Write-Warning "No Invoke-*Remote.ps1 wrappers found in $WrapperRoot"
    Write-Warning "Deploy your wrappers first, then re-run this script."
    exit 1
}

Write-Host ""
Write-Host "Registering $($wrappers.Count) task(s) under $taskPath" -ForegroundColor Cyan
Write-Host ""

foreach ($wrapper in $wrappers) {
    # Derive task name: Invoke-FooBarRemote.ps1 → FooBar-OnDemand
    $baseName = $wrapper.BaseName -replace '^Invoke-', '' -replace 'Remote$', ''
    $taskName = "$baseName-OnDemand"

    $argString = "-NonInteractive -NoProfile -ExecutionPolicy Bypass -File `"$($wrapper.FullName)`""

    $action = New-ScheduledTaskAction `
        -Execute $ps `
        -Argument $argString `
        -WorkingDirectory $WrapperRoot

    # Remove existing task if present
    $existing = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
    if ($existing) {
        Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false
        Write-Host "  Removed existing: $taskPath$taskName"
    }

    Register-ScheduledTask `
        -TaskName $taskName `
        -TaskPath $taskPath `
        -Action   $action `
        -Trigger  $trigger `
        -Settings $settings `
        -RunLevel Highest `
        -User     $ServiceAccount `
        -Password $password | Out-Null

    Write-Host "  Registered: $taskPath$taskName" -ForegroundColor Green
}

Write-Host ""
Write-Host "Done. Verify in Task Scheduler under the $taskPath folder." -ForegroundColor Cyan
Write-Host ""
Write-Host "Quick test — trigger one manually:"
Write-Host "  schtasks /Run /TN `"$($taskPath)HelloWorldETL-OnDemand`""
