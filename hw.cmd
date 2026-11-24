@echo off
setlocal
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0hw.ps1" %*
if %ERRORLEVEL% EQU 0 goto :eof
if %ERRORLEVEL% NEQ 9009 goto :err

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0hw.ps1" %*
if %ERRORLEVEL% EQU 0 goto :eof

:err
exit /b %ERRORLEVEL%
