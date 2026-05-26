#Requires -Version 5.1
<#
.SYNOPSIS
    Hello World ETL — self-contained example for ETL Control Panel SDK.

.DESCRIPTION
    Demonstrates the full control panel integration:
      - Logs Started / Success / Failed to dbo.ETL_Sync_Log
      - Supports -TestMode for safe non-destructive testing
      - Simulates multi-step progress the UI can track in real time
      - Uses Windows integrated auth to SQL Server (no password needed)

    Use this as a template when building new ETL processes.
    Copy it, rename it, and replace the logic in the "main work" section.

.PARAMETER TestMode
    When set, skips any writes and logs a reduced record count.
    Map to -TestMode in processes.php test_args.

.PARAMETER SqlServer
    SQL Server instance name. Defaults to the value in app.example.php.

.PARAMETER Database
    Target database name. Defaults to 'etl_control'.

.NOTES
    Process name logged to ETL_Sync_Log: 'Hello World ETL'
    This must match the log_process_name in config/processes.php.
#>

param(
    [switch]$TestMode,
    [string]$SqlServer = "localhost",
    [string]$Database  = "etl_control"
)

$ErrorActionPreference = "Stop"
$processName = "Hello World ETL"
$startTime   = Get-Date

# ── Logging helpers ───────────────────────────────────────────────────────────
function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $color = switch ($Level) {
        "SUCCESS" { "Green"  }
        "WARNING" { "Yellow" }
        "ERROR"   { "Red"    }
        default   { "White"  }
    }
    Write-Host "[$ts] [$Level] $Message" -ForegroundColor $color
}

function Write-ETLLog {
    param(
        [string]$Status,
        [int]   $RecordCount  = 0,
        [string]$ErrorMessage = $null
    )
    try {
        $connStr = "Server=$SqlServer;Database=$Database;Integrated Security=True;TrustServerCertificate=True;"
        $conn    = New-Object System.Data.SqlClient.SqlConnection($connStr)
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = @"
INSERT INTO dbo.ETL_Sync_Log
    (Process_Name, Status, Record_Count, Error_Message, Start_Time, End_Time, Sync_Date)
VALUES
    (@Name, @Status, @Count, @Err, @Start, @End, GETDATE())
"@
        $cmd.Parameters.AddWithValue("@Name",   $processName)                                  | Out-Null
        $cmd.Parameters.AddWithValue("@Status", $Status)                                       | Out-Null
        $cmd.Parameters.AddWithValue("@Count",  $RecordCount)                                  | Out-Null
        $cmd.Parameters.AddWithValue("@Err",    [object]$(if ($ErrorMessage) { $ErrorMessage } else { [DBNull]::Value })) | Out-Null
        $cmd.Parameters.AddWithValue("@Start",  $startTime)                                    | Out-Null
        $cmd.Parameters.AddWithValue("@End",    (Get-Date))                                    | Out-Null
        $cmd.ExecuteNonQuery() | Out-Null
        $conn.Close()
    } catch {
        Write-Log "ETL log write failed (non-fatal): $($_.Exception.Message)" "WARNING"
    }
}

# ── Main ──────────────────────────────────────────────────────────────────────
try {
    Write-Log "=== $processName started $(if ($TestMode) { '[TEST MODE]' } else { '' }) ==="
    Write-ETLLog -Status "Started"

    # ── Step 1: Initialize ────────────────────────────────────────────────────
    Write-Log "Step 1/5: Initializing..."
    Start-Sleep -Seconds 2

    # ── Step 2: Generate sample data ─────────────────────────────────────────
    Write-Log "Step 2/5: Generating sample data..."
    $recordCount = if ($TestMode) { 10 } else { 100 }
    $data = 1..$recordCount | ForEach-Object {
        [PSCustomObject]@{
            Id        = $_
            Name      = "Record $_"
            Value     = Get-Random -Minimum 1 -Maximum 1000
            Timestamp = (Get-Date).ToString("o")
        }
    }
    Write-Log "Generated $recordCount sample records" "SUCCESS"
    Start-Sleep -Seconds 2

    # ── Step 3: Process records ───────────────────────────────────────────────
    Write-Log "Step 3/5: Processing records..."
    $processed = ($data | Where-Object { $_.Value -gt 500 }).Count
    Write-Log "Processed $processed records above threshold" "SUCCESS"
    Start-Sleep -Seconds 2

    # ── Step 4: Write output ──────────────────────────────────────────────────
    Write-Log "Step 4/5: Writing output..."
    if (-not $TestMode) {
        $outPath = Join-Path $env:TEMP "HelloWorldETL_$(Get-Date -Format 'yyyyMMdd_HHmmss').csv"
        $data | Export-Csv -Path $outPath -NoTypeInformation
        Write-Log "Output written to: $outPath" "SUCCESS"
    } else {
        Write-Log "Test mode — skipping file write" "WARNING"
    }
    Start-Sleep -Seconds 2

    # ── Step 5: Complete ──────────────────────────────────────────────────────
    Write-Log "Step 5/5: Completing..."
    Start-Sleep -Seconds 1

    Write-Log "=== $processName completed successfully. Records: $recordCount ===" "SUCCESS"
    Write-ETLLog -Status "Success" -RecordCount $recordCount

} catch {
    $errMsg = $_.Exception.Message
    Write-Log "=== $processName FAILED: $errMsg ===" "ERROR"
    Write-ETLLog -Status "Failed" -ErrorMessage $errMsg
    exit 1
}
