@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0elevar.ps1" firewall-lan.ps1 -Acao Status
