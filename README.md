# ETL Control Panel SDK

A config-driven web control panel for ad-hoc execution and monitoring of ETL/ELT processes. Run it locally for development or deploy to IIS for production.

**All app data is stored in SQLite** — no SQL Server required for the control panel itself. ETL scripts connect to whatever sources they need independently.

---

## Quick Start

1. Install PHP 8.x (see Prerequisites)
2. Copy `config/app.example.php` to `config/app.php` and fill in your values
3. Double-click `start-local.bat`
4. Browse to `http://localhost:8080`

---

## Prerequisites

### PHP 8.x
Download from https://windows.php.net/download/ (Thread Safe, x64)

Extract to `C:\PHP` and run `install.bat` — it handles the rest.

Required extensions (enabled by `install.bat`):
- `php_sqlite3.dll`
- `php_pdo_sqlite.dll`
- `php_openssl.dll`
- `php_curl.dll`

### Per exec type
| Exec Type | Requirement |
|-----------|-------------|
| PowerShell | PowerShell 5.1+ (built into Windows) |
| Python | Python 3.x + `pip install requests` |
| R | R 4.x + `install.packages("httr")` |
| Node.js | Node.js 18+ |
| SSIS | SQL Server with SSISDB catalog |
| SQL Agent | SQL Server Agent enabled |

---

## Architecture

```
etl-control/
├── index.php                    Dashboard -- ETL Processes tab
├── dependency-chain.php         Dependency Chain tab
├── trigger.php                  Async trigger endpoint
├── status.php                   Status polling + history endpoint
├── log.php                      HTTP logging endpoint (scripts POST here)
├── log.php                      HTTP logging endpoint
├── ingest_pbix.php              PBIX scanner ingestion endpoint
├── seed_viewmap.php             Seeds SQL_View_Division_Map from PBI_Connection_Map
├── add_process.php              Add / Edit / Delete process UI
├── save_process.php             Saves process to ETL_Processes table
├── generate_wrapper.php         Downloads pre-filled WinRM wrapper scripts
├── router.php                   PHP built-in server router (local mode only)
├── install.bat                  One-shot PHP installer
├── start-local.bat              Double-click to start local server
├── Scan-PBIX_SharePoint.example.ps1  PBIX scanner template
├── Register-AllETLTasks.ps1     Registers all on-demand tasks on web server
├── config/
│   ├── app.example.php          Copy to app.php and fill in your values
│   └── processes.php            ETL process registry (hardcoded + DB merge)
├── includes/
│   ├── db.php                   SQLite connection + schema bootstrap
│   ├── auth.php                 Windows auth helper
│   ├── tab_nav.php              Shared tab navigation
│   └── process_panel.php        Reusable process panel partial
├── scripts/
│   ├── Invoke-HelloWorldETL.ps1       PowerShell example
│   ├── Invoke-CsvImportETL.ps1        File-based ETL example
│   ├── Invoke-RefreshViewMapETL.ps1   Seeds SQL_View_Division_Map
│   ├── Invoke-SsisLocal.ps1           SSIS package executor
│   ├── Invoke-SqlAgentLocal.ps1       SQL Agent job executor
│   ├── invoke_hello_world_py.py       Python example
│   ├── invoke_hello_world_r.R         R example
│   └── invoke_hello_world_node.js     Node.js example
└── data/
    └── etl_control.db           SQLite database (auto-created, gitignored)
```

### SQLite Tables
| Table | Purpose |
|-------|---------|
| `ETL_Sync_Log` | Run history for all processes |
| `ETL_Processes` | User-added processes (via Add Process UI) |
| `ETL_Process_Docs` | Inline documentation for processes |
| `PBI_Connection_Map` | PBIX scanner results |
| `SQL_View_Division_Map` | SQL view to division mapping |

---

## Exec Types

Six exec types are supported. Each has a built-in example script in `scripts/`.

| Type | Local script | Production wrapper |
|------|-------------|-------------------|
| `powershell` | `.ps1` via PowerShell | WinRM → remote `.ps1` |
| `python` | `.py` via python.exe | WinRM → remote `.py` |
| `r` | `.R` via Rscript.exe | WinRM → remote `.R` |
| `node` | `.js` via node.exe | WinRM → remote `.js` |
| `ssis` | `Invoke-SsisLocal.ps1` (built-in) | WinRM → SSISDB catalog |
| `sqlagent` | `Invoke-SqlAgentLocal.ps1` (built-in) | WinRM → sp_start_job |

**SSIS and SQL Agent** automatically use the built-in local scripts — no `local_script` path needed in the process config.

---

## Adding a Process

### Via the UI (recommended)
1. Click **+ Add Process** on the dashboard
2. Fill in the form — key, name, exec type, connection details
3. Click **+ Add Process** — card appears instantly
4. Click **↓ WRAPPER** to download a pre-filled WinRM wrapper for production deployment

### Via config/processes.php (for SDK defaults)
Copy one of the existing example entries and add your own. Hardcoded processes appear for all users and cannot be deleted via the UI.

---

## HTTP Logging

Scripts log back to the app via HTTP POST to `log.php`. This keeps all app data in SQLite regardless of where the script runs.

```powershell
# PowerShell
Invoke-RestMethod -Uri $LogUrl -Method POST -Body @{
    process_name  = "My ETL"
    status        = "Success"   # Started | Success | Failed
    record_count  = 100
    start_time    = $startTime.ToString("yyyy-MM-dd HH:mm:ss")
}
```

```python
# Python
requests.post(log_url, data={
    'process_name': 'My ETL',
    'status': 'Success',
    'record_count': 100,
    'start_time': start_time.strftime('%Y-%m-%d %H:%M:%S'),
})
```

```javascript
// Node.js (built-ins only)
postForm(logUrl, { process_name: 'My ETL', status: 'Success', record_count: 100 });
```

```r
# R
httr::POST(log_url, body = list(process_name='My ETL', status='Success', record_count=100), encode='form')
```

---

## Source & Destination Connections

When adding a process, you can configure source and destination database connections. The app will automatically generate an ETL script tailored to your selections.

### Supported Types

| Type | Source | Destination | Notes |
|------|--------|-------------|-------|
| SQL Server | ✓ | ✓ | Windows auth or SQL auth |
| MySQL | ✓ | ✓ | Requires MySql.Data.dll (PS) or mysql-connector-python |
| PostgreSQL | ✓ | ✓ | Requires Npgsql (PS) or psycopg2 (Python) |
| Snowflake | ✓ | ✓ | Requires snowflake-connector-python |
| Oracle | ✓ | ✓ | Requires cx_Oracle |
| MongoDB | ✓ | ✓ | Requires pymongo |
| CSV / Flat File | ✓ | ✓ | No driver needed |
| REST API | ✓ | — | HTTP client built into all languages |

### How It Works

1. Add a process via **+ Add Process**
2. Fill in Source Type + connection details
3. Fill in Destination Type + connection details
4. Click **+ Add Process** — the script is auto-generated and saved to `scripts/generated/`
5. A `credentials/creds_<key>.ini` file is created — fill in passwords there
6. Click **▶ Run** — the generated script runs automatically

### Credentials

Passwords are never stored in SQLite. Each process gets a credentials file:

```
credentials/
  creds_myprocess.example.ini   ← template (committed)
  creds_myprocess.ini           ← actual passwords (gitignored)
```

Format:
```ini
[source]
host     = your-source-host
port     = 3306
database = your_database
username = your_user
password = YourPassword

[destination]
host     = your-dest-host
port     = 5432
database = your_dest_database
username = your_dest_user
password = YourDestPassword
```

### Generated Scripts

Scripts are saved to `scripts/generated/` (gitignored) with full connection boilerplate:
- `Invoke-<key>ETL.ps1` for PowerShell processes
- `Invoke-<key>ETL.py` for Python processes
- `Invoke-<key>ETL.R` for R processes
- `Invoke-<key>ETL.js` for Node.js processes

Download any generated script via the **↓ SCRIPT** button on the process panel.

Each generated script includes:
- Credentials file reader
- Source connection block
- Extract stub with your query
- Destination connection block
- Full HTTP logging to `log.php`

---

## PBIX Scanner

Scans a SharePoint document library for Power BI reports, extracts SQL connection info from TMDL, and populates the Dependency Chain tab.

### Prerequisites
1. **Power BI Desktop** — default install location
2. **pbi-tools** (desktop version) v1.2.0+
   ```powershell
   Invoke-WebRequest -Uri "https://github.com/pbi-tools/pbi-tools/releases/download/1.2.0/pbi-tools.1.2.0.zip" -OutFile "$env:TEMP\pbi-tools.zip"
   Expand-Archive -Path "$env:TEMP\pbi-tools.zip" -DestinationPath "C:\Tools\pbi-tools-desktop" -Force
   ```
3. **Azure AD app registration** with application permissions:
   - `Sites.Read.All`
   - `Files.Read.All`
   - `Report.Read.All`
   Grant admin consent, create a client secret.
4. Add the app registration as **Member** of your Power BI workspace.

### Setup
```powershell
Copy-Item Scan-PBIX_SharePoint.example.ps1 Scan-PBIX_SharePoint.ps1
# Edit Scan-PBIX_SharePoint.ps1 -- fill in TenantId, ClientId, ClientSecret,
# SharePointSite, LibraryName, SubFolder, AppUrl
```

> `Scan-PBIX_SharePoint.ps1` is gitignored. Never commit it.

### Run
```powershell
powershell.exe -NonInteractive -NoProfile -ExecutionPolicy Bypass -File "Scan-PBIX_SharePoint.ps1"
```

After scanning, click **⟳ Refresh View Map** on the Dependency Chain tab to resolve connections.

---

## Dependency Chain

Shows the full lineage: **Power BI report → SQL view → division/database**.

- **Mapped** — report → view → database fully resolved
- **View Not Found** — connection captured but view not in SQL_View_Division_Map
- **Unmapped** — scanner could not extract a connection from TMDL

Freshness indicators show when the PBIX scan and view map were last updated.

---

## Production Deployment (IIS)

### Prerequisites on web server
- PHP with `sqlsrv` extension (for SQL Server auth)
- WinRM access to ETL server
- Windows Authentication enabled in IIS

### Setup
1. Copy to `C:\inetpub\wwwroot\etl-control\`
2. Update `config/app.php`: set `mode` to `production`
3. Set `winrm_server`, `scripts_root`, `task_folder`
4. Run `Register-AllETLTasks.ps1` as Administrator
5. For each process: click **↓ WRAPPER** → deploy wrapper to web server

### IIS web.config
`web.example.config` is included — rename to `web.config` and update the allowed users list.

---

## Local Development Notes

- `data/etl_control.db` is gitignored — safe to delete and recreate
- `config/app.php` is gitignored — never committed
- `Scan-PBIX_SharePoint.ps1` is gitignored — never committed
- Processes added via the UI are stored in SQLite — also gitignored
- Delete `data/etl_control.db` to reset all app data to a clean state
