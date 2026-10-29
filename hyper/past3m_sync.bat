@echo off
cd /d "%~dp0"
set PY1="C:\Users\ALPRO\AppData\Local\Programs\Python\Python314\python.exe"
set PY2="C:\Users\app-octopus-a\AppData\Local\Programs\Python\Python313\python.exe"
if exist %PY1% (set PYTHON=%PY1%) else (set PYTHON=%PY2%)
%PYTHON% past3m_sync.py --sync-all
echo.
echo ================================================
echo  AUTO GENERATE PICK LIST
echo  Runs only if ALL 6 syncs are complete this month and
echo  nothing was generated since - otherwise it just logs why.
echo  Log: transferpicklist\logs\auto_generate.log
echo ================================================
set PHP1="C:\xampp\php\php.exe"
set PHP2="C:\laragon\bin\php\php-8.2.12-nts-Win32-vs16-x64\php.exe"
if exist %PHP1% (set PHPEXE=%PHP1%) else (set PHPEXE=%PHP2%)
if exist %PHPEXE% (
    %PHPEXE% "%~dp0..\transferpicklist_generate.php" --auto
) else (
    echo [SKIP] PHP not found - auto generate not run.
)
