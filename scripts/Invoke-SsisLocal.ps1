#Requires -Version 5.1
<#
.SYNOPSIS
    Local SSIS package executor for ETL Control Panel SDK.

.DESCRIPTION
    Connects directly to a SQL Server SSISDB catalog, executes an SSIS package,
    polls for completion, and logs Started/Success/Failed to the ETL Control
    Panel app via HTTP (log.php -> SQLite).

    No WinRM needed -- runs directly from your local machine.
    Use this as the local_script for SSIS processes in processes.php.

.PARAMETER TestMode
    Validates the SSISDB connection without executing the package.

.PARAMETER LogUrl
    HTTP endpoint for logging (provided by trigger.php).

.PARAMETER LogProcessName
    Process name written to ETL_Sync_Log.

.PARAMETER SsisServer
    SQL Server instance hosting SSISDB.

.PARAMETER SsisCatalog
    SSIS catalog name (almost always SSISDB).

.PARAMETER SsisFolder
    Folder name in the catalog.

.PARAMETER SsisProject
    Project name in the catalog.

.PARAMETER SsisPackage
    Package filename (e.g. MyPackage.dtsx).
#>
param(
    [switch]$TestMode,
    [string]$LogUrl         = "",
    [string]$LogProcessName = "SSIS Package",
    [string]$SsisServer     = "localhost",
    [string]$SsisCatalog    = "SSISDB",
    [string]$SsisFolder     = "",
    [string]$SsisProject    = "",
    [string]$SsisPackage    = ""
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

    # Step 1 -- Connect to SSISDB
    Write-Log "Step 1/3: Connecting to $SsisServer/$SsisCatalog..."
    $conn = New-Object System.Data.SqlClient.SqlConnection(
        "Server=$SsisServer;Database=$SsisCatalog;Integrated Security=True;TrustServerCertificate=True;")
    $conn.Open()
    Write-Log "Connected." "SUCCESS"

    if ($TestMode) {
        Write-Log "Test mode -- validating SSISDB connection only" "WARNING"
        $conn.Close()
        Write-Log "=== $LogProcessName test complete ===" "SUCCESS"
        Write-ETLLog -Status "Success" -RecordCount 0
        exit 0
    }

    # Step 2 -- Execute SSIS package
    Write-Log "Step 2/3: Executing $SsisPackage..."
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = @"
DECLARE @exec_id BIGINT
EXEC [SSISDB].[catalog].[create_execution]
    @folder_name  = N'$SsisFolder',
    @project_name = N'$SsisProject',
    @package_name = N'$SsisPackage',
    @execution_id = @exec_id OUTPUT
EXEC [SSISDB].[catalog].[start_execution] @exec_id
SELECT @exec_id AS execution_id
"@
    $executionId = $cmd.ExecuteScalar()
    Write-Log "SSIS package started. Execution ID: $executionId" "SUCCESS"

    # Step 3 -- Poll for completion
    Write-Log "Step 3/3: Waiting for completion..."
    $maxWait = 300; $waited = 0; $status = 0
    do {
        Start-Sleep -Seconds 5; $waited += 5
        $statusCmd = $conn.CreateCommand()
        $statusCmd.CommandText = "SELECT [status] FROM [SSISDB].[catalog].[executions] WHERE execution_id = $executionId"
        $status = $statusCmd.ExecuteScalar()
        $statusName = switch ($status) {
            1 { "Created" } 2 { "Running" } 3 { "Succeeded" }
            4 { "Failed"  } 5 { "Pending" } 6 { "Ended Unexpectedly" }
            7 { "Canceled" } default { "Unknown" }
        }
        Write-Log "SSIS status: $statusName ($waited s elapsed)"
    } while ($status -notin @(3, 4, 6, 7) -and $waited -lt $maxWait)

    # Status 7 (Canceled) can be a false positive when package actually finished
    # Check operation messages to confirm real outcome
    if ($status -eq 7) {
        $msgCmd = $conn.CreateCommand()
        $msgCmd.CommandText = "SELECT TOP 10 message FROM [SSISDB].[catalog].[operation_messages] WHERE operation_id = $executionId AND message_type = 40 ORDER BY message_time DESC"
        $msgReader = $msgCmd.ExecuteReader()
        $hasFinished = $false
        while ($msgReader.Read()) {
            if ($msgReader["message"] -like "*Finished*" -or $msgReader["message"] -like "*Completed*") {
                $hasFinished = $true; break
            }
        }
        $msgReader.Close()
        if ($hasFinished) {
            Write-Log "Package finished (status 7 is a known false positive -- treating as success)" "WARNING"
            $status = 3
        }
    }

    $conn.Close()

    if ($status -eq 3) {
        Write-Log "=== $LogProcessName completed successfully ===" "SUCCESS"
        Write-ETLLog -Status "Success" -RecordCount 1
    } else {
        throw "SSIS package ended with status: $statusName (code $status)"
    }

} catch {
    $errMsg = $_.Exception.Message
    Write-Log "=== $LogProcessName FAILED: $errMsg ===" "ERROR"
    if ($conn -and $conn.State -eq 'Open') { $conn.Close() }
    Write-ETLLog -Status "Failed" -ErrorMessage $errMsg
    exit 1
}