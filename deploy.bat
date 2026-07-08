@echo off
REM ============================================================================
REM  deploy.bat  --  Mirror this repo into the Local WP site's plugins dir.
REM
REM  ADR-004: Local is a disposable mirror, not a git repo. Run after each batch
REM  so you test real rendered output before pushing to `main` (which IS the
REM  production deploy via Plugin Update Checker -- ADR-001).
REM
REM  Usage:  double-click, or `deploy.bat` from this folder.
REM ============================================================================

setlocal

set "SRC=%~dp0"
set "DEST=C:\Users\asama\Local Sites\asa-haugh\app\public\wp-content\plugins\newtide-public-agent"

echo Deploying NewTide Public Agent to Local...
echo   From: %SRC%
echo   To:   %DEST%
echo.

REM /MIR mirrors the tree; excludes keep dev-only artifacts out of the runtime.
robocopy "%SRC%." "%DEST%" /MIR ^
  /XD ".git" "node_modules" "vendor" "tests" ".github" "docs" ^
  /XF "deploy.bat" ".gitignore" ".phpcs.xml.dist" "composer.json" "composer.lock" "phpunit.xml.dist" "*.md"

REM robocopy exit codes 0-7 are success; 8+ are real errors.
if %ERRORLEVEL% GEQ 8 (
  echo.
  echo ERROR: robocopy reported a failure ^(code %ERRORLEVEL%^).
  exit /b %ERRORLEVEL%
)

echo.
echo Done. Activate/refresh the plugin on the Local site to test.
endlocal
