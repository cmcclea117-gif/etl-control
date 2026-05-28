#Requires -Version 5.1
<#
.SYNOPSIS
    Local SQL Agent job executor for ETL Control Panel SDK.

.DESCRIPTION
    Connects directly to a SQL Server instance, fires a SQL Agent job,
    polls sysjobhistory for completion, and logs Started/Success/Failed
    to the ETL Control Panel app via HTTP (log.php -> SQLite).

    No WinRM needed -- runs directly from your local machine.
    Use this as the local_script for SQL Agent processes in processes.php.

.PARAMETER TestMode
    Validates the SQL Server connection without firing the job.

.PARAMETER LogUrl
    HTTP endpoint for logging (provided by trigger.php).

.PARAMETER LogProcessName
    Process name written to ETL_Sync_Log.

.PARAMETER AgentServer
    SQL Server instance hosting the SQL Agent job.

.PARAMETER AgentJob
    Exact name of the SQL Agent job to fire.
#>
param(
    [switch]$TestMode,
    [string]$LogUrl         = "",
    [string]$LogProcessName = "SQL Agent Job",
    [string]$AgentServer    = "localhost",
    [string]$AgentJob       = ""
)

$ErrorActionPreference = "Stop"
$startTime = Get-Date

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $ts    = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $color = switch ($Level) {
        "SUCCESS" { "Green"  }
        "WARNING" { "Yellow" }
        "ERROR"   { "Red"    }
        default   { "White"  }
    }
    Write-Host "[$ts] [$Level] $Message" -ForegroundColor $color
}

function Write-ETLLog {
    param([string]$Status, [int]$RecordCount = 0, [string]$ErrorMessage = $null)
    $startStr = $startTime.ToString("yyyy-MM-dd HH:mm:ss")
    if ($LogUrl) {
        try {
            $body = @{
                process_name  = $LogProcessName
                status        = $Status
                record_count  = $RecordCount
                start_time    = $startStr
            }
            if ($ErrorMessage) { $body.error_message = $ErrorMessage }
            Invoke-RestMethod -Uri $LogUrl -Method POST -Body $body -ErrorAction Stop | Out-Null
            Write-Log "Logged '$Status' to $LogUrl" "SUCCESS"
        } catch {
            Write-Log "HTTP log failed (non-fatal): $($_.Exception.Message)" "WARNING"
        }
    }
}

# ── Main ──────────────────────────────────────────────────────────────────────
try {
    Write-Log "=== $LogProcessName started$(if ($TestMode) { ' [TEST MODE]' }) ==="

    if (-not $AgentJob) { throw "AgentJob parameter is required" }

    # Step 1 -- Connect
    Write-Log "Step 1/3: Connecting to $AgentServer..."
    $conn = New-Object System.Data.SqlClient.SqlConnection(
        "Server=$AgentServer;Database=msdb;Integrated Security=True;TrustServerCertificate=True;")
    $conn.Open()
    Write-Log "Connected." "SUCCESS"

    if ($TestMode) {
        # Verify job exists
        $checkCmd = $conn.CreateCommand()
        $checkCmd.CommandText = "SELECT COUNT(*) FROM dbo.sysjobs WHERE name = N'$AgentJob'"
        $exists = $checkCmd.ExecuteScalar()
        if ($exists -eq 0) { throw "Job not found: $AgentJob" }
        Write-Log "Test mode -- job '$AgentJob' exists, skipping execution" "WARNING"
        $conn.Close()
        Write-Log "=== $LogProcessName test complete ===" "SUCCESS"
        Write-ETLLog -Status "Success" -RecordCount 0
        exit 0
    }

    # Step 2 -- Capture baseline BEFORE firing (so we detect our specific run)
    Write-Log "Step 2/3: Starting SQL Agent job: $AgentJob..."
    $baselineCmd = $conn.CreateCommand()
    $baselineCmd.CommandText = @"
SELECT ISNULL(MAX(h.instance_id), 0)
FROM dbo.sysjobhistory h
JOIN dbo.sysjobs j ON j.job_id = h.job_id
WHERE j.name = N'$AgentJob'
"@
    $baselineId = $baselineCmd.ExecuteScalar()
    Write-Log "Baseline instance_id: $baselineId"

    $startCmd = $conn.CreateCommand()
    $startCmd.CommandText = "EXEC dbo.sp_start_job N'$AgentJob'"
    $startCmd.ExecuteNonQuery() | Out-Null
    Write-Log "Job started." "SUCCESS"
    Start-Sleep -Seconds 3

    # Step 3 -- Poll until complete
    Write-Log "Step 3/3: Waiting for completion..."

    $maxWait = 3600; $waited = 0; $jobStatus = "Unknown"
    do {
        Start-Sleep -Seconds 5; $waited += 5
        $statusCmd = $conn.CreateCommand()
        $statusCmd.CommandText = @"
SELECT TOP 1
    CASE h.run_status
        WHEN 0 THEN 'Failed'
        WHEN 1 THEN 'Succeeded'
        WHEN 2 THEN 'Retry'
        WHEN 3 THEN 'Cancelled'
        ELSE 'Unknown'
    END AS job_status
FROM dbo.sysjobhistory h
JOIN dbo.sysjobs j ON j.job_id = h.job_id
WHERE j.name = N'$AgentJob'
  AND h.step_id = 0
  AND h.instance_id > $baselineId
ORDER BY h.instance_id DESC
"@
        $reader = $statusCmd.ExecuteReader()
        if ($reader.Read()) { $jobStatus = $reader["job_status"] }
        $reader.Close()

        # Also check if job is currently running via sysjobactivity
        if ($jobStatus -eq 'Unknown') {
            $activeCmd = $conn.CreateCommand()
            $activeCmd.CommandText = @"
SELECT COUNT(*) FROM dbo.sysjobactivity a
JOIN dbo.sysjobs j ON j.job_id = a.job_id
WHERE j.name = N'$AgentJob'
  AND a.start_execution_date IS NOT NULL
  AND a.stop_execution_date IS NULL
  AND a.session_id = (SELECT MAX(session_id) FROM dbo.syssessions)
"@
            $isRunning = $activeCmd.ExecuteScalar()
            if ($isRunning -gt 0) { $jobStatus = "Running" }
        }

        Write-Log "Job status: $jobStatus ($waited s elapsed)"
    } while ($jobStatus -in @('Running', 'Retry', 'Unknown') -and $waited -lt $maxWait)

    $conn.Close()

    if ($jobStatus -eq 'Succeeded') {
        Write-Log "=== $LogProcessName completed successfully ===" "SUCCESS"
        Write-ETLLog -Status "Success" -RecordCount 1
    } else {
        throw "SQL Agent job ended with status: $jobStatus"
    }

} catch {
    $errMsg = $_.Exception.Message
    Write-Log "=== $LogProcessName FAILED: $errMsg ===" "ERROR"
    if ($conn -and $conn.State -eq 'Open') { $conn.Close() }
    Write-ETLLog -Status "Failed" -ErrorMessage $errMsg
    exit 1
}