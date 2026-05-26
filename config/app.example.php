<?php
// ── config/app.php ────────────────────────────────────────────────────────────
// Copy this file to config/app.php and fill in your values.
// config/app.php is gitignored — never commit it.
//
// mode: 'local'      → PHP built-in server, no IIS/WinRM needed, scripts run directly
//       'production' → IIS + Windows Auth + WinRM + schtasks
// ─────────────────────────────────────────────────────────────────────────────

return [

    // ── Environment ───────────────────────────────────────────────────────────
    'mode'            => 'local',        // 'local' | 'production'
    'app_name'        => 'ETL Control Panel',
    'app_url'         => 'http://localhost:8080',

    // ── Branding ─────────────────────────────────────────────────────────────
    // badge: short label shown top-left (2-5 chars works best)
    // org_name: shown in page header and footer
    'badge'           => 'ETL',          // e.g. 'RMG', 'ACME', 'ETL'
    'org_name'        => 'My Org',       // e.g. 'RMG', 'Acme Corp'

    // ── Database driver ──────────────────────────────────────────────────────
    // 'sqlite'  — local .db file, no SQL Server needed (default for local/SDK use)
    // 'sqlsrv'  — SQL Server (production); requires credentials.php + sqlsrv extension
    'db_driver'       => 'sqlite',

    // ── SQL Server (sqlsrv driver only) ───────────────────────────────────────
    'sql_server'      => 'localhost',    // e.g. 'your-sql-server' or '192.168.1.10'
    'database'        => 'etl_control',  // target database name

    // ── Task Scheduler ────────────────────────────────────────────────────────
    // Folder under which on-demand tasks are registered on the web server.
    'task_folder'     => 'ETL',          // e.g. 'ETL' → tasks appear as \ETL\ProcessName-OnDemand

    // ── WinRM / Remote execution (production only) ────────────────────────────
    // The server WinRM connects to in order to run ETL scripts.
    'winrm_server'    => 'your-sql-server',

    // ── IIS deployment paths (production only) ────────────────────────────────
    'wrapper_root'    => 'C:\inetpub\wwwroot\etl-control',
    'scripts_root'    => 'C:\Scripts',   // root where ETL scripts live on winrm_server

    // ── Active Directory (production only) ────────────────────────────────────
    'domain'          => 'YOURDOMAIN',
    'service_account' => 'sqlagent',     // account that runs IIS app pool + tasks

    // ── Local dev (local mode only) ───────────────────────────────────────────
    // Username shown in the UI when running without Windows Auth.
    'local_user'      => 'developer',

    // ── Power BI / PBIX Scanner ───────────────────────────────────────────────
    // Azure AD app registration for the SharePoint + Power BI scanner.
    'azure_tenant_id' => 'your-tenant-id',
    'azure_client_id' => 'your-client-id',
    'sharepoint_site' => 'yourtenant.sharepoint.com:/sites/YourSite:',
    'sharepoint_url'  => 'https://yourtenant.sharepoint.com/sites/YourSite',
    'pbi_library'     => 'Documents',
    'pbi_subfolder'   => 'Power BI Reports',

];
