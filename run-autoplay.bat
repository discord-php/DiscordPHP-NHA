@echo off
rem Supervised runner for the standalone LLM autoplay loop.
rem Restarts autoplay.php if it ever exits (crash, network drop, kill), so the
rem only thing that stops it for good is closing this window / Ctrl+C twice.
rem
rem   run-autoplay.bat              -- default agent from var\state.json
rem   run-autoplay.bat 142285       -- a specific agent id (passed straight through)

setlocal
cd /d "%~dp0"

:loop
echo [run-autoplay] starting %DATE% %TIME%
php autoplay.php %*
echo [run-autoplay] autoplay.php exited (code %ERRORLEVEL%) - restarting in 3s, Ctrl+C to stop
timeout /t 3 /nobreak >nul
goto loop
