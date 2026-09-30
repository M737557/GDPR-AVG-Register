@echo off
timeout /t 30
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\scripts\watchdogwindows\watchdog-dev\WK\versioning backup.ps1"