# ETL Control Panel

A config-driven web control panel for ad-hoc execution and monitoring of ETL/ELT processes. Built with PHP + IIS + Windows Authentication for enterprise environments, with a **local mode** for development and testing without any server setup.

---

## Features

- **Process registry** — add new ETL processes by editing a single config file
- **Real-time progress** — time-based progress bar with named steps, snaps to 100% on completion
- **Run history** — last 10 runs per process with duration, record count, and error details
- **Test mode** — safe per-process test args (e.g. `-TestMode -DivisionFilter single_division`)
- **Advanced processes** — resource-intensive jobs hidden behind an access control
- **Dependency Chain** — Power BI report → SQL view → source database lineage viewer
- **PBIX Scanner** — auto-scans SharePoint for Power BI reports, maps SQL connections, fetches live report URLs
- **Inline docs editor** — edit process documentation from the UI, stored in SQL Server
- **Wrapper generator** — visit `/generate_wrapper.php?process=key` to download a pre-filled `Invoke-*Remote.ps1`
- **Local mode** — run with `php -S localhost:8080`, no IIS or WinRM needed

---

## Quick Start (Local Mode)

**Requirements:** PHP 8.x, SQL Server (any edition), PowerShell 5.1+

Install PHP in one line if you don't have it:
```powershell
powershell -c "& ([ScriptBlock]::Create((irm 'https://www.php.net/include/download-instructions/windows.ps1'))) -Version 8.5"
```
Then close and reopen your terminal so PHP is on your PATH.

```powershell
# 1. Clone the repo
git clone https://github.com/your-username/etl-control.git
cd etl-control

# 2. Configure
Copy-Item config/app.example.php config/app.php
Copy-Item config/credentials.example.php config/credentials.php
# Edit both files — set your SQL Server connection and keep mode = 'local'

# 3. Create the database
# Open setup.sql in SSMS and run it against your SQL Server as sysadmin.
# It creates everything in one shot: database, tables, views, procedures, permissions.
# Estimated time: < 10 seconds.

# 4. Start the server
.\start-local.ps1

# 5. Open http://localhost:8080
# Click Run on the Hello World ETL to verify everything is working
```

---

## Project Structure

```
etl-control/
├── index.php                    ETL Processes tab
├── dependency-chain.php         Dependency Chain tab
├── trigger.php                  Async trigger endpoint
├── status.php                   Status polling + history endpoint
├── generate_wrapper.php         Downloads pre-filled Invoke-*Remote.ps1
├── save_docs.php                Inline docs editor endpoint
├── start-local.ps1              Local dev launcher (no IIS needed)
├── Register-AllETLTasks.ps1     Registers on-demand tasks on web server (production)
├── Invoke-Remote.template.ps1   WinRM wrapper template
├── Scan-PBIX_SharePoint.example.ps1  PBIX scanner (fill in and rename)
├── web.example.config           IIS config template (fill in and rename)
├── .gitignore
├── README.md
│
├── config/
│   ├── app.example.php          ← copy to app.php, fill in your values
│   ├── credentials.example.php  ← copy to credentials.php, fill in your values
│   └── processes.php            ← ETL process registry (edit this to add processes)
│
├── includes/
│   ├── db.php                   SQL Server connection helper
│   ├── auth.php                 Windows Auth / local mode user resolver
│   ├── tab_nav.php              Shared navigation bar
│   └── process_panel.php        Reusable process panel partial
│
├── migrations/
│   ├── 000_create_database.sql  Create the etl_control database
│   ├── 001_create_etl_sync_log.sql
│   ├── 002_create_pbi_connection_map.sql
│   ├── 004_create_sql_view_division_map.sql
│   ├── 005_create_etl_process_docs.sql
│   ├── 006_create_views.sql
│   ├── 007_create_stored_procedures.sql
│   ├── 008_seed_etl_process_docs.sql
│   └── 009_grant_permissions.sql
│
└── scripts/
    └── Invoke-HelloWorldETL.ps1  Example ETL script (ships with SDK)
```

---

## Adding a New Process

### 1. Register the process

Add an entry to `config/processes.php` (copy the `helloworld` block as a template):

```php
'myprocess' => [
    'key'              => 'myprocess',
    'name'             => 'My ETL Process',
    'description'      => 'Short description shown on the panel',
    'log_process_name' => 'My ETL Process',   // must match what the script logs
    'remote_server'    => $remoteServer,
    'remote_script'    => $scriptsRoot . '\my-scripts\Invoke-MyProcess.ps1',
    'local_script'     => __DIR__ . '/../scripts/Invoke-MyProcess.ps1',
    'prod_args'        => '',
    'test_args'        => '-TestMode',
    'task_name'        => 'MyProcess-OnDemand',
    'expected_seconds' => 60,
    'poll_timeout_seconds' => 300,
    'advanced'         => false,
    'trigger'          => true,
    'step_labels'      => ['Connecting', 'Extracting', 'Loading', 'Completing'],
    'step_thresholds'  => [0, 15, 40, 55],
    'reports'          => [],
    'docs'             => [
        'what'     => 'What this process does.',
        'when'     => 'When to run it manually.',
        'schedule' => 'Daily at 6:00 AM.',
        'duration' => 'Typically 45-60 seconds.',
        'warnings' => null,
    ],
],
```

### 2. Write the ETL script

Copy `scripts/Invoke-HelloWorldETL.ps1` as a starting point. The script must:
- Accept `-TestMode` if you define `test_args`
- Log `Started` / `Success` / `Failed` to `dbo.ETL_Sync_Log` with `Process_Name` matching `log_process_name`

### 3. (Production only) Generate and deploy the wrapper

```
# In browser:
http://your-etl-control/generate_wrapper.php?process=myprocess
→ downloads Invoke-MyprocessRemote.ps1
```

Deploy it to the web server, then run:

```powershell
.\Register-AllETLTasks.ps1
```

The dashboard will render the new card automatically.

---

## ETL Script Logging Contract

Scripts must write to `dbo.ETL_Sync_Log` for status polling to work:

```sql
-- Minimum required columns:
INSERT INTO dbo.ETL_Sync_Log (Process_Name, Status, Sync_Date, Start_Time, End_Time, Record_Count, Error_Message)
VALUES ('My ETL Process', 'Started',  GETDATE(), GETDATE(), GETDATE(), NULL, NULL)
-- ... do work ...
VALUES ('My ETL Process', 'Success', GETDATE(), @start, GETDATE(), @recordCount, NULL)
-- on error:
VALUES ('My ETL Process', 'Failed',  GETDATE(), @start, GETDATE(), NULL, @errorMessage)
```

See `scripts/Invoke-HelloWorldETL.ps1` for a PowerShell implementation with the `Write-ETLLog` helper function.

---

## Production Deployment (IIS)

### Prerequisites

- IIS with PHP 8.x (sqlsrv extension required)
- WinRM enabled between web server and SQL/script server
- Windows Authentication enabled in IIS, Anonymous disabled
- Service account with appropriate permissions

### IIS Setup

1. Copy folder to your web server
2. Copy `web.example.config` → `web.config`, add your AD users
3. Copy `config/app.example.php` → `config/app.php`, set `mode = 'production'`
4. Copy `config/credentials.example.php` → `config/credentials.php`
5. In IIS Manager: Add Application, PHP app pool, Windows Auth enabled

### WinRM Setup

```powershell
# On the target server (runs ETL scripts):
Enable-PSRemoting -Force
Add-LocalGroupMember -Group "Remote Management Users" -Member "DOMAIN\service-account"

# Test from web server:
Invoke-Command -ComputerName your-sql-server -ScriptBlock { hostname }
```

### Task Registration

```powershell
# Deploy Invoke-*Remote.ps1 wrappers, then:
.\Register-AllETLTasks.ps1 -WrapperRoot "C:\inetpub\wwwroot\etl-control" -TaskFolder "ETL"
```

### SQL Server Permissions

```sql
USE etl_control;

-- Web app account (read/write ETL log and dependency chain tables)
GRANT SELECT, INSERT, UPDATE ON dbo.ETL_Sync_Log          TO [your_db_user];
GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.PBI_Connection_Map TO [your_db_user];
GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.ETL_Process_Docs   TO [your_db_user];
GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.SQL_View_Division_Map TO [your_db_user];
GRANT EXECUTE ON dbo.sp_Refresh_ViewDivisionMap            TO [your_db_user];
```

---

## Dependency Chain

The Dependency Chain tab maps Power BI reports → SQL views → source databases.

It runs in two automated steps:

1. **PBIX Scanner** (separate machine) — downloads PBIX files from SharePoint, extracts TMDL connection info, fetches Power BI report URLs from the REST API, writes to `PBI_Connection_Map`. Configure from `Scan-PBIX_SharePoint.example.ps1`.

2. **View Division Map** (SQL Server Agent job) — scans all databases for SQL views and populates `SQL_View_Division_Map`. Run: `EXEC dbo.sp_Refresh_ViewDivisionMap`

See `migrations/` for the full database schema.

---

## Configuration Reference

| File | Purpose | Committed? |
|------|---------|-----------|
| `config/app.php` | Server names, mode, paths | ❌ gitignored |
| `config/app.example.php` | Template for app.php | ✅ |
| `config/credentials.php` | DB username/password | ❌ gitignored |
| `config/credentials.example.php` | Template for credentials.php | ✅ |
| `config/processes.php` | ETL process registry | ✅ |
| `web.config` | IIS auth + security rules | ❌ gitignored |
| `web.example.config` | Template for web.config | ✅ |
| `Scan-PBIX_SharePoint.ps1` | PBIX scanner with real secrets | ❌ gitignored |
| `Scan-PBIX_SharePoint.example.ps1` | Template for PBIX scanner | ✅ |

---

## License

MIT
