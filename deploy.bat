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

REM The Local path is machine-specific. Set NPA_LOCAL_PLUGIN_DIR to override it,
REM so a checkout on another machine doesn't leave this file permanently modified
REM in git just to point at a different Local site.
if defined NPA_LOCAL_PLUGIN_DIR (
  set "DEST=%NPA_LOCAL_PLUGIN_DIR%"
) else (
  set "DEST=C:\Users\dough\Local Sites\thinking-on-energy-local\app\public\wp-content\plugins\newtide-public-agent"
)

echo Deploying NewTide Public Agent to Local...
echo   From: %SRC%
echo   To:   %DEST%
echo.

REM /MIR mirrors the tree; excludes keep dev-only artifacts out of the runtime.
REM
REM Directory excludes are FULL PATHS on purpose. A bare name like "vendor" makes
REM robocopy exclude EVERY directory of that name at any depth -- which would
REM strip lib\plugin-update-checker\vendor\ (Parsedown + PucReadmeParser) and
REM break the update checker's "View details" screen. Anchor them to %SRC%.
robocopy "%SRC%." "%DEST%" /MIR ^
  /XD "%SRC%.git" "%SRC%.github" "%SRC%node_modules" "%SRC%vendor" "%SRC%tests" "%SRC%docs" ^
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
