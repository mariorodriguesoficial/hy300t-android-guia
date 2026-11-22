@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0elevar.ps1" backup.ps1 -Motivo manual
