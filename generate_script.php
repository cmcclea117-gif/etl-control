<?php
// ── generate_script.php ───────────────────────────────────────────────────────
// Assembles a source→destination ETL script based on process configuration.
// Saves to scripts/generated/ and sets as local_script in ETL_Processes.
// Also downloadable directly via GET ?process=key&download=1
//
// Supported source/dest types:
//   sqlserver, mysql, postgres, snowflake, oracle, mongodb, csv, api, none
//
// Supported exec types:
//   powershell, python, r, node
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/includes/db.php';

$processKey = trim($_GET['process'] ?? $_POST['process'] ?? '');
$download   = isset($_GET['download']);

if (!$processKey) {
    if (!defined('GENERATE_SCRIPT_INCLUDED')) { http_response_code(400); echo json_encode(['error' => 'process key required']); }
    return;
}

$conn = getDbConnection();
$row  = $conn->prepare('SELECT * FROM ETL_Processes WHERE process_key = ?');
$row->execute([$processKey]);
$proc = $row->fetch();

if (!$proc) {
    http_response_code(404);
    echo json_encode(['error' => "Process not found: $processKey"]);
    exit;
}

$execType  = $proc['exec_type']   ?? 'powershell';
$srcType   = $proc['source_type'] ?? 'none';
$dstType   = $proc['dest_type']   ?? 'none';
$logName   = $proc['log_process_name'];

// ── Determine file extension ──────────────────────────────────────────────────
$ext = match($execType) {
    'python' => 'py',
    'r'      => 'R',
    'node'   => 'js',
    default  => 'ps1',
};

$scriptName = "Invoke-" . ucfirst($processKey) . "ETL.$ext";
$scriptDir  = __DIR__ . '/scripts/generated';
$scriptPath = "$scriptDir/$scriptName";
$credsName  = "creds_$processKey.ini";
$credsPath  = __DIR__ . "/credentials/$credsName";
$credsExample = __DIR__ . "/credentials/creds_$processKey.example.ini";

if (!is_dir($scriptDir)) mkdir($scriptDir, 0755, true);

// ── Generate credentials template ────────────────────────────────────────────
$credsContent = generateCredsTemplate($proc);
file_put_contents($credsExample, $credsContent);
if (!file_exists($credsPath)) {
    file_put_contents($credsPath, $credsContent);
}

// ── Generate script ───────────────────────────────────────────────────────────
$script = match($execType) {
    'python' => generatePython($proc, $credsName),
    'r'      => generateR($proc, $credsName),
    'node'   => generateNode($proc, $credsName),
    default  => generatePowershell($proc, $credsName),
};

// Write with UTF-8 BOM for PowerShell
if ($execType === 'powershell') {
    file_put_contents($scriptPath, "\xEF\xBB\xBF" . $script);
} else {
    file_put_contents($scriptPath, $script);
}

// ── Update local_script in DB ─────────────────────────────────────────────────
$conn->prepare('UPDATE ETL_Processes SET generated_script = ?, local_script = ? WHERE process_key = ?')
     ->execute([$scriptPath, $scriptPath, $processKey]);

// ── Download or return JSON ───────────────────────────────────────────────────
if (!defined('GENERATE_SCRIPT_INCLUDED')) {
    if ($download) {
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"$scriptName\"");
        echo $script;
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'ok'          => true,
        'script_name' => $scriptName,
        'script_path' => $scriptPath,
        'creds_path'  => $credsPath,
    ]);
}

// ── Credential template generator ────────────────────────────────────────────
function generateCredsTemplate(array $proc): string {
    $srcType = $proc['source_type'] ?? 'none';
    $dstType = $proc['dest_type']   ?? 'none';
    $out = "# Credentials for {$proc['log_process_name']}\n";
    $out .= "# Copy this file to creds_{$proc['process_key']}.ini and fill in passwords.\n";
    $out .= "# This file is gitignored -- never commit real passwords.\n\n";

    if ($srcType !== 'none') {
        $defaultPort = defaultPort($srcType);
        $out .= "[source]\n";
        $out .= "# host can be overridden here or set in the Add Process form\n";
        $out .= "host     = " . ($proc['source_host']     ?? 'your-source-host') . "\n";
        $out .= "port     = " . ($proc['source_port']     ?? $defaultPort) . "\n";
        $out .= "database = " . ($proc['source_database'] ?? 'your_database') . "\n";
        $out .= "username = " . ($proc['source_username'] ?? 'your_user') . "\n";
        $out .= "password = YourSourcePassword\n\n";
    }

    if ($dstType !== 'none') {
        $defaultPort = defaultPort($dstType);
        $out .= "[destination]\n";
        $out .= "# host can be overridden here or set in the Add Process form\n";
        $out .= "host     = " . ($proc['dest_host']     ?? 'your-dest-host') . "\n";
        $out .= "port     = " . ($proc['dest_port']     ?? $defaultPort) . "\n";
        $out .= "database = " . ($proc['dest_database'] ?? 'your_dest_database') . "\n";
        $out .= "username = " . ($proc['dest_username'] ?? 'your_dest_user') . "\n";
        $out .= "password = YourDestPassword\n";
    }

    return $out;
}

function defaultPort(string $type): string {
    return match($type) {
        'sqlserver' => '1433',
        'mysql'     => '3306',
        'postgres'  => '5432',
        'snowflake' => '443',
        'oracle'    => '1521',
        'mongodb'   => '27017',
        default     => '',
    };
}

// ── PowerShell generator ──────────────────────────────────────────────────────
function generatePowershell(array $proc, string $credsName): string {
    $key      = $proc['process_key'];
    $logName  = $proc['log_process_name'];
    $srcType  = $proc['source_type']    ?? 'none';
    $dstType  = $proc['dest_type']      ?? 'none';
    $srcQuery = $proc['source_query']   ?? "SELECT * FROM your_table";
    $dstTable = $proc['dest_table']     ?? "dbo.YourTable";
    $dstSchema= $proc['dest_schema']    ?? "dbo";

    $srcBlock = powershellSourceBlock($srcType, $proc);
    $dstBlock = powershellDestBlock($dstType, $proc, $dstTable);
    $extractBlock = powershellExtractBlock($srcType);

    return <<<PS
#Requires -Version 5.1
<#
.SYNOPSIS
    Generated ETL script: {$logName}
    Source: {$srcType} -> Destination: {$dstType}
    Generated by ETL Control Panel SDK.

.NOTES
    Credentials: Fill in credentials/creds_{$key}.ini before running.
#>
param(
    [switch]\$TestMode,
    [string]\$LogUrl          = "",
    [string]\$LogProcessName  = "{$logName}"
)

\$ErrorActionPreference = "Stop"
\$startTime = Get-Date

function Write-Log {
    param([string]\$Message, [string]\$Level = "INFO")
    \$ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    \$color = switch (\$Level) { "SUCCESS"{"Green"} "WARNING"{"Yellow"} "ERROR"{"Red"} default{"White"} }
    Write-Host "[\$ts] [\$Level] \$Message" -ForegroundColor \$color
}

function Write-ETLLog {
    param([string]\$Status, [int]\$RecordCount = 0, [string]\$ErrorMessage = \$null)
    if (\$LogUrl) {
        try {
            \$body = @{ process_name = \$LogProcessName; status = \$Status; record_count = \$RecordCount; start_time = \$startTime.ToString("yyyy-MM-dd HH:mm:ss") }
            if (\$ErrorMessage) { \$body.error_message = \$ErrorMessage }
            Invoke-RestMethod -Uri \$LogUrl -Method POST -Body \$body | Out-Null
        } catch { Write-Log "HTTP log failed (non-fatal): \$_" "WARNING" }
    }
}

function Read-Credentials {
    param([string]\$Section)
    \$credsPath = Join-Path \$PSScriptRoot "..\\..\\credentials\\{$credsName}"
    if (-not (Test-Path \$credsPath)) { throw "Credentials file not found: \$credsPath" }
    \$creds = @{}
    \$inSection = \$false
    foreach (\$line in (Get-Content \$credsPath)) {
        if (\$line -match "^\[(\w+)\]") { \$inSection = (\$matches[1] -eq \$Section) }
        elseif (\$inSection -and \$line -match "^(\w+)\s*=\s*(.+)") { \$creds[\$matches[1].Trim()] = \$matches[2].Trim() }
    }
    return \$creds
}

try {
    Write-Log "=== \$LogProcessName started\$(if (\$TestMode) { ' [TEST MODE]' }) ==="
    Write-ETLLog -Status "Started"

    # ── Step 1: Connect to source ─────────────────────────────────────────────
    Write-Log "Step 1: Connecting to source ({$srcType})..."
{$srcBlock}

    # ── Step 2: Extract data ──────────────────────────────────────────────────
        Write-Log "Step 2: Extracting data..."
        \$query = "{$srcQuery}"
        if (\$TestMode) {
            if ("{$srcType}" -eq "mysql" -or "{$srcType}" -eq "postgres") { \$query += " LIMIT 100" }
            else { Write-Log "TestMode active — running full query (add TOP/LIMIT manually if needed)" "WARNING" }
        }
    {$extractBlock}

    # ── Step 3: Load to destination ───────────────────────────────────────────
    Write-Log "Step 3: Loading to destination ({$dstType})..."
{$dstBlock}

    Write-Log "=== \$LogProcessName completed. Records: \$recordCount ===" "SUCCESS"
    Write-ETLLog -Status "Success" -RecordCount \$recordCount

} catch {
    \$errMsg = \$_.Exception.Message
    Write-Log "=== \$LogProcessName FAILED: \$errMsg ===" "ERROR"
    Write-ETLLog -Status "Failed" -ErrorMessage \$errMsg
    if (\$srcConn -and \$srcConn.State -eq 'Open') { \$srcConn.Close() }
    if (\$dstConn -and \$dstConn.State -eq 'Open') { \$dstConn.Close() }
    exit 1
}
PS;
}

function powershellSourceBlock(string $type, array $proc): string {
    $host = $proc['source_host'] ?? 'your-source-host';
    return match($type) {
        'sqlserver' => <<<PS
    \$srcCreds = Read-Credentials -Section "source"
    \$srcHost  = if (\$srcCreds['host']) { \$srcCreds['host'] } else { "{$host}" }
    \$srcConn  = New-Object System.Data.SqlClient.SqlConnection(
        "Server=\$srcHost;Database=\$(\$srcCreds['database']);User ID=\$(\$srcCreds['username']);Password=\$(\$srcCreds['password']);TrustServerCertificate=True;")
    \$srcConn.Open()
    Write-Log "Source SQL Server connected." "SUCCESS"
PS,
        'mysql' => <<<PS
    \$srcCreds   = Read-Credentials -Section "source"
    \$srcHost    = if (\$srcCreds['host']) { \$srcCreds['host'] } else { "{$host}" }
    # Uses MySQL ODBC Connector -- install from https://dev.mysql.com/downloads/connector/odbc/
    \$srcConnStr = "Driver={MySQL ODBC 8.0 Unicode Driver};Server=\$srcHost;Port=\$(\$srcCreds['port']);Database=\$(\$srcCreds['database']);User=\$(\$srcCreds['username']);Password=\$(\$srcCreds['password']);Option=3;"
    \$srcConn   = New-Object System.Data.Odbc.OdbcConnection(\$srcConnStr)
    \$srcConn.Open()
    Write-Log "Source MySQL connected via ODBC." "SUCCESS"
PS,
        'postgres' => <<<PS
    \$srcCreds   = Read-Credentials -Section "source"
    \$srcHost    = if (\$srcCreds['host']) { \$srcCreds['host'] } else { "{$host}" }
    # Uses psqlODBC driver -- install from https://odbc.postgresql.org/
    # Or use Npgsql: place Npgsql.dll in scripts/lib/ and uncomment the LoadFile lines
    # \$npgsqlDll = "\$PSScriptRoot\..\..\lib\Npgsql.dll"
    # [Reflection.Assembly]::LoadFile(\$npgsqlDll) | Out-Null
    \$srcConnStr = "Driver={PostgreSQL Unicode};Server=\$srcHost;Port=\$(\$srcCreds['port']);Database=\$(\$srcCreds['database']);Uid=\$(\$srcCreds['username']);Pwd=\$(\$srcCreds['password']);"
    \$srcConn   = New-Object System.Data.Odbc.OdbcConnection(\$srcConnStr)
    \$srcConn.Open()
    Write-Log "Source PostgreSQL connected via ODBC." "SUCCESS"
PS,
        'csv' => <<<PS
    \$srcFile = "{$proc['source_query']}"
    if (-not (Test-Path \$srcFile)) { throw "Source CSV not found: \$srcFile" }
    \$srcData = Import-Csv \$srcFile
    Write-Log "Source CSV loaded: \$(\$srcData.Count) rows." "SUCCESS"
PS,
        default => "    # No source connection configured\n    \$srcData = @()",
    };
}

function powershellExtractBlock(string $type): string {
    return match($type) {
        'sqlserver' => <<<PS
    \$srcCmd             = \$srcConn.CreateCommand()
    \$srcCmd.CommandText = \$query
    \$adapter            = New-Object System.Data.SqlClient.SqlDataAdapter(\$srcCmd)
    \$srcData            = New-Object System.Data.DataTable
    \$adapter.Fill(\$srcData) | Out-Null
    \$recordCount        = \$srcData.Rows.Count
    Write-Log "Extracted \$recordCount rows from source." "SUCCESS"
PS,
        'mysql', 'postgres' => <<<PS
    \$srcCmd             = \$srcConn.CreateCommand()
    \$srcCmd.CommandText = \$query
    \$adapter            = New-Object System.Data.Odbc.OdbcDataAdapter(\$srcCmd)
    \$srcData            = New-Object System.Data.DataTable
    \$adapter.Fill(\$srcData) | Out-Null
    \$recordCount        = \$srcData.Rows.Count
    Write-Log "Extracted \$recordCount rows from source." "SUCCESS"
PS,
        'csv' => <<<PS
    \$srcData     = Import-Csv \$srcFile
    \$recordCount = \$srcData.Count
    Write-Log "Loaded \$recordCount rows from CSV." "SUCCESS"
PS,
        default => "    \$srcData = @()\n    \$recordCount = 0",
    };
}

function powershellDestBlock(string $type, array $proc, string $dstTable): string {
    $host = $proc['dest_host'] ?? 'your-dest-host';
    return match($type) {
        'sqlserver' => <<<PS
    \$dstCreds = Read-Credentials -Section "destination"
    \$dstHost  = if (\$dstCreds['host']) { \$dstCreds['host'] } else { "{$host}" }
    \$dstConn  = New-Object System.Data.SqlClient.SqlConnection(
        "Server=\$dstHost;Database=\$(\$dstCreds['database']);User ID=\$(\$dstCreds['username']);Password=\$(\$dstCreds['password']);TrustServerCertificate=True;")
    \$dstConn.Open()
    \$bulk                      = New-Object System.Data.SqlClient.SqlBulkCopy(\$dstConn)
    \$bulk.DestinationTableName = "{$dstTable}"
    \$bulk.BulkCopyTimeout      = 300
    \$bulk.WriteToServer(\$srcData)
    \$dstConn.Close()
    Write-Log "Loaded \$recordCount rows to {$dstTable}." "SUCCESS"
PS,
        'mysql' => <<<PS
    \$dstCreds   = Read-Credentials -Section "destination"
    \$dstHost    = if (\$dstCreds['host']) { \$dstCreds['host'] } else { "{$host}" }
    \$dstConnStr = "Driver={MySQL ODBC 8.0 Unicode Driver};Server=\$dstHost;Port=\$(\$dstCreds['port']);Database=\$(\$dstCreds['database']);User=\$(\$dstCreds['username']);Password=\$(\$dstCreds['password']);Option=3;"
    \$dstConn   = New-Object System.Data.Odbc.OdbcConnection(\$dstConnStr)
    \$dstConn.Open()
    # TODO: insert rows into {$dstTable} via OdbcCommand
    Write-Log "Destination MySQL connected via ODBC." "SUCCESS"
PS,
        'postgres' => <<<PS
    \$dstCreds   = Read-Credentials -Section "destination"
    \$dstHost    = if (\$dstCreds['host']) { \$dstCreds['host'] } else { "{$host}" }
    \$dstConnStr = "Driver={PostgreSQL Unicode};Server=\$dstHost;Port=\$(\$dstCreds['port']);Database=\$(\$dstCreds['database']);Uid=\$(\$dstCreds['username']);Pwd=\$(\$dstCreds['password']);"
    \$dstConn   = New-Object System.Data.Odbc.OdbcConnection(\$dstConnStr)
    \$dstConn.Open()
    # TODO: insert rows into {$dstTable}
    Write-Log "Destination PostgreSQL connected." "SUCCESS"
PS,
        'csv' => <<<PS
    \$outPath = [System.IO.Path]::Combine([System.IO.Path]::GetTempPath(), "{$dstTable}_\$(Get-Date -Format 'yyyyMMdd_HHmmss').csv")
    # TODO: \$srcData | Export-Csv \$outPath -NoTypeInformation
    Write-Log "Output CSV: \$outPath" "SUCCESS"
PS,
        default => "    # No destination configured",
    };
}

// ── Python generator ──────────────────────────────────────────────────────────
function generatePython(array $proc, string $credsName): string {
    $key     = $proc['process_key'];
    $logName = $proc['log_process_name'];
    $srcType = $proc['source_type'] ?? 'none';
    $dstType = $proc['dest_type']   ?? 'none';
    $srcQuery = $proc['source_query'] ?? "SELECT * FROM your_table";
    $dstTable = $proc['dest_table']   ?? "your_table";

    $imports    = pythonImports($srcType, $dstType);
    $srcConnect = pythonSourceConnect($srcType);
    $dstConnect = pythonDestConnect($dstType);
    $requirements = pythonRequirements($srcType, $dstType);

    return <<<PY
#!/usr/bin/env python3
"""
Generated ETL script: {$logName}
Source: {$srcType} -> Destination: {$dstType}
Generated by ETL Control Panel SDK.

Requirements:
{$requirements}

Credentials: Fill in credentials/creds_{$key}.ini before running.
"""

import argparse
import configparser
import datetime
import os
import sys
{$imports}

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False

# ── Args ──────────────────────────────────────────────────────────────────────
parser = argparse.ArgumentParser()
parser.add_argument('--test-mode',        action='store_true')
parser.add_argument('--log-url',          default='')
parser.add_argument('--log-process-name', default='{$logName}')
args = parser.parse_args()

PROCESS_NAME = args.log_process_name
START_TIME   = datetime.datetime.now()
SCRIPT_DIR   = os.path.dirname(os.path.abspath(__file__))
CREDS_FILE   = os.path.join(SCRIPT_DIR, '..', '..', 'credentials', '{$credsName}')

def log(message, level='INFO'):
    ts = datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f'[{ts}] [{level}] {message}')

def log_etl(status, record_count=0, error_message=None):
    if args.log_url and HAS_REQUESTS:
        try:
            payload = {'process_name': PROCESS_NAME, 'status': status,
                       'record_count': record_count,
                       'start_time': START_TIME.strftime('%Y-%m-%d %H:%M:%S')}
            if error_message: payload['error_message'] = error_message
            requests.post(args.log_url, data=payload, timeout=10)
        except Exception as e:
            log(f'HTTP log failed (non-fatal): {e}', 'WARNING')

def read_creds(section):
    if not os.path.exists(CREDS_FILE):
        raise FileNotFoundError(f'Credentials file not found: {CREDS_FILE}')
    config = configparser.ConfigParser()
    config.read(CREDS_FILE)
    return dict(config[section]) if section in config else {}

# ── Main ──────────────────────────────────────────────────────────────────────
try:
    log(f'=== {PROCESS_NAME} started{"  [TEST MODE]" if args.test_mode else ""} ===')
    log_etl('Started')

    # ── Step 1: Connect to source ─────────────────────────────────────────────
    log(f'Step 1: Connecting to source ({$srcType})...')
{$srcConnect}

    # ── Step 2: Extract data ──────────────────────────────────────────────────
    log('Step 2: Extracting data...')
    query = """{$srcQuery}"""
    # TODO: execute query and fetch results
    # df = pd.read_sql(query, src_conn)
    record_count = 0  # TODO: set to len(df) or actual count

    # ── Step 3: Load to destination ───────────────────────────────────────────
    log(f'Step 3: Loading to destination ({$dstType})...')
{$dstConnect}

    log(f'=== {PROCESS_NAME} completed. Records: {record_count} ===', 'SUCCESS')
    log_etl('Success', record_count)

except Exception as e:
    log(f'=== {PROCESS_NAME} FAILED: {e} ===', 'ERROR')
    log_etl('Failed', error_message=str(e))
    sys.exit(1)
PY;
}

function pythonImports(string $src, string $dst): string {
    $imports = [];
    foreach ([$src, $dst] as $type) {
        match($type) {
            'sqlserver' => $imports[] = "import pyodbc",
            'mysql'     => $imports[] = "import mysql.connector",
            'postgres'  => $imports[] = "import psycopg2",
            'snowflake' => $imports[] = "import snowflake.connector",
            'oracle'    => $imports[] = "import cx_Oracle",
            'mongodb'   => $imports[] = "import pymongo",
            default     => null,
        };
    }
    if (in_array($src, ['sqlserver','mysql','postgres','snowflake'])) {
        $imports[] = "import pandas as pd";
    }
    return implode("\n", array_unique($imports));
}

function pythonRequirements(string $src, string $dst): string {
    $reqs = [];
    foreach ([$src, $dst] as $type) {
        match($type) {
            'sqlserver' => $reqs[] = "  pip install pyodbc pandas",
            'mysql'     => $reqs[] = "  pip install mysql-connector-python pandas",
            'postgres'  => $reqs[] = "  pip install psycopg2-binary pandas",
            'snowflake' => $reqs[] = "  pip install snowflake-connector-python pandas",
            'oracle'    => $reqs[] = "  pip install cx_Oracle pandas",
            'mongodb'   => $reqs[] = "  pip install pymongo",
            default     => null,
        };
    }
    $reqs[] = "  pip install requests";
    return implode("\n", array_unique($reqs));
}

function pythonSourceConnect(string $type): string {
    return match($type) {
        'sqlserver' => <<<PY
    src_creds = read_creds('source')
    src_conn = pyodbc.connect(
        f"DRIVER={{ODBC Driver 17 for SQL Server}};"
        f"SERVER={src_creds.get('host')},{src_creds.get('port', 1433)};"
        f"DATABASE={src_creds.get('database')};"
        f"UID={src_creds.get('username')};PWD={src_creds.get('password')};"
        f"TrustServerCertificate=yes;"
    )
    log('Source SQL Server connected.', 'SUCCESS')
PY,
        'mysql' => <<<PY
    src_creds = read_creds('source')
    src_conn = mysql.connector.connect(
        host=src_creds.get('host'),
        port=int(src_creds.get('port', 3306)),
        database=src_creds.get('database'),
        user=src_creds.get('username'),
        password=src_creds.get('password'),
    )
    log('Source MySQL connected.', 'SUCCESS')
PY,
        'postgres' => <<<PY
    src_creds = read_creds('source')
    src_conn = psycopg2.connect(
        host=src_creds.get('host'),
        port=int(src_creds.get('port', 5432)),
        dbname=src_creds.get('database'),
        user=src_creds.get('username'),
        password=src_creds.get('password'),
    )
    log('Source PostgreSQL connected.', 'SUCCESS')
PY,
        'snowflake' => <<<PY
    src_creds = read_creds('source')
    src_conn = snowflake.connector.connect(
        account=src_creds.get('host'),
        user=src_creds.get('username'),
        password=src_creds.get('password'),
        database=src_creds.get('database'),
    )
    log('Source Snowflake connected.', 'SUCCESS')
PY,
        'mongodb' => <<<PY
    src_creds = read_creds('source')
    src_client = pymongo.MongoClient(
        host=src_creds.get('host'),
        port=int(src_creds.get('port', 27017)),
        username=src_creds.get('username'),
        password=src_creds.get('password'),
    )
    src_db = src_client[src_creds.get('database')]
    log('Source MongoDB connected.', 'SUCCESS')
PY,
        'csv' => <<<PY
    src_file = "your_source_file.csv"  # TODO: set source file path
    df = pd.read_csv(src_file)
    log(f'Source CSV loaded: {len(df)} rows.', 'SUCCESS')
PY,
        default => "    # No source connection configured\n    log('No source configured.', 'WARNING')",
    };
}

function pythonDestConnect(string $type): string {
    return match($type) {
        'sqlserver' => <<<PY
    dst_creds = read_creds('destination')
    dst_conn = pyodbc.connect(
        f"DRIVER={{ODBC Driver 17 for SQL Server}};"
        f"SERVER={dst_creds.get('host')},{dst_creds.get('port', 1433)};"
        f"DATABASE={dst_creds.get('database')};"
        f"UID={dst_creds.get('username')};PWD={dst_creds.get('password')};"
        f"TrustServerCertificate=yes;"
    )
    # TODO: df.to_sql('your_table', dst_conn, if_exists='append', index=False)
    log('Destination SQL Server connected.', 'SUCCESS')
PY,
        'mysql' => <<<PY
    dst_creds = read_creds('destination')
    dst_conn = mysql.connector.connect(
        host=dst_creds.get('host'),
        port=int(dst_creds.get('port', 3306)),
        database=dst_creds.get('database'),
        user=dst_creds.get('username'),
        password=dst_creds.get('password'),
    )
    # TODO: insert rows into destination table
    log('Destination MySQL connected.', 'SUCCESS')
PY,
        'postgres' => <<<PY
    dst_creds = read_creds('destination')
    dst_conn = psycopg2.connect(
        host=dst_creds.get('host'),
        port=int(dst_creds.get('port', 5432)),
        dbname=dst_creds.get('database'),
        user=dst_creds.get('username'),
        password=dst_creds.get('password'),
    )
    # TODO: insert rows into destination table
    log('Destination PostgreSQL connected.', 'SUCCESS')
PY,
        'csv' => <<<PY
    import tempfile, os
    out_path = os.path.join(tempfile.gettempdir(), f"output_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}.csv")
    # TODO: df.to_csv(out_path, index=False)
    log(f'Output CSV: {out_path}', 'SUCCESS')
PY,
        default => "    # No destination configured\n    log('No destination configured.', 'WARNING')",
    };
}

// ── R generator ───────────────────────────────────────────────────────────────
function generateR(array $proc, string $credsName): string {
    $key     = $proc['process_key'];
    $logName = $proc['log_process_name'];
    $srcType = $proc['source_type'] ?? 'none';
    $dstType = $proc['dest_type']   ?? 'none';
    $srcQuery = $proc['source_query'] ?? "SELECT * FROM your_table";

    $srcBlock = rSourceBlock($srcType);
    $dstBlock = rDestBlock($dstType);
    $packages = rPackages($srcType, $dstType);

    return <<<RSCRIPT
#!/usr/bin/env Rscript
# Generated ETL script: {$logName}
# Source: {$srcType} -> Destination: {$dstType}
# Generated by ETL Control Panel SDK.
#
# Requirements: {$packages}
# Credentials: Fill in credentials/creds_{$key}.ini before running.

args         <- commandArgs(trailingOnly = TRUE)
test_mode    <- "--test-mode" %in% args
log_url      <- ""
process_name <- "{$logName}"
for (i in seq_along(args)) {
  if (args[i] == "--log-url"          && i < length(args)) log_url      <- args[i + 1]
  if (args[i] == "--log-process-name" && i < length(args)) process_name <- args[i + 1]
}

start_time  <- Sys.time()
script_dir  <- dirname(sys.frame(1)\$ofile)
creds_file  <- file.path(script_dir, '..', '..', 'credentials', '{$credsName}')

log_msg <- function(message, level = "INFO") {
  cat(sprintf("[%s] [%s] %s\n", format(Sys.time(), "%Y-%m-%d %H:%M:%S"), level, message))
}

log_etl <- function(status, record_count = 0, error_message = NULL) {
  if (nchar(log_url) > 0 && requireNamespace("httr", quietly = TRUE)) {
    tryCatch({
      payload <- list(process_name=process_name, status=status,
                      record_count=as.character(record_count),
                      start_time=format(start_time, "%Y-%m-%d %H:%M:%S"))
      if (!is.null(error_message)) payload\$error_message <- error_message
      httr::POST(log_url, body=payload, encode="form")
    }, error = function(e) log_msg(paste("HTTP log failed:", e\$message), "WARNING"))
  }
}

read_creds <- function(section) {
  if (!file.exists(creds_file)) stop(paste("Credentials file not found:", creds_file))
  lines <- readLines(creds_file)
  creds <- list()
  in_section <- FALSE
  for (line in lines) {
    if (grepl(paste0("^\\\\[", section, "\\\\]"), line)) { in_section <- TRUE; next }
    if (grepl("^\\\\[", line)) { in_section <- FALSE; next }
    if (in_section && grepl("=", line)) {
      parts <- strsplit(line, "\\\\s*=\\\\s*")[[1]]
      creds[[trimws(parts[1])]] <- trimws(parts[2])
    }
  }
  creds
}

tryCatch({
  log_msg(paste("===", process_name, "started", if (test_mode) "[TEST MODE]" else ""))
  log_etl("Started")

  # ── Step 1: Connect to source ───────────────────────────────────────────────
  log_msg(paste("Step 1: Connecting to source ({$srcType})..."))
{$srcBlock}

  # ── Step 2: Extract data ────────────────────────────────────────────────────
  log_msg("Step 2: Extracting data...")
  query <- "{$srcQuery}"
  # TODO: df <- dbGetQuery(src_conn, query)
  record_count <- 0  # TODO: nrow(df)

  # ── Step 3: Load to destination ─────────────────────────────────────────────
  log_msg(paste("Step 3: Loading to destination ({$dstType})..."))
{$dstBlock}

  log_msg(paste("===", process_name, "completed. Records:", record_count), "SUCCESS")
  log_etl("Success", record_count)

}, error = function(e) {
  log_msg(paste("===", process_name, "FAILED:", e\$message), "ERROR")
  log_etl("Failed", error_message = e\$message)
  quit(status = 1)
})
RSCRIPT;
}

function rPackages(string $src, string $dst): string {
    $pkgs = ["httr"];
    foreach ([$src, $dst] as $type) {
        match($type) {
            'sqlserver' => array_push($pkgs, "RODBC", "DBI"),
            'mysql'     => array_push($pkgs, "RMySQL", "DBI"),
            'postgres'  => array_push($pkgs, "RPostgres", "DBI"),
            'mongodb'   => array_push($pkgs, "mongolite"),
            default     => null,
        };
    }
    return "install.packages(c('" . implode("','", array_unique($pkgs)) . "'))";
}

function rSourceBlock(string $type): string {
    return match($type) {
        'sqlserver' => <<<RBLOCK
  src_creds <- read_creds("source")
  src_conn  <- DBI::dbConnect(odbc::odbc(),
    Driver   = "ODBC Driver 17 for SQL Server",
    Server   = src_creds[["host"]],
    Database = src_creds[["database"]],
    UID      = src_creds[["username"]],
    PWD      = src_creds[["password"]])
  log_msg("Source SQL Server connected.", "SUCCESS")
RBLOCK,
        'mysql' => <<<RBLOCK
  src_creds <- read_creds("source")
  src_conn  <- DBI::dbConnect(RMySQL::MySQL(),
    host     = src_creds[["host"]],
    port     = as.integer(src_creds[["port"]]),
    dbname   = src_creds[["database"]],
    username = src_creds[["username"]],
    password = src_creds[["password"]])
  log_msg("Source MySQL connected.", "SUCCESS")
RBLOCK,
        'postgres' => <<<RBLOCK
  src_creds <- read_creds("source")
  src_conn  <- DBI::dbConnect(RPostgres::Postgres(),
    host     = src_creds[["host"]],
    port     = as.integer(src_creds[["port"]]),
    dbname   = src_creds[["database"]],
    user     = src_creds[["username"]],
    password = src_creds[["password"]])
  log_msg("Source PostgreSQL connected.", "SUCCESS")
RBLOCK,
        'csv' => <<<RBLOCK
  src_file <- "your_source.csv"  # TODO: set path
  df <- read.csv(src_file)
  log_msg(paste("Source CSV loaded:", nrow(df), "rows."), "SUCCESS")
RBLOCK,
        default => "  # No source configured\n  log_msg('No source configured.', 'WARNING')",
    };
}

function rDestBlock(string $type): string {
    return match($type) {
        'sqlserver' => "  dst_creds <- read_creds(\"destination\")\n  # TODO: DBI::dbWriteTable(dst_conn, 'your_table', df, append=TRUE)\n  log_msg('Destination SQL Server ready.', 'SUCCESS')",
        'mysql'     => "  dst_creds <- read_creds(\"destination\")\n  dst_conn  <- DBI::dbConnect(RMySQL::MySQL(), host=dst_creds[['host']], port=as.integer(dst_creds[['port']]), dbname=dst_creds[['database']], username=dst_creds[['username']], password=dst_creds[['password']])\n  # TODO: DBI::dbWriteTable(dst_conn, 'your_table', df, append=TRUE)\n  log_msg('Destination MySQL ready.', 'SUCCESS')",
        'postgres'  => "  dst_creds <- read_creds(\"destination\")\n  dst_conn  <- DBI::dbConnect(RPostgres::Postgres(), host=dst_creds[['host']], port=as.integer(dst_creds[['port']]), dbname=dst_creds[['database']], user=dst_creds[['username']], password=dst_creds[['password']])\n  # TODO: DBI::dbWriteTable(dst_conn, 'your_table', df, append=TRUE)\n  log_msg('Destination PostgreSQL ready.', 'SUCCESS')",
        'csv'       => "  out_path <- file.path(tempdir(), paste0('output_', format(Sys.time(), '%Y%m%d_%H%M%S'), '.csv'))\n  # TODO: write.csv(df, out_path, row.names=FALSE)\n  log_msg(paste('Output CSV:', out_path), 'SUCCESS')",
        default     => "  # No destination configured",
    };
}

// ── Node.js generator ─────────────────────────────────────────────────────────
function generateNode(array $proc, string $credsName): string {
    $key     = $proc['process_key'];
    $logName = $proc['log_process_name'];
    $srcType = $proc['source_type'] ?? 'none';
    $dstType = $proc['dest_type']   ?? 'none';
    $srcQuery = $proc['source_query'] ?? "SELECT * FROM your_table";

    $requires    = nodeRequires($srcType, $dstType);
    $srcBlock    = nodeSourceBlock($srcType);
    $dstBlock    = nodeDestBlock($dstType);
    $npmInstall  = nodeNpmInstall($srcType, $dstType);

    return <<<JS
#!/usr/bin/env node
// Generated ETL script: {$logName}
// Source: {$srcType} -> Destination: {$dstType}
// Generated by ETL Control Panel SDK.
//
// Requirements: {$npmInstall}
// Credentials: Fill in credentials/creds_{$key}.ini before running.

'use strict';

const fs   = require('fs');
const path = require('path');
const http = require('http');
const https= require('https');
{$requires}

const args        = process.argv.slice(2);
const testMode    = args.includes('--test-mode');
let   logUrl      = '';
let   processName = '{$logName}';
for (let i = 0; i < args.length; i++) {
    if (args[i] === '--log-url'          && args[i+1]) logUrl      = args[i+1];
    if (args[i] === '--log-process-name' && args[i+1]) processName = args[i+1];
}

const startTime = new Date();
const credsFile = path.join(__dirname, '..', '..', 'credentials', '{$credsName}');

function fmtLocal(d) {
    const p = n => String(n).padStart(2,'0');
    return `\${d.getFullYear()}-\${p(d.getMonth()+1)}-\${p(d.getDate())} \${p(d.getHours())}:\${p(d.getMinutes())}:\${p(d.getSeconds())}`;
}

function logMsg(message, level='INFO') {
    const ts = fmtLocal(new Date());
    console.log(`[\${ts}] [\${level}] \${message}`);
}

function postForm(url, data) {
    return new Promise((resolve, reject) => {
        const body    = Object.entries(data).map(([k,v]) => `\${encodeURIComponent(k)}=\${encodeURIComponent(v??'')}`).join('&');
        const parsed  = new URL(url);
        const lib     = parsed.protocol === 'https:' ? https : http;
        const options = { hostname: parsed.hostname, port: parsed.port||(parsed.protocol==='https:'?443:80), path: parsed.pathname+parsed.search, method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body) } };
        const req = lib.request(options, res => { let d=''; res.on('data',c=>d+=c); res.on('end',()=>resolve(d)); });
        req.on('error', reject); req.write(body); req.end();
    });
}

async function logEtl(status, recordCount=0, errorMessage=null) {
    if (!logUrl) return;
    try {
        const payload = { process_name: processName, status, record_count: recordCount, start_time: fmtLocal(startTime) };
        if (errorMessage) payload.error_message = errorMessage;
        await postForm(logUrl, payload);
    } catch(e) { logMsg(`HTTP log failed (non-fatal): \${e.message}`, 'WARNING'); }
}

function readCreds(section) {
    if (!fs.existsSync(credsFile)) throw new Error(`Credentials file not found: \${credsFile}`);
    const creds = {};
    let inSection = false;
    for (const line of fs.readFileSync(credsFile, 'utf8').split('\n')) {
        if (line.match(/^\[(\w+)\]/)) { inSection = line.match(/^\[(\w+)\]/)[1] === section; continue; }
        if (inSection && line.includes('=')) {
            const [k, ...v] = line.split('=');
            creds[k.trim()] = v.join('=').trim();
        }
    }
    return creds;
}

async function main() {
    logMsg(`=== \${processName} started \${testMode?'[TEST MODE]':''} ===`);
    await logEtl('Started');

    // ── Step 1: Connect to source ─────────────────────────────────────────────
    logMsg(`Step 1: Connecting to source ({$srcType})...`);
{$srcBlock}

    // ── Step 2: Extract data ──────────────────────────────────────────────────
    logMsg('Step 2: Extracting data...');
    const query = `{$srcQuery}`;
    // TODO: execute query against source connection
    let recordCount = 0;  // TODO: set to actual count

    // ── Step 3: Load to destination ───────────────────────────────────────────
    logMsg(`Step 3: Loading to destination ({$dstType})...`);
{$dstBlock}

    logMsg(`=== \${processName} completed. Records: \${recordCount} ===`, 'SUCCESS');
    await logEtl('Success', recordCount);
}

main().catch(async e => {
    logMsg(`=== \${processName} FAILED: \${e.message} ===`, 'ERROR');
    await logEtl('Failed', 0, e.message);
    process.exit(1);
});
JS;
}

function nodeRequires(string $src, string $dst): string {
    $reqs = [];
    foreach ([$src, $dst] as $type) {
        match($type) {
            'sqlserver' => $reqs[] = "const sql  = require('mssql');",
            'mysql'     => $reqs[] = "const mysql = require('mysql2/promise');",
            'postgres'  => $reqs[] = "const { Pool } = require('pg');",
            'mongodb'   => $reqs[] = "const { MongoClient } = require('mongodb');",
            default     => null,
        };
    }
    return implode("\n", array_unique($reqs));
}

function nodeNpmInstall(string $src, string $dst): string {
    $pkgs = [];
    foreach ([$src, $dst] as $type) {
        match($type) {
            'sqlserver' => $pkgs[] = "mssql",
            'mysql'     => $pkgs[] = "mysql2",
            'postgres'  => $pkgs[] = "pg",
            'mongodb'   => $pkgs[] = "mongodb",
            default     => null,
        };
    }
    return empty($pkgs) ? "none" : "npm install " . implode(" ", array_unique($pkgs));
}

function nodeSourceBlock(string $type): string {
    return match($type) {
        'sqlserver' => <<<JS
    const srcCreds = readCreds('source');
    await sql.connect({ server: srcCreds.host, port: parseInt(srcCreds.port||1433), database: srcCreds.database, user: srcCreds.username, password: srcCreds.password, options: { trustServerCertificate: true } });
    logMsg('Source SQL Server connected.', 'SUCCESS');
JS,
        'mysql' => <<<JS
    const srcCreds  = readCreds('source');
    const srcConn   = await mysql.createConnection({ host: srcCreds.host, port: parseInt(srcCreds.port||3306), database: srcCreds.database, user: srcCreds.username, password: srcCreds.password });
    logMsg('Source MySQL connected.', 'SUCCESS');
JS,
        'postgres' => <<<JS
    const srcCreds = readCreds('source');
    const srcPool  = new Pool({ host: srcCreds.host, port: parseInt(srcCreds.port||5432), database: srcCreds.database, user: srcCreds.username, password: srcCreds.password });
    logMsg('Source PostgreSQL connected.', 'SUCCESS');
JS,
        'mongodb' => <<<JS
    const srcCreds  = readCreds('source');
    const srcUri    = `mongodb://\${srcCreds.username}:\${srcCreds.password}@\${srcCreds.host}:\${srcCreds.port||27017}`;
    const srcClient = new MongoClient(srcUri);
    await srcClient.connect();
    const srcDb = srcClient.db(srcCreds.database);
    logMsg('Source MongoDB connected.', 'SUCCESS');
JS,
        'csv' => <<<JS
    const srcFile = 'your_source.csv';  // TODO: set path
    // TODO: parse CSV -- consider using 'csv-parse' npm package
    logMsg(`Source CSV: \${srcFile}`, 'SUCCESS');
JS,
        default => "    // No source configured\n    logMsg('No source configured.', 'WARNING');",
    };
}

function nodeDestBlock(string $type): string {
    return match($type) {
        'sqlserver' => "    const dstCreds = readCreds('destination');\n    // TODO: connect and insert rows\n    logMsg('Destination SQL Server ready.', 'SUCCESS');",
        'mysql'     => "    const dstCreds = readCreds('destination');\n    const dstConn  = await mysql.createConnection({ host: dstCreds.host, port: parseInt(dstCreds.port||3306), database: dstCreds.database, user: dstCreds.username, password: dstCreds.password });\n    // TODO: insert rows\n    logMsg('Destination MySQL ready.', 'SUCCESS');",
        'postgres'  => "    const dstCreds = readCreds('destination');\n    const dstPool  = new Pool({ host: dstCreds.host, port: parseInt(dstCreds.port||5432), database: dstCreds.database, user: dstCreds.username, password: dstCreds.password });\n    // TODO: insert rows\n    logMsg('Destination PostgreSQL ready.', 'SUCCESS');",
        'csv'       => "    const outPath = require('os').tmpdir() + '/output_' + Date.now() + '.csv';\n    // TODO: write output CSV\n    logMsg(`Output CSV: \${outPath}`, 'SUCCESS');",
        default     => "    // No destination configured",
    };
}
