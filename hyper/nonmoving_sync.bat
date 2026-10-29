@echo off
cd /d "%~dp0"
set PY1="C:\Users\ALPRO\AppData\Local\Programs\Python\Python314\python.exe"
set PY2="C:\Users\app-octopus-a\AppData\Local\Programs\Python\Python313\python.exe"
if exist %PY1% (set PYTHON=%PY1%) else (set PYTHON=%PY2%)
%PYTHON% nonmoving_sync.py --sync-all
