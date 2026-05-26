#Requires -Version 5.1
<#
.SYNOPSIS
    CSV Import ETL -- example process for ETL Control Panel SDK.

.DESCRIPTION
    Reads a CSV file from a configurable source path, validates rows,
    and logs a record count. Demonstrates a realistic file-based ETL pattern.

    Use this as a template for file-based ETL processes.

.PARAMETER SourcePath
    Full path to the CSV file to import.
    Default: looks for sample.csv in the scripts directory.

.PARAMETER TestMode
    Validates the file exists and counts rows but does not process.

.PARAMETER LogUrl
    HTTP endpoint for logging (provided by trigger.php in local mode).

.PARAMETER LogProcessName
    Process name written to ETL_Sync_Log.

.PARAMETER SqlServer
    SQL Server instance (production mode).

.PARAMETER Database
    Target database (production mode).
#>
param(
    [string]$SourcePath     = "",
    [switch]$TestMode,
    [string]$LogUrl         = "",
    [string]$LogProcessName = "CSV Import ETL",
    [string]$SqlServer      = "localhost",
    [string]$Database       = "etl_control"
)

$ErrorActionPreference = "Stop"
$startTime = Get-Date

# ── Default source path ───────────────────────────────────────────────────────
if (-not $SourcePath) {
    $SourcePath = Join-Path $PSScriptRoot "sample.csv"
}

# ── Logging helpers ───────────────────────────────────────────────────────────
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

# ── Generate sample CSV if it doesn't exist ───────────────────────────────────
function New-SampleCsv {
    param([string]$Path)
    $rows = 1..50 | ForEach-Object {
        [PSCustomObject]@{
            Id        = $_
            Name      = "Item $_"
            Category  = @("A","B","C") | Get-Random
            Value     = [math]::Round((Get-Random -Minimum 1.0 -Maximum 999.99), 2)
            Date      = (Get-Date).AddDays(-$_).ToString("yyyy-MM-dd")
        }
    }
    $rows | Export-Csv -Path $Path -NoTypeInformation
    Write-Log "Created sample CSV: $Path" "SUCCESS"
}

# ── Main ──────────────────────────────────────────────────────────────────────
try {
    Write-Log "=== $LogProcessName started $(if ($TestMode) { '[TEST MODE]' } else { '' }) ==="

    # Step 1 -- Check source file
    Write-Log "Step 1/4: Locating source file..."
    Write-Log "Source: $SourcePath"

    if (-not (Test-Path $SourcePath)) {
        Write-Log "File not found -- generating sample CSV..." "WARNING"
        New-SampleCsv -Path $SourcePath
    }
    Start-Sleep -Seconds 1

    # Step 2 -- Read and validate
    Write-Log "Step 2/4: Reading and validating CSV..."
    $rows = Import-Csv -Path $SourcePath
    $rowCount = @($rows).Count
    Write-Log "Found $rowCount rows in source file" "SUCCESS"

    if ($rowCount -eq 0) {
        throw "Source file is empty: $SourcePath"
    }
    Start-Sleep -Seconds 2

    # Step 3 -- Process
    Write-Log "Step 3/4: Processing records..."
    if ($TestMode) {
        Write-Log "Test mode -- skipping actual processing" "WARNING"
        $processed = 0
    } else {
        # Simulate processing -- in a real ETL you'd load to DB here
        $processed = ($rows | Where-Object { $_.Category -ne "" }).Count
        Write-Log "Processed $processed valid records" "SUCCESS"
    }
    Start-Sleep -Seconds 2

    # Step 4 -- Complete
    Write-Log "Step 4/4: Completing..."
    Start-Sleep -Seconds 1

    $finalCount = if ($TestMode) { $rowCount } else { $processed }
    Write-Log "=== $LogProcessName completed. Records: $finalCount ===" "SUCCESS"
    Write-ETLLog -Status "Success" -RecordCount $finalCount

} catch {
    $errMsg = $_.Exception.Message
    Write-Log "=== $LogProcessName FAILED: $errMsg ===" "ERROR"
    Write-ETLLog -Status "Failed" -ErrorMessage $errMsg
    exit 1
}
