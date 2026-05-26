@echo off
:: ─────────────────────────────────────────────────────────────────────────────
:: start-local.bat — ETL Control Panel local dev launcher
:: Double-click this file or run from Command Prompt.
:: No PowerShell execution policy issues.
:: ─────────────────────────────────────────────────────────────────────────────

echo.
echo  ETL Control Panel — Local Dev Server
echo  ─────────────────────────────────────
echo.

:: Check PHP is installed
where php >nul 2>&1
if %errorlevel% neq 0 (
    echo  ERROR: PHP not found in PATH.
    echo  Install PHP 8.x by running this in PowerShell:
    echo.
    echo    powershell -c "^& ([ScriptBlock]::Create((irm 'https://www.php.net/include/download-instructions/windows.ps1'))) -Version 8.5"
    echo.
    echo  Then close and reopen your terminal.
    echo.
    pause
    exit /b 1
)

:: Check config files exist
if not exist "%~dp0config\app.php" (
    echo  ERROR: config\app.php not found.
    echo  Copy config\app.example.php to config\app.php and fill in your values.
    echo.
    pause
    exit /b 1
)

:: credentials.php only needed for sqlsrv driver mode — skip check for sqlite
findstr /i "'db_driver'.*'sqlsrv'" "%~dp0config\app.php" >nul 2>&1
if %errorlevel% equ 0 (
    if not exist "%~dp0config\credentials.php" (
        echo  ERROR: config\credentials.php not found.
        echo  Copy config\credentials.example.php to config\credentials.php and fill in your values.
        echo.
        pause
        exit /b 1
    )
)

echo  Starting PHP built-in server...
echo  URL:  http://localhost:8080
echo  Mode: local ^(no IIS or WinRM required^)
echo.
echo  Press Ctrl+C to stop the server.
echo.

cd /d "%~dp0"
php -S localhost:8080 router.php
pause
