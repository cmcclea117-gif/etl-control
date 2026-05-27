<?php
// ── config/app.php ────────────────────────────────────────────────────────────
// Copy this file to config/app.php and fill in your values.
// config/app.php is gitignored — never commit it.
//
// The ETL Control Panel always uses SQLite for its own app data.
// ETL scripts connect to whatever sources they need independently.
// ─────────────────────────────────────────────────────────────────────────────

return [

    // ── App ───────────────────────────────────────────────────────────────────
    'app_name' => 'ETL Control Panel',
    'app_url'  => 'http://localhost:8080',

    // ── Branding ──────────────────────────────────────────────────────────────
    // badge:    short label shown top-left (2-5 chars works best)
    // org_name: shown in page header and footer
    'badge'    => 'ETL',       // e.g. 'ACME', 'RMG', 'ETL'
    'org_name' => 'My Org',    // e.g. 'Acme Corp', 'My Company'

    // ── Auth ──────────────────────────────────────────────────────────────────
    // mode: 'local'      -> no auth, uses local_user below
    //       'production' -> IIS Windows Authentication
    'mode'       => 'local',
    'local_user' => 'developer',

    // ── Remote execution (production only) ───────────────────────────────────
    // The server WinRM connects to when firing ETL scripts remotely.
    'winrm_server' => 'your-etl-server',

    // Root path where ETL scripts live on the remote server.
    'scripts_root' => 'C:\Scripts',

    // ── Task Scheduler (production only) ─────────────────────────────────────
    // Folder under which on-demand tasks are registered on the web server.
    'task_folder' => 'ETL',

    // ── IIS (production only) ─────────────────────────────────────────────────
    'wrapper_root'    => 'C:\inetpub\wwwroot\etl-control',
    'domain'          => 'YOURDOMAIN',
    'service_account' => 'sqlagent',

    // ── PBIX Scanner ─────────────────────────────────────────────────────────
    // The scanner POSTs data to this app via ingest_pbix.php.
    // Set this to the URL where this app is running.
    'app_ingestion_url' => 'http://localhost:8080',  // e.g. https://etl-control.example.com

    // Azure AD app registration for SharePoint + Power BI access
    'azure_tenant_id' => 'your-tenant-id',
    'azure_client_id' => 'your-client-id',

    // SharePoint library settings
    'sharepoint_site'   => 'yourtenant.sharepoint.com:/sites/YourSite:',
    'sharepoint_url'    => 'https://yourtenant.sharepoint.com/sites/YourSite',
    'pbi_library'       => 'Documents',
    'pbi_subfolder'     => 'Power BI Reports',

];
