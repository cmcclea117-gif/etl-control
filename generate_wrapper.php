<?php
// ── generate_wrapper.php ──────────────────────────────────────────────────────
// Generates and downloads a ready-to-use Invoke-<Key>Remote.ps1 WinRM wrapper
// for any registered process. The wrapper is tailored to the process exec_type.
//
// Usage: visit /generate_wrapper.php?process=myprocess
//        -> downloads Invoke-MyprocessRemote.ps1
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/includes/auth.php';
requireAuth();

$config     = require __DIR__ . '/config/app.php';
$processes  = require __DIR__ . '/config/processes.php';
$processKey = trim($_GET['process'] ?? '');

if (empty($processKey) || !isset($processes[$processKey])) {
    http_response_code(400);
    echo 'Unknown process: ' . htmlspecialchars($processKey);
    exit;
}

$proc         = $processes[$processKey];
$execType     = $proc['exec_type'] ?? 'powershell';
$remoteServer = $proc['remote_server'] ?? $config['winrm_server'];
$fileName     = 'Invoke-' . ucfirst($processKey) . 'Remote.ps1';
$procName     = $proc['name'];
$wrapperRoot  = $config['wrapper_root'];

// ── Build the inner script block based on exec_type ──────────────────────────

switch ($execType) {

    case 'python':
        $script    = $proc['remote_script'] ?? 'C:\Scripts\my_etl.py';
        $pythonExe = $proc['python_exe']    ?? 'python.exe';
        $innerBlock = <<<PS
        \$pythonExe = "$pythonExe"
        \$script    = "$script"
        \$args      = \$extraArgs -split ' ' | Where-Object { \$_ }
        & \$pythonExe \$script @args
        if (\$LASTEXITCODE -ne 0) { throw "Python script exited with code \$LASTEXITCODE" }
PS;
        break;

    case 'ssis':
        $ssisServer  = $proc['ssis_server']  ?? $remoteServer;
        $ssisCatalog = $proc['ssis_catalog'] ?? 'SSISDB';
        $ssisFolder  = $proc['ssis_folder']  ?? 'MyFolder';
        $ssisProject = $proc['ssis_project'] ?? 'MyProject';
        $ssisPackage = $proc['ssis_package'] ?? 'MyPackage.dtsx';
        $appUrl      = rtrim($config['app_ingestion_url'] ?? 'http://localhost:8080', '/');
        $logName     = $proc['log_process_name'];
        $innerBlock = <<<PS
        \$ssisServer  = "$ssisServer"
        \$ssisCatalog = "$ssisCatalog"
        \$ssisFolder  = "$ssisFolder"
        \$ssisProject = "$ssisProject"
        \$ssisPackage = "$ssisPackage"
        \$logUrl      = "$appUrl/log.php"
        \$logName     = "$logName"
        \$startTime   = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

        function Write-AppLog {
            param([string]\$Status, [int]\$RecordCount = 0, [string]\$ErrorMessage = \$null)
            try {
                \$body = @{
                    process_name  = \$logName
                    status        = \$Status
                    record_count  = \$RecordCount
                    start_time    = \$startTime
                }
                if (\$ErrorMessage) { \$body.error_message = \$ErrorMessage }
                Invoke-RestMethod -Uri \$logUrl -Method POST -Body \$body -ErrorAction Stop | Out-Null
                Write-Host "Logged '\$Status' to \$logUrl"
            } catch {
                Write-Warning "Log POST failed (non-fatal): \$_"
            }
        }

        # Log started
        Write-AppLog -Status "Started"

        try {
            \$conn = New-Object System.Data.SqlClient.SqlConnection(
                "Server=\$ssisServer;Database=\$ssisCatalog;Integrated Security=True;TrustServerCertificate=True;")
            \$conn.Open()
            \$cmd = \$conn.CreateCommand()
            \$cmd.CommandText = @"
DECLARE @exec_id BIGINT
EXEC [SSISDB].[catalog].[create_execution]
    @folder_name  = N'\$ssisFolder',
    @project_name = N'\$ssisProject',
    @package_name = N'\$ssisPackage',
    @execution_id = @exec_id OUTPUT
EXEC [SSISDB].[catalog].[start_execution] @exec_id
SELECT @exec_id AS execution_id
"@
            \$executionId = \$cmd.ExecuteScalar()
            Write-Host "SSIS package started. Execution ID: \$executionId"

            # Poll for completion
            \$maxWait = 300; \$waited = 0; \$status = 0
            do {
                Start-Sleep -Seconds 5; \$waited += 5
                \$statusCmd = \$conn.CreateCommand()
                \$statusCmd.CommandText = "SELECT [status] FROM [SSISDB].[catalog].[executions] WHERE execution_id = \$executionId"
                \$status = \$statusCmd.ExecuteScalar()
                Write-Host "SSIS status: \$status (\$waited s elapsed)"
            } while (\$status -notin @(3, 4, 6, 7) -and \$waited -lt \$maxWait)

            \$conn.Close()

            if (\$status -eq 3) {
                Write-Host "SSIS package completed successfully"
                Write-AppLog -Status "Success" -RecordCount 1
            } else {
                throw "SSIS package failed with status \$status"
            }

        } catch {
            \$errMsg = \$_.Exception.Message
            Write-Host "SSIS execution failed: \$errMsg"
            if (\$conn -and \$conn.State -eq 'Open') { \$conn.Close() }
            Write-AppLog -Status "Failed" -ErrorMessage \$errMsg
            throw
        }
PS;
        break;

    case 'sqlagent':
        $agentServer = $proc['agent_server'] ?? $remoteServer;
        $agentJob    = $proc['agent_job']    ?? 'My SQL Agent Job';
        $appUrl      = rtrim($config['app_ingestion_url'] ?? 'http://localhost:8080', '/');
        $logName     = $proc['log_process_name'];
        $innerBlock = <<<PS
        \$agentServer = "$agentServer"
        \$jobName     = "$agentJob"
        \$logUrl      = "$appUrl/log.php"
        \$logName     = "$logName"
        \$startTime   = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

        function Write-AppLog {
            param([string]\$Status, [int]\$RecordCount = 0, [string]\$ErrorMessage = \$null)
            try {
                \$body = @{
                    process_name  = \$logName
                    status        = \$Status
                    record_count  = \$RecordCount
                    start_time    = \$startTime
                }
                if (\$ErrorMessage) { \$body.error_message = \$ErrorMessage }
                Invoke-RestMethod -Uri \$logUrl -Method POST -Body \$body -ErrorAction Stop | Out-Null
                Write-Host "Logged '\$Status' to \$logUrl"
            } catch {
                Write-Warning "Log POST failed (non-fatal): \$_"
            }
        }

        # Log started
        Write-AppLog -Status "Started"

        try {
            # Start the SQL Agent job
            \$conn = New-Object System.Data.SqlClient.SqlConnection(
                "Server=\$agentServer;Database=msdb;Integrated Security=True;TrustServerCertificate=True;")
            \$conn.Open()
            \$cmd = \$conn.CreateCommand()
            \$cmd.CommandText = "EXEC dbo.sp_start_job N'\$jobName'"
            \$cmd.ExecuteNonQuery() | Out-Null
            Write-Host "SQL Agent job started: \$jobName"

            # Poll until complete
            \$maxWait = 3600; \$waited = 0
            \$jobStatus = "Running"
            do {
                Start-Sleep -Seconds 10; \$waited += 10
                \$statusCmd = \$conn.CreateCommand()
                \$statusCmd.CommandText = @"
SELECT TOP 1
    CASE h.run_status
        WHEN 0 THEN 'Failed'
        WHEN 1 THEN 'Succeeded'
        WHEN 2 THEN 'Retry'
        WHEN 3 THEN 'Cancelled'
        WHEN 4 THEN 'Running'
        ELSE 'Unknown'
    END AS job_status
FROM dbo.sysjobhistory h
JOIN dbo.sysjobs j ON j.job_id = h.job_id
WHERE j.name = N'\$jobName' AND h.step_id = 0
ORDER BY h.instance_id DESC
"@
                \$reader = \$statusCmd.ExecuteReader()
                if (\$reader.Read()) { \$jobStatus = \$reader["job_status"] }
                \$reader.Close()
                Write-Host "Job status: \$jobStatus (\$waited s elapsed)"
            } while (\$jobStatus -in @('Running', 'Retry', 'Unknown') -and \$waited -lt \$maxWait)

            \$conn.Close()

            if (\$jobStatus -eq 'Succeeded') {
                Write-Host "SQL Agent job completed successfully"
                Write-AppLog -Status "Success" -RecordCount 1
            } else {
                throw "SQL Agent job ended with status: \$jobStatus"
            }

        } catch {
            \$errMsg = \$_.Exception.Message
            Write-Host "SQL Agent job failed: \$errMsg"
            if (\$conn -and \$conn.State -eq 'Open') { \$conn.Close() }
            Write-AppLog -Status "Failed" -ErrorMessage \$errMsg
            throw
        }
PS;
        break;

    case 'cmd':
        $remoteCmd = $proc['remote_cmd'] ?? 'echo Hello from ETL';
        $innerBlock = <<<PS
        \$command = "$remoteCmd"
        if (\$extraArgs) { \$command += " \$extraArgs" }
        Write-Host "Running: \$command"
        Invoke-Expression \$command
        if (\$LASTEXITCODE -ne 0) { throw "Command exited with code \$LASTEXITCODE" }
PS;
        break;

    case 'r':
        $script    = $proc['remote_script'] ?? 'C:\\Scripts\\my_etl.R';
        $rExe      = $proc['r_exe']         ?? 'Rscript.exe';
        $innerBlock = <<<PS
        \$rExe   = "$rExe"
        \$script = "$script"
        \$rArgs  = @("\$script")
        if (\$extraArgs) { \$rArgs += \$extraArgs -split ' ' | Where-Object { \$_ } }
        & \$rExe @rArgs
        if (\$LASTEXITCODE -ne 0) { throw "R script exited with code \$LASTEXITCODE" }
PS;
        break;

    case 'node':
        $script  = $proc['remote_script'] ?? 'C:\\Scripts\\my_etl.js';
        $nodeExe = $proc['node_exe']      ?? 'node.exe';
        $innerBlock = <<<PS
        \$nodeExe = "$nodeExe"
        \$script  = "$script"
        \$nArgs   = @("\$script")
        if (\$extraArgs) { \$nArgs += \$extraArgs -split ' ' | Where-Object { \$_ } }
        & \$nodeExe @nArgs
        if (\$LASTEXITCODE -ne 0) { throw "Node.js script exited with code \$LASTEXITCODE" }
PS;
        break;

    case 'powershell':
    default:
        $script = $proc['remote_script'] ?? 'C:\Scripts\Invoke-MyProcess.ps1';
        $innerBlock = <<<PS
        \$script = "$script"
        if (\$extraArgs) { Invoke-Expression "& '\$script' \$extraArgs" }
        else             { & \$script }
PS;
        break;
}

// ── Assemble the full wrapper ─────────────────────────────────────────────────
$content = <<<PS
#Requires -Version 5.1
<#
.SYNOPSIS
    WinRM wrapper for {$procName} -- generated by ETL Control Panel.
    exec_type: $execType

.PARAMETER ExtraArgs
    Additional arguments forwarded to the process.
    Populated by trigger.php from prod_args / test_args in processes.php.

.NOTES
    Deploy to:  {$wrapperRoot}\\{$fileName}
    Register:   Run Register-AllETLTasks.ps1 after deploying.
    Regenerate: Visit /generate_wrapper.php?process={$processKey}
#>

param(
    [string]\$ExtraArgs = ""
)
\$ErrorActionPreference = "Stop"
\$remoteServer = "$remoteServer"

try {
    Invoke-Command -ComputerName \$remoteServer \`
                   -Authentication Negotiate \`
                   -ScriptBlock {
                       param(\$extraArgs)
                       Set-ExecutionPolicy Bypass -Scope Process -Force

$innerBlock

                   } \`
                   -ArgumentList \$ExtraArgs
} catch {
    throw
}
PS;

// ── Stream as downloadable file ───────────────────────────────────────────────
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-cache');
echo $content;
