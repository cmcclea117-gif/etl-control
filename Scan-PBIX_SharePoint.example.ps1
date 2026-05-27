#Requires -Version 5.1
<#
.SYNOPSIS
    PBIX SharePoint Scanner for ETL Control Panel.

.DESCRIPTION
    Downloads PBIX files from a SharePoint document library via Microsoft
    Graph API, extracts SQL connection info from TMDL using pbi-tools,
    fetches live Power BI report URLs, and writes everything to the
    ETL Control Panel app via HTTP (ingest_pbix.php -> SQLite).

    No SQL Server connection needed. All app data stays in SQLite.

.PREREQUISITES
    1. Power BI Desktop installed at default location:
       C:\Program Files\Microsoft Power BI Desktop\

    2. pbi-tools (desktop version) v1.2.0+
       Download: https://github.com/pbi-tools/pbi-tools/releases
       Extract to: C:\Tools\pbi-tools-desktop\pbi-tools.exe

    3. Azure AD app registration with application permissions:
       - Sites.Read.All
       - Files.Read.All
       - Report.Read.All
       Grant admin consent on all three.

    4. Add the app registration as Member of your Power BI workspace.

.NOTES
    Copy this file to Scan-PBIX_SharePoint.ps1 and fill in the variables.
    Scan-PBIX_SharePoint.ps1 is gitignored -- never commit it.
#>

# ── CONFIGURE THESE ──────────────────────────────────────────────────────────
# Azure AD app registration
$TenantId     = "your-tenant-id"
$ClientId     = "your-client-id"
$ClientSecret = "YourClientSecret"   # Never commit this

# SharePoint settings
$SharePointSite = "yourtenant.sharepoint.com:/sites/YourSite:"
$LibraryName    = "Documents"
$SubFolder      = "Power BI Reports"
$SPSiteUrl      = "https://yourtenant.sharepoint.com/sites/YourSite"

# ETL Control Panel app URL -- where ingest_pbix.php is running
$AppUrl = "http://localhost:8080"

# pbi-tools path
$PBITools    = "C:\Tools\pbi-tools-desktop\pbi-tools.exe"
$ExtractRoot = "C:\Temp\PBX"
# ─────────────────────────────────────────────────────────────────────────────

$startTime = Get-Date

function Write-Log {
    param([string]$Message, [string]$Color = "White")
    Write-Host $Message -ForegroundColor $Color
}

function Invoke-AppEndpoint {
    param([string]$Endpoint, [hashtable]$Body)
    try {
        $result = Invoke-RestMethod -Uri "$AppUrl/$Endpoint" -Method POST -Body $Body -ErrorAction Stop
        return $result
    } catch {
        Write-Log "  App endpoint failed ($Endpoint): $($_.Exception.Message)" "Yellow"
        return $null
    }
}

# ── Log start to ETL_Sync_Log ─────────────────────────────────────────────────
Invoke-AppEndpoint -Endpoint "log.php" -Body @{
    process_name = "PBIX SharePoint Scanner"
    status       = "Started"
    start_time   = $startTime.ToString("yyyy-MM-dd HH:mm:ss")
}

foreach ($dir in @($ExtractRoot, "C:\Temp\PBIXFiles")) {
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
}

# ── Authenticate with Microsoft Graph ────────────────────────────────────────
Write-Log "Authenticating with Microsoft Graph..." "Cyan"

$tokenUrl  = "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token"
$tokenBody = @{
    grant_type    = "client_credentials"
    client_id     = $ClientId
    client_secret = $ClientSecret
    scope         = "https://graph.microsoft.com/.default"
}

$tokenResponse = Invoke-RestMethod -Uri $tokenUrl -Method POST -Body $tokenBody
$accessToken   = $tokenResponse.access_token
$headers       = @{ Authorization = "Bearer $accessToken"; Accept = "application/json" }
Write-Log "Authenticated." "Green"

# ── Get SharePoint site and drive ─────────────────────────────────────────────
$siteId = (Invoke-RestMethod -Uri "https://graph.microsoft.com/v1.0/sites/$SharePointSite" -Headers $headers).id

$drive = (Invoke-RestMethod -Uri "https://graph.microsoft.com/v1.0/sites/$siteId/drives" -Headers $headers).value |
         Where-Object { $_.name -eq $LibraryName }

if (-not $drive) { Write-Error "Library '$LibraryName' not found."; exit 1 }

$pbixFiles = (Invoke-RestMethod -Uri "https://graph.microsoft.com/v1.0/drives/$($drive.id)/items/root:/$($SubFolder):/children" -Headers $headers).value |
             Where-Object { $_.name -like "*.pbix" }

Write-Log "Found $($pbixFiles.Count) PBIX file(s) in SharePoint" "Cyan"

# ── Clear previous scan ───────────────────────────────────────────────────────
Invoke-AppEndpoint -Endpoint "ingest_pbix.php" -Body @{ action = "clear" }
Write-Log "PBI_Connection_Map cleared." "Yellow"

# ── Helper: Parse TMDL files ──────────────────────────────────────────────────
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

# ── Process each PBIX file ────────────────────────────────────────────────────
$i            = 0
$totalRecords = 0

foreach ($item in $pbixFiles) {
    $i++
    Write-Log "[$i/$($pbixFiles.Count)] $($item.name)" "White"

    $localPath  = "C:\Temp\PBIXFiles\$($item.name)"
    $safeName   = [System.IO.Path]::GetFileNameWithoutExtension($item.name) -replace '[\\/:*?"<>|]', '_'
    $extractDir = "$ExtractRoot\$safeName"

    # Download
    try {
        Invoke-RestMethod -Uri "https://graph.microsoft.com/v1.0/drives/$($drive.id)/items/$($item.id)/content" `
                          -Headers $headers -OutFile $localPath
    } catch {
        Write-Log "  Download failed: $_" "Yellow"
        continue
    }

    # Extract TMDL
    if (Test-Path $extractDir) { Remove-Item $extractDir -Recurse -Force -ErrorAction SilentlyContinue }
    try {
        & $PBITools extract "$localPath" -extractFolder "$extractDir" -modelSerialization Tmdl 2>&1 | Out-Null
    } catch {
        Write-Log "  pbi-tools failed: $_" "Yellow"
        Remove-Item $localPath -Force -ErrorAction SilentlyContinue
        continue
    }

    # Parse and POST each connection row to the app
    $spPath  = if ($item.parentReference.path) { $item.parentReference.path } else { "" }
    $records = Parse-ExtractedTMDL -ExtractDir $extractDir -ReportName $item.name -SPPath $spPath

    foreach ($r in $records) {
        Invoke-AppEndpoint -Endpoint "ingest_pbix.php" -Body @{
            action          = "insert"
            report_file     = $r.Report_File
            sharepoint_path = $r.SharePoint_Path
            sharepoint_site = $r.SharePoint_Site
            server          = $r.Server
            database_name   = $r.Database_Name
            schema_name     = $r.Schema_Name
            view_or_table   = $r.View_Or_Table
            import_mode     = $r.Import_Mode
        }
    }

    $recCount = @($records).Count
    Write-Log "  -> $recCount connection(s) found" $(if ($recCount -gt 0) { "Green" } else { "DarkGray" })
    $totalRecords += $recCount

    Remove-Item $localPath  -Force -ErrorAction SilentlyContinue
    Remove-Item $extractDir -Recurse -Force -ErrorAction SilentlyContinue
}

# ── Fetch Power BI report URLs and update via app ─────────────────────────────
Write-Log "Fetching Power BI report URLs..." "Cyan"
try {
    $pbiToken = (Invoke-RestMethod -Uri $tokenUrl -Method POST -Body @{
        grant_type    = "client_credentials"
        client_id     = $ClientId
        client_secret = $ClientSecret
        scope         = "https://analysis.windows.net/powerbi/api/.default"
    }).access_token

    $pbiHeaders  = @{ Authorization = "Bearer $pbiToken"; Accept = "application/json" }
    $workspaces  = (Invoke-RestMethod -Uri "https://api.powerbi.com/v1.0/myorg/groups" -Headers $pbiHeaders).value
    $urlsUpdated = 0

    foreach ($ws in $workspaces) {
        try {
            $reports = (Invoke-RestMethod -Uri "https://api.powerbi.com/v1.0/myorg/groups/$($ws.id)/reports" -Headers $pbiHeaders).value
            foreach ($report in $reports) {
                $result = Invoke-AppEndpoint -Endpoint "ingest_pbix.php" -Body @{
                    action      = "update_url"
                    report_file = $report.name
                    report_url  = $report.webUrl
                }
                if ($result -and $result.rows -gt 0) { $urlsUpdated += $result.rows }
            }
        } catch {
            Write-Log "  Could not fetch reports for workspace '$($ws.name)': $_" "Yellow"
        }
    }
    Write-Log "  Updated $urlsUpdated row(s) with report URLs" "Green"
} catch {
    Write-Log "  Power BI URL fetch failed (non-fatal): $_" "Yellow"
}

# ── Log completion ────────────────────────────────────────────────────────────
$status = if ($totalRecords -eq 0) { "Warning" } else { "Success" }
$errMsg = if ($totalRecords -eq 0) { "No connections found -- check your PBIX files have SQL Server connections" } else { $null }

Invoke-AppEndpoint -Endpoint "log.php" -Body @{
    process_name  = "PBIX SharePoint Scanner"
    status        = $status
    record_count  = $totalRecords
    error_message = $errMsg
    start_time    = $startTime.ToString("yyyy-MM-dd HH:mm:ss")
}

Write-Log "Scan complete. $totalRecords connection(s) written to PBI_Connection_Map." "Cyan"
