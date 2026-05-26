-- ============================================================
-- setup.sql — ETL Control Panel — First-time database setup
-- ============================================================
-- Run this script ONCE on your SQL Server instance as sysadmin.
-- It creates everything the app needs from scratch.
--
-- Before running:
--   1. Create a SQL login for the web app (used by db.php):
--        CREATE LOGIN etl_web WITH PASSWORD = 'YourPassword';
--      Then set db_user = 'etl_web' and db_pass = 'YourPassword'
--      in config/credentials.php.
--
--   2. If you want Windows auth for your ETL scripts instead,
--      see the OPTIONAL GRANTS section at the bottom.
--
-- What this creates:
--   Database:    etl_control
--   Tables:      ETL_Sync_Log, PBI_Connection_Map,
--                SQL_View_Division_Map, ETL_Process_Docs
--   Views:       vw_ETLSyncLogSummary, vw_Full_Dependency_Chain
--   Procedures:  sp_LogETLProcess, sp_CleanupETLSyncLog,
--                sp_Refresh_ViewDivisionMap
--   Permissions: etl_web login gets read/write on all app objects
-- ============================================================

-- ============================================================
-- SECTION 1 — Create database
-- ============================================================
USE master;
GO

IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = 'etl_control')
BEGIN
    CREATE DATABASE etl_control COLLATE SQL_Latin1_General_CP1_CI_AS;
    PRINT 'Created database: etl_control';
END
ELSE
    PRINT 'Database already exists: etl_control';
GO

-- Simple recovery — this is an operational app DB, not a data warehouse
ALTER DATABASE etl_control SET RECOVERY SIMPLE;
GO

USE etl_control;
GO

-- ── Create the web app user if the login exists ───────────────────────────────
-- If your login is named something other than 'etl_web', update this.
IF EXISTS (SELECT name FROM sys.server_principals WHERE name = 'etl_web')
   AND NOT EXISTS (SELECT name FROM sys.database_principals WHERE name = 'etl_web')
BEGIN
    CREATE USER etl_web FOR LOGIN etl_web;
    PRINT 'Created database user: etl_web';
END
GO

-- ============================================================
-- SECTION 2 — Tables
-- ============================================================

-- ── ETL_Sync_Log ──────────────────────────────────────────────────────────────
-- Central log written by all ETL scripts.
-- status.php polls this table every 3 seconds during an active run.
IF OBJECT_ID('dbo.ETL_Sync_Log', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ETL_Sync_Log (
        Log_ID        INT            NOT NULL IDENTITY(1,1),
        Process_Name  NVARCHAR(255)  NOT NULL,
        Record_Count  INT            NULL,
        Sync_Date     DATETIME2      NOT NULL CONSTRAINT DF_ETL_Sync_Log_Sync_Date DEFAULT SYSDATETIME(),
        Status        NVARCHAR(50)   NOT NULL,
        Error_Message NVARCHAR(MAX)  NULL,
        Start_Time    DATETIME2      NULL,
        End_Time      DATETIME2      NULL,
        Updated_Rows  INT            NULL,
        Inserted_Rows INT            NULL,
        CONSTRAINT PK_ETL_Sync_Log PRIMARY KEY (Log_ID)
    );

    -- status.php filters and orders by these two columns on every poll
    CREATE NONCLUSTERED INDEX IX_ETL_Sync_Log_ProcessName_SyncDate
        ON dbo.ETL_Sync_Log (Process_Name, Sync_Date DESC);

    PRINT 'Created table: dbo.ETL_Sync_Log';
END
ELSE
    PRINT 'Table already exists: dbo.ETL_Sync_Log';
GO

-- ── PBI_Connection_Map ────────────────────────────────────────────────────────
-- Written by Scan-PBIX_SharePoint.ps1. Cleared and repopulated on every scan.
-- Read by dependency-chain.php.
IF OBJECT_ID('dbo.PBI_Connection_Map', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.PBI_Connection_Map (
        MapID           INT             NOT NULL IDENTITY(1,1),
        Report_File     NVARCHAR(500)   NULL,
        SharePoint_Path NVARCHAR(1000)  NULL,
        SharePoint_Site NVARCHAR(500)   NULL,
        Server          NVARCHAR(255)   NULL,
        Database_Name   NVARCHAR(255)   NULL,
        View_Or_Table   NVARCHAR(500)   NULL,
        Query_Text      NVARCHAR(MAX)   NULL,
        Source_Type     NVARCHAR(50)    NULL,
        Last_Scanned    DATETIME        NULL CONSTRAINT DF_PBI_Connection_Map_Last_Scanned DEFAULT GETDATE(),
        Schema_Name     NVARCHAR(128)   NULL,
        Import_Mode     NVARCHAR(50)    NULL,
        Report_URL      NVARCHAR(500)   NULL,
        CONSTRAINT PK_PBI_Connection_Map PRIMARY KEY (MapID)
    );

    CREATE NONCLUSTERED INDEX IX_PBI_Connection_Map_ViewTable
        ON dbo.PBI_Connection_Map (View_Or_Table, Database_Name);

    PRINT 'Created table: dbo.PBI_Connection_Map';
END
ELSE
    PRINT 'Table already exists: dbo.PBI_Connection_Map';
GO

-- ── SQL_View_Division_Map ─────────────────────────────────────────────────────
-- Written by sp_Refresh_ViewDivisionMap. Truncated and repopulated on refresh.
-- Read by dependency-chain.php via vw_Full_Dependency_Chain.
IF OBJECT_ID('dbo.SQL_View_Division_Map', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.SQL_View_Division_Map (
        MapID            INT           NOT NULL IDENTITY(1,1),
        FoundInDatabase  NVARCHAR(128) NULL,
        View_Schema      NVARCHAR(128) NULL,
        View_Name        NVARCHAR(128) NULL,
        Division_DB      NVARCHAR(128) NULL,    -- upstream source database, if detectable
        Approx_LineCount INT           NULL,
        Last_Refreshed   DATETIME      NULL CONSTRAINT DF_SQL_View_Division_Map_Last_Refreshed DEFAULT GETDATE(),
        CONSTRAINT PK_SQL_View_Division_Map PRIMARY KEY (MapID)
    );

    CREATE NONCLUSTERED INDEX IX_SQL_View_Division_Map_ViewName
        ON dbo.SQL_View_Division_Map (View_Name, FoundInDatabase);

    PRINT 'Created table: dbo.SQL_View_Division_Map';
END
ELSE
    PRINT 'Table already exists: dbo.SQL_View_Division_Map';
GO

-- ── ETL_Process_Docs ──────────────────────────────────────────────────────────
-- Written by save_docs.php. Read by index.php at render time.
-- Allows editing process documentation from the UI without touching code.
IF OBJECT_ID('dbo.ETL_Process_Docs', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ETL_Process_Docs (
        process_key  NVARCHAR(50)   NOT NULL,
        what         NVARCHAR(MAX)  NULL,
        schedule     NVARCHAR(500)  NULL,
        duration     NVARCHAR(500)  NULL,
        when_to_run  NVARCHAR(MAX)  NULL,
        warnings     NVARCHAR(MAX)  NULL,
        updated_by   NVARCHAR(100)  NULL,
        updated_at   DATETIME       NOT NULL CONSTRAINT DF_ETL_Process_Docs_updated_at DEFAULT GETDATE(),
        CONSTRAINT PK_ETL_Process_Docs PRIMARY KEY (process_key)
    );

    PRINT 'Created table: dbo.ETL_Process_Docs';
END
ELSE
    PRINT 'Table already exists: dbo.ETL_Process_Docs';
GO

-- ============================================================
-- SECTION 3 — Views
-- ============================================================

-- ── vw_ETLSyncLogSummary ──────────────────────────────────────────────────────
-- Process monitoring summary — used by Power BI ETL dashboards if needed.
CREATE OR ALTER VIEW dbo.vw_ETLSyncLogSummary AS
SELECT
    Process_Name,
    Status,
    COUNT(*)                                        AS ProcessCount,
    MIN(Sync_Date)                                  AS EarliestRun,
    MAX(Sync_Date)                                  AS LatestRun,
    AVG(DATEDIFF(SECOND, Start_Time, End_Time))     AS AvgDurationSeconds
FROM dbo.ETL_Sync_Log
GROUP BY Process_Name, Status;
GO

PRINT 'Created/updated view: dbo.vw_ETLSyncLogSummary';
GO

-- ── vw_Full_Dependency_Chain ──────────────────────────────────────────────────
-- Joins PBI_Connection_Map to SQL_View_Division_Map to resolve the full
-- Power BI report → SQL view → source database chain.
-- Used by dependency-chain.php.
CREATE OR ALTER VIEW dbo.vw_Full_Dependency_Chain AS
SELECT
    p.Report_File,
    p.SharePoint_Site,
    p.SharePoint_Path,
    p.Import_Mode,
    p.Server,
    p.Database_Name                     AS PBI_Database,
    p.Schema_Name                       AS PBI_Schema,
    p.View_Or_Table                     AS PBI_View,
    p.Report_URL,
    v.FoundInDatabase                   AS View_Database,
    v.View_Schema,
    v.View_Name,
    v.Division_DB                       AS Upstream_Division_DB,
    v.Approx_LineCount,
    v.Last_Refreshed                    AS View_Map_Refreshed,
    p.Last_Scanned                      AS PBI_Map_Scanned,
    CASE
        WHEN p.View_Or_Table IS NULL
          OR p.View_Or_Table = ''       THEN 'Unmapped'
        WHEN v.View_Name IS NOT NULL    THEN 'Mapped'
        ELSE 'View Not Found'
    END                                 AS Chain_Status
FROM dbo.PBI_Connection_Map p
LEFT JOIN dbo.SQL_View_Division_Map v
    ON  v.View_Name       = p.View_Or_Table
    AND v.FoundInDatabase = p.Database_Name;
GO

PRINT 'Created/updated view: dbo.vw_Full_Dependency_Chain';
GO

-- ============================================================
-- SECTION 4 — Stored procedures
-- ============================================================

-- ── sp_LogETLProcess ──────────────────────────────────────────────────────────
-- Called by PowerShell ETL scripts to write Started/Success/Failed entries.
-- Use this in your scripts instead of raw INSERTs for consistency.
CREATE OR ALTER PROCEDURE dbo.sp_LogETLProcess (
    @ProcessName   NVARCHAR(255),
    @Status        NVARCHAR(50),
    @StartTime     DATETIME2     = NULL,
    @EndTime       DATETIME2     = NULL,
    @RecordCount   INT           = NULL,
    @ErrorMessage  NVARCHAR(MAX) = NULL,
    @UpdatedRows   INT           = NULL,
    @InsertedRows  INT           = NULL
)
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.ETL_Sync_Log
        (Process_Name, Record_Count, Status, Error_Message,
         Start_Time, End_Time, Updated_Rows, Inserted_Rows)
    VALUES
        (@ProcessName, @RecordCount, @Status, @ErrorMessage,
         @StartTime, @EndTime, @UpdatedRows, @InsertedRows);
END;
GO

PRINT 'Created/updated procedure: dbo.sp_LogETLProcess';
GO

-- ── sp_CleanupETLSyncLog ──────────────────────────────────────────────────────
-- Deletes log rows older than @RetentionDays (default 90).
-- Schedule as a SQL Agent job (weekly or monthly).
CREATE OR ALTER PROCEDURE dbo.sp_CleanupETLSyncLog (
    @RetentionDays INT = 90
)
AS
BEGIN
    SET NOCOUNT ON;
    DELETE FROM dbo.ETL_Sync_Log
    WHERE Sync_Date < DATEADD(DAY, -@RetentionDays, GETDATE());
    SELECT @@ROWCOUNT AS RowsDeleted;
END;
GO

PRINT 'Created/updated procedure: dbo.sp_CleanupETLSyncLog';
GO

-- ── sp_Refresh_ViewDivisionMap ────────────────────────────────────────────────
-- Scans all ONLINE user databases on the instance for SQL views.
-- Populates SQL_View_Division_Map — the dependency chain reads this.
--
-- Schedule as a SQL Agent job to run nightly (after the PBIX scanner).
-- Run manually: EXEC etl_control.dbo.sp_Refresh_ViewDivisionMap
--
-- Optional: pass a comma-separated list of databases to scan instead of all.
-- Optional: populate the SourceDatabases table below with your known
--           source/upstream database names to enable division mapping.
CREATE OR ALTER PROCEDURE dbo.sp_Refresh_ViewDivisionMap
AS
BEGIN
    SET NOCOUNT ON;

    IF OBJECT_ID('tempdb..#ViewMap') IS NOT NULL DROP TABLE #ViewMap;
    CREATE TABLE #ViewMap (
        FoundInDatabase  NVARCHAR(128),
        View_Schema      NVARCHAR(128),
        View_Name        NVARCHAR(128),
        Division_DB      NVARCHAR(128),
        Approx_LineCount INT
    );

    -- ── List of known source databases to look for in view definitions ────────
    -- Add your upstream/source database names here.
    -- When a view's definition references one of these databases, that database
    -- name is recorded in Division_DB — this powers the dependency chain links.
    -- Leave empty if you don't have named source databases.
    IF OBJECT_ID('tempdb..#SourceDatabases') IS NOT NULL DROP TABLE #SourceDatabases;
    CREATE TABLE #SourceDatabases (db_name NVARCHAR(128));
    -- INSERT INTO #SourceDatabases VALUES ('your_source_db_1'), ('your_source_db_2');

    DECLARE @DBName  NVARCHAR(128);
    DECLARE @SQL     NVARCHAR(MAX);
    DECLARE @cur     CURSOR;

    SET @cur = CURSOR FAST_FORWARD FOR
        SELECT name
        FROM sys.databases
        WHERE state_desc = 'ONLINE'
          AND name NOT IN ('model', 'tempdb', 'master', 'msdb', 'SSISDB', 'etl_control')
          AND database_id > 4   -- skip system databases
        ORDER BY name;

    OPEN @cur;
    FETCH NEXT FROM @cur INTO @DBName;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @SQL = N'
        USE ' + QUOTENAME(@DBName) + N';
        INSERT INTO #ViewMap
        SELECT DISTINCT
            ''' + @DBName + N''' AS FoundInDatabase,
            OBJECT_SCHEMA_NAME(o.object_id) AS View_Schema,
            o.name AS View_Name,
            src.db_name AS Division_DB,
            LEN(m.definition) - LEN(REPLACE(m.definition, CHAR(10), '''')) + 1 AS Approx_LineCount
        FROM sys.sql_modules m
        JOIN sys.objects o ON o.object_id = m.object_id
        LEFT JOIN #SourceDatabases src
            ON m.definition LIKE ''%'' + src.db_name + ''%''
        WHERE o.type = ''V''
          AND o.name NOT LIKE ''%backup%''
          AND o.name NOT LIKE ''%_bak%''
          AND o.name NOT LIKE ''%_old%''
          AND o.name NOT LIKE ''%_orig%''
          AND o.name NOT LIKE ''%_copy%''
          AND o.name NOT LIKE ''%_temp%''
          AND o.name NOT LIKE ''%_tmp%'';';

        BEGIN TRY
            EXEC sp_executesql @SQL;
        END TRY
        BEGIN CATCH
            PRINT 'Skipped: ' + @DBName + ' — ' + ERROR_MESSAGE();
        END CATCH

        FETCH NEXT FROM @cur INTO @DBName;
    END

    CLOSE @cur;
    DEALLOCATE @cur;

    TRUNCATE TABLE dbo.SQL_View_Division_Map;

    INSERT INTO dbo.SQL_View_Division_Map
        (FoundInDatabase, View_Schema, View_Name, Division_DB, Approx_LineCount, Last_Refreshed)
    SELECT FoundInDatabase, View_Schema, View_Name, Division_DB, Approx_LineCount, GETDATE()
    FROM #ViewMap;

    PRINT 'SQL_View_Division_Map refreshed — ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
END;
GO

PRINT 'Created/updated procedure: dbo.sp_Refresh_ViewDivisionMap';
GO

-- ============================================================
-- SECTION 5 — Seed ETL_Process_Docs
-- ============================================================
-- Seeds the Hello World example process documentation.
-- Uses MERGE so re-running won't overwrite edits made via the web UI.
-- Add rows here for each process you add to config/processes.php.

MERGE dbo.ETL_Process_Docs AS target
USING (VALUES
    (
        'helloworld',
        'A self-contained example ETL that demonstrates the full control panel integration. Generates sample data and logs Started/Success/Failed to ETL_Sync_Log.',
        'Not scheduled — run on demand only.',
        'Typically 10-15 seconds.',
        'Run manually to verify your ETL Control Panel installation is working end-to-end.',
        'This is a demo process. Remove or disable it in production once you have added your real processes.'
    )
) AS source (process_key, what, schedule, duration, when_to_run, warnings)
ON target.process_key = source.process_key
WHEN NOT MATCHED BY TARGET THEN
    INSERT (process_key, what, schedule, duration, when_to_run, warnings, updated_by, updated_at)
    VALUES (source.process_key, source.what, source.schedule, source.duration,
            source.when_to_run, source.warnings, 'setup', GETDATE());

PRINT 'Seeded ETL_Process_Docs';
GO

-- ============================================================
-- SECTION 6 — Grant permissions to web app user
-- ============================================================
-- Grants the etl_web user (your db_user from credentials.php)
-- the minimum permissions needed by the PHP app.
-- Change 'etl_web' if your login has a different name.

IF EXISTS (SELECT name FROM sys.database_principals WHERE name = 'etl_web')
BEGIN
    -- ETL log — read for status polling, write for PBIX scanner
    GRANT SELECT, INSERT, UPDATE       ON dbo.ETL_Sync_Log           TO [etl_web];

    -- Process docs — read at render, write via save_docs.php
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.ETL_Process_Docs     TO [etl_web];

    -- PBI connection map — cleared + repopulated by PBIX scanner
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.PBI_Connection_Map   TO [etl_web];

    -- View division map — read by dependency-chain.php
    GRANT SELECT                         ON dbo.SQL_View_Division_Map TO [etl_web];

    -- Views
    GRANT SELECT ON dbo.vw_ETLSyncLogSummary     TO [etl_web];
    GRANT SELECT ON dbo.vw_Full_Dependency_Chain TO [etl_web];

    -- Procedures
    GRANT EXECUTE ON dbo.sp_Refresh_ViewDivisionMap TO [etl_web];

    PRINT 'Permissions granted to etl_web';
END
ELSE
    PRINT 'User etl_web not found — create the login first, then re-run this section';
GO

-- ============================================================
-- SECTION 7 — OPTIONAL: Windows auth grants (production only)
-- ============================================================
-- If your ETL scripts run as a Windows service account via WinRM,
-- uncomment and update the account names below.
--
-- Example: a service account called YOURDOMAIN\sqlagent that runs
-- the scheduled tasks on the web server and ETL scripts on the
-- SQL Server via WinRM.

/*
-- Create the Windows auth user
IF NOT EXISTS (SELECT name FROM sys.database_principals WHERE name = 'YOURDOMAIN\sqlagent')
    CREATE USER [YOURDOMAIN\sqlagent] FOR LOGIN [YOURDOMAIN\sqlagent];

-- Grant broad access for the ETL service account
ALTER ROLE db_datareader ADD MEMBER [YOURDOMAIN\sqlagent];
ALTER ROLE db_datawriter ADD MEMBER [YOURDOMAIN\sqlagent];
GRANT EXECUTE ON dbo.sp_LogETLProcess           TO [YOURDOMAIN\sqlagent];
GRANT EXECUTE ON dbo.sp_CleanupETLSyncLog       TO [YOURDOMAIN\sqlagent];
GRANT EXECUTE ON dbo.sp_Refresh_ViewDivisionMap TO [YOURDOMAIN\sqlagent];
*/

-- ============================================================
-- Done!
-- ============================================================
PRINT '';
PRINT '=== ETL Control Panel database setup complete ===';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Copy config/app.example.php → config/app.php';
PRINT '  2. Copy config/credentials.example.php → config/credentials.php';
PRINT '  3. Fill in sql_server, database, db_user, db_pass';
PRINT '  4. Run .\start-local.ps1';
PRINT '  5. Open http://localhost:8080';
PRINT '  6. Click Run on Hello World ETL to verify';
PRINT '';

SELECT
    t.name AS table_name,
    p.rows AS row_count
FROM sys.tables t
JOIN sys.partitions p ON t.object_id = p.object_id AND p.index_id IN (0, 1)
WHERE t.schema_id = SCHEMA_ID('dbo')
ORDER BY t.name;
GO
