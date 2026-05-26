#Requires -Version 5.1
<#
.SYNOPSIS
    Hello World ETL -- self-contained example for ETL Control Panel SDK.

.DESCRIPTION
    Demonstrates the full control panel integration:
      - Logs Started / Success / Failed via HTTP back to the control panel
      - Supports -TestMode for safe non-destructive testing
      - Simulates multi-step progress the UI tracks in real time

    Use this as a template when building new ETL processes.

.PARAMETER TestMode
    Skips file writes and uses a reduced record count.

.PARAMETER LogUrl
    HTTP endpoint to POST log entries to (provided by trigger.php in local mode).
    e.g. http://localhost:8080/log.php

.PARAMETER LogProcessName
    Process name to log under -- must match log_process_name in processes.php.

.PARAMETER SqlServer
    SQL Server instance (production mode only).

.PARAMETER Database
    Target database (production mode only).
#>

param(
    [switch]$TestMode,
    [string]$LogUrl         = "",
    [string]$LogProcessName = "Hello World ETL",
    [string]$SqlServer      = "localhost",
    [string]$Database       = "etl_control"
)

$ErrorActionPreference = "Stop"
$startTime = Get-Date

# -- Logging helpers -----------------------------------------------------------
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
    param(
        [string]$Status,
        [int]   $RecordCount  = 0,
        [string]$ErrorMessage = $null
    )
    $startStr = $startTime.ToString("yyyy-MM-dd HH:mm:ss")

    # -- Local mode: POST to log.php -------------------------------------------
    if ($LogUrl) {
        try {
            $body = @{
                process_name  = $LogProcessName
                status        = $Status
                record_count  = $RecordCount
                error_message = $ErrorMessage
                start_time    = $startStr
            }
            Invoke-RestMethod -Uri $LogUrl -Method POST -Body $body -ErrorAction Stop | Out-Null
            Write-Log "Logged '$Status' to $LogUrl" "SUCCESS"
        } catch {
            Write-Log "HTTP log failed (non-fatal): $($_.Exception.Message)" "WARNING"
        }
        return
    }

    # -- Production mode: write directly to SQL Server -------------------------
    try {
        $connStr = "Server=$SqlServer;Database=$Database;Integrated Security=True;TrustServerCertificate=True;"
        $conn    = New-Object System.Data.SqlClient.SqlConnection($connStr)
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = "INSERT INTO ETL_Sync_Log (Process_Name,Status,Record_Count,Error_Message,Start_Time,End_Time,Sync_Date) VALUES (@Name,@Status,@Count,@Err,@Start,GETDATE(),GETDATE())"
        $cmd.Parameters.AddWithValue("@Name",   $LogProcessName) | Out-Null
        $cmd.Parameters.AddWithValue("@Status", $Status)         | Out-Null
        $cmd.Parameters.AddWithValue("@Count",  [object]$(if ($RecordCount) { $RecordCount } else { [DBNull]::Value })) | Out-Null
        $cmd.Parameters.AddWithValue("@Err",    [object]$(if ($ErrorMessage) { $ErrorMessage } else { [DBNull]::Value })) | Out-Null
        $cmd.Parameters.AddWithValue("@Start",  $startTime)      | Out-Null
        $cmd.ExecuteNonQuery() | Out-Null
        $conn.Close()
    } catch {
        Write-Log "SQL log failed (non-fatal): $($_.Exception.Message)" "WARNING"
    }
}

# -- Main ----------------------------------------------------------------------
try {
    Write-Log "=== $LogProcessName started $(if ($TestMode) { '[TEST MODE]' } else { '' }) ==="

    # -- Step 1: Initialize ----------------------------------------------------
    Write-Log "Step 1/5: Initializing..."
    Start-Sleep -Seconds 2

    # -- Step 2: Generate sample data -----------------------------------------
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

    # -- Step 3: Process records -----------------------------------------------
    Write-Log "Step 3/5: Processing records..."
    $processed = ($data | Where-Object { $_.Value -gt 500 }).Count
    Write-Log "Processed $processed records above threshold" "SUCCESS"
    Start-Sleep -Seconds 2

    # -- Step 4: Write output --------------------------------------------------
    Write-Log "Step 4/5: Writing output..."
    if (-not $TestMode) {
        $outPath = Join-Path $env:TEMP "HelloWorldETL_$(Get-Date -Format 'yyyyMMdd_HHmmss').csv"
        $data | Export-Csv -Path $outPath -NoTypeInformation
        Write-Log "Output written to: $outPath" "SUCCESS"
    } else {
        Write-Log "Test mode -- skipping file write" "WARNING"
    }
    Start-Sleep -Seconds 2

    # -- Step 5: Complete ------------------------------------------------------
    Write-Log "Step 5/5: Completing..."
    Start-Sleep -Seconds 1

    Write-Log "=== $LogProcessName completed. Records: $recordCount ===" "SUCCESS"
    Write-ETLLog -Status "Success" -RecordCount $recordCount

} catch {
    $errMsg = $_.Exception.Message
    Write-Log "=== $LogProcessName FAILED: $errMsg ===" "ERROR"
    Write-ETLLog -Status "Failed" -ErrorMessage $errMsg
    exit 1
}
