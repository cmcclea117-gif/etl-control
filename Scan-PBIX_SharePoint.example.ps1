# ============================================================
# Scan-PBIX_SharePoint.ps1
# Downloads PBIX files from SharePoint via Graph API (app-only auth),
# extracts TMDL connection info, writes to SQL Server,
# then fetches Power BI report URLs and updates PBI_Connection_Map.
#
# Prerequisites:
#   pbi-tools.exe installed (desktop version)
#   Azure AD app registration with:
#     - Sites.Read.All (application permission, admin consent)
#     - Files.Read.All (application permission, admin consent)
#     - Report.Read.All (application permission, admin consent)
#     - Power BI workspace: add the app as Member
#
# Fill in the variables in the CONFIGURE section below.
# Store this file locally with real values — it is gitignored.
# ============================================================

# *** CONFIGURE THESE — keep secrets out of source control ***
$TenantId      = "your-azure-tenant-id"          # Azure AD tenant ID
$ClientId      = "your-azure-client-id"          # App registration client ID
$ClientSecret  = "YourClientSecret"              # ← paste your secret here locally
$SQLUser       = "your_db_user"
$SQLPass       = "YourSQLPass"

# *** SharePoint config ***
$SharePointSite = "yourtenant.sharepoint.com:/sites/YourSite:"
$LibraryName    = "Documents"
$SubFolder      = "Power BI Reports"

# *** Tool + SQL config ***
$PBITools    = "C:\Tools\pbi-tools-desktop\pbi-tools.exe"
$ExtractRoot = "C:\Temp\PBX"
$SQLServer   = "your-sql-server"
$SQLDatabase = "etl_control"
$SPSiteUrl   = "https://yourtenant.sharepoint.com/sites/YourSite"

# ============================================================
# Log start to ETL_Sync_Log
# ============================================================
$startTime  = Get-Date
$logConnStr = "Server=$SQLServer;Database=$SQLDatabase;User ID=$SQLUser;Password=$SQLPass;TrustServerCertificate=True;"
$logConn    = New-Object System.Data.SqlClient.SqlConnection($logConnStr)
$logConn.Open()
$logCmd = $logConn.CreateCommand()
$logCmd.CommandText = @"
INSERT INTO dbo.ETL_Sync_Log (Process_Name, Sync_Date, Status, Start_Time)
OUTPUT INSERTED.Log_ID
VALUES ('PBIX SharePoint Scanner', GETDATE(), 'Started', GETDATE())
"@
$logId = $logCmd.ExecuteScalar()
$logConn.Close()

foreach ($dir in @($ExtractRoot, "C:\Temp\PBIXFiles")) {
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
}

# ============================================================
# Authenticate via client credentials (no user login needed)
# ============================================================
Write-Host "Authenticating with Microsoft Graph..." -ForegroundColor Cyan

$tokenUrl  = "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token"
$tokenBody = @{
    grant_type    = "client_credentials"
    client_id     = $ClientId
    client_secret = $ClientSecret
    scope         = "https://graph.microsoft.com/.default"
}

$tokenResponse = Invoke-RestMethod -Uri $tokenUrl -Method POST -Body $tokenBody
$accessToken   = $tokenResponse.access_token

$headers = @{
    Authorization = "Bearer $accessToken"
    Accept        = "application/json"
}

Write-Host "Authenticated." -ForegroundColor Green

# ============================================================
# Get SharePoint site and drive
# ============================================================
$siteResponse = Invoke-RestMethod -Uri "https://graph.microsoft.com/v1.0/sites/$SharePointSite" -Headers $headers
$siteId       = $siteResponse.id

$drivesResponse = Invoke-RestMethod -Uri "https://graph.microsoft.com/v1.0/sites/$siteId/drives" -Headers $headers
$drive          = $drivesResponse.value | Where-Object { $_.name -eq $LibraryName }

if (-not $drive) {
    Write-Error "Library '$LibraryName' not found on site."
    exit 1
}

$driveId = $drive.id

# Get PBIX files from subfolder
$folderPath    = "root:/$SubFolder"
$itemsUrl      = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$($folderPath):/children"
$itemsResponse = Invoke-RestMethod -Uri $itemsUrl -Headers $headers
$pbixFiles     = $itemsResponse.value | Where-Object { $_.name -like "*.pbix" }

Write-Host "Found $($pbixFiles.Count) PBIX file(s) in SharePoint" -ForegroundColor Cyan

# ============================================================
# Helper functions
# ============================================================
function Parse-ExtractedTMDL {
    param ([string]$ExtractDir, [string]$ReportName, [string]$SPPath)
    $records   = @()
    $tmdlFiles = Get-ChildItem "$ExtractDir\Model\tables" -Filter "*.tmdl" -ErrorAction SilentlyContinue
    if (-not $tmdlFiles) { return $records }

    foreach ($tmdl in $tmdlFiles) {
        if ($tmdl.Name -match "^(DateTableTemplate|LocalDateTable)_") { continue }
        $content = Get-Content $tmdl.FullName -Raw -ErrorAction SilentlyContinue
        if (-not $content) { continue }

        $dbMatch = [regex]::Match($content, '(?<!MySQL\.)Sql\.Database\s*\(\s*"([^"]+)"\s*,\s*"([^"]+)"')
        if (-not $dbMatch.Success) { continue }

        $server   = $dbMatch.Groups[1].Value
        $database = $dbMatch.Groups[2].Value
        $schema   = ""
        $viewName = ""

        $itemMatch = [regex]::Match($content, 'Source\s*\{\s*\[Schema\s*=\s*"([^"]+)"\s*,\s*Item\s*=\s*"([^"]+)"\s*\]')
        if ($itemMatch.Success) {
            $schema   = $itemMatch.Groups[1].Value
            $viewName = $itemMatch.Groups[2].Value
        } else {
            $itemOnly = [regex]::Match($content, 'Source\s*\{\s*\[Item\s*=\s*"([^"]+)"\s*\]')
            if ($itemOnly.Success) { $viewName = $itemOnly.Groups[1].Value }
        }

        if (-not $viewName) {
            $queryMatch = [regex]::Match($content, '\[Query\s*=\s*"[^"]*FROM\s+\[?(\w+)\]?\.\[?(\w+)\]?(?!\.\[?\w)')
            if ($queryMatch.Success) {
                $schema   = $queryMatch.Groups[1].Value
                $viewName = $queryMatch.Groups[2].Value
            } else {
                $querySimple = [regex]::Match($content, '\[Query\s*=\s*"[^"]*FROM\s+\[?(\w+)\]?"')
                if ($querySimple.Success) { $viewName = $querySimple.Groups[1].Value }
            }
        }

        if (-not $viewName) {
            $threePartOnly = [regex]::IsMatch($content, '\[Query\s*=\s*"[^"]*FROM\s+\w+\.\w+\.\w+') -and
                             -not [regex]::IsMatch($content, '\[Query\s*=\s*"[^"]*FROM\s+\[?\w+\]?\.\[?\w+\]?"')
            if ($threePartOnly) { continue }
        }

        if (-not $viewName) {
            $hierMatch = [regex]::Match($content, '\{\s*\[Name\s*=\s*"([^"]+)"\s*\]\s*\}')
            if ($hierMatch.Success) { $viewName = $hierMatch.Groups[1].Value }
        }

        if (-not $schema) {
            $schemaNav = [regex]::Match($content, 'Source\s*\{\s*\[Schema\s*=\s*"([^"]+)"\s*\]\s*\}')
            if ($schemaNav.Success) { $schema = $schemaNav.Groups[1].Value }
        }

        $modeMatch = [regex]::Match($content, 'mode:\s*(\w+)')
        $mode = if ($modeMatch.Success) { $modeMatch.Groups[1].Value } else { "" }

        # Add your known database names here to prevent false-positive schema matches
        $knownDatabases = @('etl_control', 'master', 'msdb', 'tempdb')
        if ($knownDatabases -contains $schema.ToLower()) { continue }

        $records += [PSCustomObject]@{
            Report_File     = $ReportName
            SharePoint_Path = $SPPath
            SharePoint_Site = $SPSiteUrl
            Server          = $server
            Database_Name   = $database
            Schema_Name     = $schema
            View_Or_Table   = $viewName
            Import_Mode     = $mode
        }
    }
    return $records
}

function Write-ToSQL {
    param ([array]$Records)
    if (-not $Records -or $Records.Count -eq 0) { return }

    $connStr = "Server=$SQLServer;Database=$SQLDatabase;User ID=$SQLUser;Password=$SQLPass;TrustServerCertificate=True;"
    $conn    = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()

    foreach ($r in $Records) {
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = @"
INSERT INTO dbo.PBI_Connection_Map
    (Report_File, SharePoint_Path, SharePoint_Site, Server,
     Database_Name, Schema_Name, View_Or_Table, Import_Mode)
VALUES
    (@ReportFile, @SPPath, @SPSite, @Server,
     @Database, @Schema, @ViewOrTable, @Mode)
"@
        $cmd.Parameters.AddWithValue("@ReportFile",  [string]$r.Report_File)     | Out-Null
        $cmd.Parameters.AddWithValue("@SPPath",      [string]$r.SharePoint_Path) | Out-Null
        $cmd.Parameters.AddWithValue("@SPSite",      [string]$r.SharePoint_Site) | Out-Null
        $cmd.Parameters.AddWithValue("@Server",      [string]$r.Server)          | Out-Null
        $cmd.Parameters.AddWithValue("@Database",    [string]$r.Database_Name)   | Out-Null
        $cmd.Parameters.AddWithValue("@Schema",      [string]$r.Schema_Name)     | Out-Null
        $cmd.Parameters.AddWithValue("@ViewOrTable", [string]$r.View_Or_Table)   | Out-Null
        $cmd.Parameters.AddWithValue("@Mode",        [string]$r.Import_Mode)     | Out-Null
        $cmd.ExecuteNonQuery() | Out-Null
    }
    $conn.Close()
}

# ============================================================
# Clear previous scan
# ============================================================
$connStr   = "Server=$SQLServer;Database=$SQLDatabase;User ID=$SQLUser;Password=$SQLPass;TrustServerCertificate=True;"
$clearConn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$clearConn.Open()
$clearCmd = $clearConn.CreateCommand()
$clearCmd.CommandText = "DELETE FROM dbo.PBI_Connection_Map"
$clearCmd.ExecuteNonQuery() | Out-Null
$clearConn.Close()
Write-Host "PBI_Connection_Map cleared." -ForegroundColor Yellow

# ============================================================
# Process each PBIX file
# ============================================================
$i            = 0
$totalRecords = 0

foreach ($item in $pbixFiles) {
    $i++
    Write-Host "[$i/$($pbixFiles.Count)] $($item.name)" -ForegroundColor White

    $localPath  = "C:\Temp\PBIXFiles\$($item.name)"
    $safeName   = [System.IO.Path]::GetFileNameWithoutExtension($item.name) -replace '[\\/:*?"<>|]', '_'
    $extractDir = "$ExtractRoot\$safeName"

    try {
        $downloadUrl = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$($item.id)/content"
        Invoke-RestMethod -Uri $downloadUrl -Headers $headers -OutFile $localPath
    } catch {
        Write-Warning "  Download failed: $_"
        continue
    }

    if (Test-Path $extractDir) { Remove-Item $extractDir -Recurse -Force -ErrorAction SilentlyContinue }

    try {
        & $PBITools extract "$localPath" -extractFolder "$extractDir" -modelSerialization Tmdl 2>&1 | Out-Null
    } catch {
        Write-Warning "  pbi-tools failed: $_"
        Remove-Item $localPath -Force -ErrorAction SilentlyContinue
        continue
    }

    $spPath   = if ($item.parentReference.path) { $item.parentReference.path } else { "" }
    $records  = Parse-ExtractedTMDL -ExtractDir $extractDir -ReportName $item.name -SPPath $spPath
    $recCount = @($records).Count
    Write-Host "  → $recCount connection(s) found" -ForegroundColor $(if ($recCount -gt 0) {"Green"} else {"DarkGray"})

    Write-ToSQL -Records $records
    $totalRecords += $records.Count

    Remove-Item $localPath  -Force -ErrorAction SilentlyContinue
    Remove-Item $extractDir -Recurse -Force -ErrorAction SilentlyContinue
}

# ============================================================
# Fetch Power BI report URLs and update PBI_Connection_Map
# ============================================================
Write-Host "Fetching Power BI report URLs..." -ForegroundColor Cyan

try {
    $pbiTokenBody = @{
        grant_type    = "client_credentials"
        client_id     = $ClientId
        client_secret = $ClientSecret
        scope         = "https://analysis.windows.net/powerbi/api/.default"
    }
    $pbiTokenResponse = Invoke-RestMethod -Uri $tokenUrl -Method POST -Body $pbiTokenBody
    $pbiToken         = $pbiTokenResponse.access_token

    $pbiHeaders = @{ Authorization = "Bearer $pbiToken"; Accept = "application/json" }

    $reportUrlMap = @{}
    $workspacesResponse = Invoke-RestMethod -Uri "https://api.powerbi.com/v1.0/myorg/groups" -Headers $pbiHeaders

    foreach ($workspace in $workspacesResponse.value) {
        try {
            $reportsResponse = Invoke-RestMethod `
                -Uri     "https://api.powerbi.com/v1.0/myorg/groups/$($workspace.id)/reports" `
                -Headers $pbiHeaders
            foreach ($report in $reportsResponse.value) {
                $key = $report.name.ToLower()
                if (-not $reportUrlMap.ContainsKey($key)) { $reportUrlMap[$key] = $report.webUrl }
            }
        } catch {
            Write-Warning "  Could not fetch reports for workspace '$($workspace.name)': $_"
        }
    }

    Write-Host "  Found $($reportUrlMap.Count) report URL(s) across $($workspacesResponse.value.Count) workspace(s)" -ForegroundColor Green

    $urlConn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $urlConn.Open()
    $urlUpdated = 0
    foreach ($key in $reportUrlMap.Keys) {
        $urlCmd = $urlConn.CreateCommand()
        $urlCmd.CommandText = "UPDATE dbo.PBI_Connection_Map SET Report_URL = @Url WHERE LOWER(REPLACE(Report_File, '.pbix', '')) = @Name"
        $urlCmd.Parameters.AddWithValue("@Url",  $reportUrlMap[$key]) | Out-Null
        $urlCmd.Parameters.AddWithValue("@Name", $key)                | Out-Null
        $urlUpdated += $urlCmd.ExecuteNonQuery()
    }
    $urlConn.Close()
    Write-Host "  Updated $urlUpdated row(s) with report URLs" -ForegroundColor Green

} catch {
    Write-Warning "Power BI URL fetch failed (non-fatal): $_"
    Write-Warning "Check that Report.Read.All is granted on the Azure app and the app is a workspace Member."
}

# ============================================================
# Log completion to ETL_Sync_Log
# ============================================================
$endTime = Get-Date
$status  = if ($totalRecords -lt 50) { "Warning" } else { "Success" }
$errMsg  = if ($totalRecords -lt 50) { "Only $totalRecords records written — check your PBIX library." } else { $null }

$logConn = New-Object System.Data.SqlClient.SqlConnection($logConnStr)
$logConn.Open()
$logCmd = $logConn.CreateCommand()
$logCmd.CommandText = @"
UPDATE dbo.ETL_Sync_Log
SET Status = @Status, Record_Count = @Count, End_Time = @EndTime, Error_Message = @ErrMsg
WHERE Log_ID = @LogId
"@
$logCmd.Parameters.AddWithValue("@Status",  $status)       | Out-Null
$logCmd.Parameters.AddWithValue("@Count",   $totalRecords) | Out-Null
$logCmd.Parameters.AddWithValue("@EndTime", $endTime)      | Out-Null
if ($errMsg) {
    $logCmd.Parameters.AddWithValue("@ErrMsg", $errMsg) | Out-Null
} else {
    $logCmd.Parameters.AddWithValue("@ErrMsg", [DBNull]::Value) | Out-Null
}
$logCmd.Parameters.AddWithValue("@LogId", $logId) | Out-Null
$logCmd.ExecuteNonQuery() | Out-Null
$logConn.Close()

Write-Host "Scan complete. $totalRecords connection(s) written to $SQLDatabase.dbo.PBI_Connection_Map" -ForegroundColor Cyan
