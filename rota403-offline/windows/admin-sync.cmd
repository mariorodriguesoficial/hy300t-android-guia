@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0elevar.ps1" configurar-sync.ps1 %*
