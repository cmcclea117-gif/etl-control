#Requires -Version 5.1
<#
.SYNOPSIS
    WinRM wrapper template for ETL Control Panel.

    Copy this file to Invoke-<YourProcess>Remote.ps1, then fill in:
      - $remoteServer : the SQL Server / script host to connect to via WinRM
      - $remoteScript : full path to the ETL script on the remote server

    Or, generate a pre-filled version automatically:
      Visit /generate_wrapper.php?process=yourkey in the control panel.

.PARAMETER ExtraArgs
    Additional arguments forwarded to the ETL script.
    Populated by trigger.php from the process's prod_args / test_args.

.NOTES
    Deploy to: <wrapper_root>\Invoke-<YourProcess>Remote.ps1
    Register:  Run Register-AllETLTasks.ps1 as Administrator after deploying.
#>

param(
    [string]$ExtraArgs = ""
)
$ErrorActionPreference = "Stop"

# ── Configure these two values ────────────────────────────────────────────────
$remoteServer = "your-sql-server"
$remoteScript = "C:\Scripts\your-etl-script.ps1"
# ─────────────────────────────────────────────────────────────────────────────

try {
    Invoke-Command -ComputerName $remoteServer `
                   -Authentication Negotiate `
                   -ScriptBlock {
                       param($script, $extraArgs)
                       Set-ExecutionPolicy Bypass -Scope Process -Force
                       if ($extraArgs) {
                           Invoke-Expression "& `"$script`" $extraArgs"
                       } else {
                           & $script
                       }
                   } `
                   -ArgumentList $remoteScript, $ExtraArgs
} catch {
    throw
}
