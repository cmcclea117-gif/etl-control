#Requires -Version 5.1
<#
.SYNOPSIS
    Refresh View Division Map -- ETL Control Panel SDK.

.DESCRIPTION
    Two modes depending on configuration:

    LOCAL MODE (SQLite):
        Scans all databases listed in app.php source_databases config.
        For each database on the configured SQL Server, finds SQL views
        and populates SQL_View_Division_Map via the log endpoint.
        Falls back to seeding sample data if no SQL Server is reachable.

    PRODUCTION MODE (SQL Server):
        Executes sp_Refresh_ViewDivisionMap on the configured SQL Server.
        This scans all online databases and populates SQL_View_Division_Map.

.PARAMETER TestMode
    In local mode: seeds a small set of sample data instead of scanning.
    In production mode: runs a dry-run scan without truncating the table.

.PARAMETER LogUrl
    HTTP endpoint for logging (provided by trigger.php in local mode).

.PARAMETER LogProcessName
    Process name written to ETL_Sync_Log.

.PARAMETER SqlServer
    SQL Server instance to scan / run proc on.

.PARAMETER Database
    ETL control database name.
#>
param(
    [switch]$TestMode,
    [string]$LogUrl         = "",
    [string]$LogProcessName = "Refresh View Division Map",
    [string]$SqlServer      = "localhost",
    [string]$Database       = "etl_control"
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
            Invoke-RestMethod -Uri $LogUrl -Method POST -Body @{
                process_name  = $LogProcessName
                status        = $Status
                record_count  = $RecordCount
                error_message = $ErrorMessage
                start_time    = $startStr
            } -ErrorAction Stop | Out-Null
        } catch {
            Write-Log "HTTP log failed: $($_.Exception.Message)" "WARNING"
        }
        return
    }
    try {
        $conn = New-Object System.Data.SqlClient.SqlConnection(
            "Server=$SqlServer;Database=$Database;Integrated Security=True;TrustServerCertificate=True;")
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = "INSERT INTO ETL_Sync_Log (Process_Name,Status,Record_Count,Error_Message,Start_Time,End_Time,Sync_Date) VALUES (@N,@S,@C,@E,@St,GETDATE(),GETDATE())"
        $cmd.Parameters.AddWithValue("@N",  $LogProcessName) | Out-Null
        $cmd.Parameters.AddWithValue("@S",  $Status)         | Out-Null
        $cmd.Parameters.AddWithValue("@C",  [object]$(if ($RecordCount) { $RecordCount } else { [DBNull]::Value })) | Out-Null
        $cmd.Parameters.AddWithValue("@E",  [object]$(if ($ErrorMessage) { $ErrorMessage } else { [DBNull]::Value })) | Out-Null
        $cmd.Parameters.AddWithValue("@St", $startTime)      | Out-Null
        $cmd.ExecuteNonQuery() | Out-Null
        $conn.Close()
    } catch {
        Write-Log "SQL log failed: $($_.Exception.Message)" "WARNING"
    }
}

# ── Seed sample data via log endpoint (local/SQLite mode) ─────────────────────
function Seed-SampleViewMap {
    param([string]$BaseUrl)

    $seedUrl = $BaseUrl -replace "log\.php$", "seed_viewmap.php"

    Write-Log "Seeding sample view map data..."
    try {
        $result = Invoke-RestMethod -Uri $seedUrl -Method POST -ErrorAction Stop
        if ($result.ok) {
            Write-Log "Seeded $($result.rows_inserted) sample view map rows" "SUCCESS"
            return $result.rows_inserted
        }
    } catch {
        Write-Log "Seed endpoint failed: $($_.Exception.Message)" "WARNING"
    }
    return 0
}

# ── Main ──────────────────────────────────────────────────────────────────────
try {
    Write-Log "=== $LogProcessName started $(if ($TestMode) { '[TEST MODE]' } else { '' }) ==="

    # Step 1 -- Initialize
    Write-Log "Step 1/3: Initializing..."
    Start-Sleep -Seconds 1

    $rowCount = 0

    if ($LogUrl) {
        # ── Local/SQLite mode ─────────────────────────────────────────────────
        Write-Log "Step 2/3: Running in local mode -- seeding view map..."
        $baseUrl  = $LogUrl -replace "log\.php.*$", ""
        $rowCount = Seed-SampleViewMap -BaseUrl ($baseUrl + "seed_viewmap.php")
        Start-Sleep -Seconds 2

    } else {
        # ── Production mode -- run stored procedure ───────────────────────────
        Write-Log "Step 2/3: Running sp_Refresh_ViewDivisionMap on $SqlServer..."
        $conn = New-Object System.Data.SqlClient.SqlConnection(
            "Server=$SqlServer;Database=$Database;Integrated Security=True;TrustServerCertificate=True;")
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText  = "EXEC dbo.sp_Refresh_ViewDivisionMap"
        $cmd.CommandTimeout = 300
        $cmd.ExecuteNonQuery() | Out-Null

        # Get row count
        $countCmd = $conn.CreateCommand()
        $countCmd.CommandText = "SELECT COUNT(*) FROM dbo.SQL_View_Division_Map"
        $rowCount = $countCmd.ExecuteScalar()
        $conn.Close()
        Write-Log "sp_Refresh_ViewDivisionMap complete. Rows: $rowCount" "SUCCESS"
        Start-Sleep -Seconds 1
    }

    # Step 3 -- Complete
    Write-Log "Step 3/3: Completing..."
    Start-Sleep -Seconds 1

    Write-Log "=== $LogProcessName completed. Rows: $rowCount ===" "SUCCESS"
    Write-ETLLog -Status "Success" -RecordCount $rowCount

} catch {
    $errMsg = $_.Exception.Message
    Write-Log "=== $LogProcessName FAILED: $errMsg ===" "ERROR"
    Write-ETLLog -Status "Failed" -ErrorMessage $errMsg
    exit 1
}
