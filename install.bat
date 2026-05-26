@echo off
:: ─────────────────────────────────────────────────────────────────────────────
:: install.bat — ETL Control Panel one-shot installer
:: Run this ONCE before start-local.bat
:: Handles: PHP download, extraction, php.ini configuration, PATH update
:: ─────────────────────────────────────────────────────────────────────────────

echo.
echo  ╔═══════════════════════════════════════════════════╗
echo  ║     ETL Control Panel — One-Shot Installer       ║
echo  ╚═══════════════════════════════════════════════════╝
echo.

:: ── Check if PHP is already installed ────────────────────────────────────────
where php >nul 2>&1
if %errorlevel% equ 0 (
    echo  [OK] PHP already installed:
    php -r "echo '       ' . PHP_VERSION . PHP_EOL;"
    goto :configure_php
)

:: ── Check if C:\PHP already exists ───────────────────────────────────────────
if exist "C:\PHP\php.exe" (
    echo  [OK] PHP found at C:\PHP but not in PATH. Fixing...
    goto :add_to_path
)

:: ── Download and install PHP ──────────────────────────────────────────────────
echo  [1/4] Downloading PHP 8.3...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "try { Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/php-8.3.31-Win32-vs16-x64.zip' -OutFile \"$env:TEMP\php.zip\" -UseBasicParsing; Write-Host '  Downloaded.' } catch { Write-Host ('  ERROR: ' + $_.Exception.Message); exit 1 }"

if %errorlevel% neq 0 (
    echo.
    echo  ERROR: PHP download failed. Check your internet connection.
    pause
    exit /b 1
)

echo  [2/4] Extracting PHP to C:\PHP...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Expand-Archive -Path \"$env:TEMP\php.zip\" -DestinationPath 'C:\PHP' -Force; Write-Host '  Extracted.'"

if not exist "C:\PHP\php.exe" (
    echo  ERROR: Extraction failed. Try running as Administrator.
    pause
    exit /b 1
)

:add_to_path
:: ── Add C:\PHP to user PATH ───────────────────────────────────────────────────
echo  [3/4] Adding C:\PHP to PATH...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$current = [Environment]::GetEnvironmentVariable('Path','User'); if ($current -notlike '*C:\PHP*') { [Environment]::SetEnvironmentVariable('Path', $current + ';C:\PHP', 'User'); Write-Host '  Added to PATH.' } else { Write-Host '  Already in PATH.' }"

:: Update PATH for current session too
set "PATH=%PATH%;C:\PHP"

:configure_php
:: ── Configure php.ini ─────────────────────────────────────────────────────────
echo  [4/4] Configuring php.ini...

:: Find php.ini location (use php.exe location)
for /f "tokens=*" %%i in ('where php 2^>nul') do set "PHP_EXE=%%i"
if not defined PHP_EXE set "PHP_EXE=C:\PHP\php.exe"
for %%i in ("%PHP_EXE%") do set "PHP_DIR=%%~dpi"

set "PHP_INI=%PHP_DIR%php.ini"
set "PHP_INI_DIST=%PHP_DIR%php.ini-development"

:: Create php.ini from development template if it doesn't exist
if not exist "%PHP_INI%" (
    if exist "%PHP_INI_DIST%" (
        copy "%PHP_INI_DIST%" "%PHP_INI%" >nul
        echo  Created php.ini from php.ini-development
    ) else (
        echo  Creating minimal php.ini...
        echo [PHP] > "%PHP_INI%"
        echo extension_dir = "ext" >> "%PHP_INI%"
    )
)

:: Enable required extensions using PowerShell for reliable string replacement
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ini = '%PHP_INI%';" ^
  "$content = Get-Content $ini -Raw;" ^
  "$extensions = @('pdo_sqlite', 'sqlite3', 'pdo', 'openssl', 'curl');" ^
  "foreach ($ext in $extensions) {" ^
  "  $commented = ';extension=' + $ext;" ^
  "  $enabled   = 'extension=' + $ext;" ^
  "  if ($content -match [regex]::Escape($commented)) {" ^
  "    $content = $content -replace [regex]::Escape($commented), $enabled;" ^
  "    Write-Host ('  Enabled: ' + $ext);" ^
  "  } elseif ($content -notmatch [regex]::Escape($enabled)) {" ^
  "    $content += \"`r`nextension=$ext\";" ^
  "    Write-Host ('  Added:   ' + $ext);" ^
  "  } else {" ^
  "    Write-Host ('  Already: ' + $ext);" ^
  "  }" ^
  "}" ^
  "Set-Content $ini $content -NoNewline;" ^
  "Write-Host '  php.ini updated.'"

:: ── Verify installation ───────────────────────────────────────────────────────
echo.
echo  ─────────────────────────────────────────────────────
echo  Verifying installation...
echo.

php -r "echo '  PHP version: ' . PHP_VERSION . PHP_EOL;"
php -r "echo '  pdo_sqlite:  ' . (extension_loaded('pdo_sqlite') ? 'OK' : 'MISSING') . PHP_EOL;"
php -r "echo '  curl:        ' . (extension_loaded('curl')        ? 'OK' : 'MISSING') . PHP_EOL;"


echo.
echo  ─────────────────────────────────────────────────────
echo.

:: ── Set up config files if not already present ───────────────────────────────
set "SCRIPT_DIR=%~dp0"

if not exist "%SCRIPT_DIR%config\app.php" (
    copy "%SCRIPT_DIR%config\app.example.php" "%SCRIPT_DIR%config\app.php" >nul
    echo  [OK] Created config\app.php from example
) else (
    echo  [OK] config\app.php already exists
)

echo.
echo  ═════════════════════════════════════════════════════
echo  Installation complete!
echo.
echo  IMPORTANT: Close this window and open a NEW terminal
echo  before running start-local.bat (PATH needs to refresh)
echo.
echo  Then run:
echo    start-local.bat
echo    Open http://localhost:8080
echo  ═════════════════════════════════════════════════════
echo.
pause
