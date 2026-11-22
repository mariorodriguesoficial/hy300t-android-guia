@echo off
cd /d "%~dp0.."
runtime\php\php.exe -c runtime\php\php.ini -S 0.0.0.0:8080 -t app
