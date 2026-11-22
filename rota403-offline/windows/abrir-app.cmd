@echo off
setlocal
set PORTA=8080
if exist "%~dp0porta-http.txt" set /p PORTA=<"%~dp0porta-http.txt"
set URL=http://127.0.0.1:%PORTA%/
for %%E in ("%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe") do (
  if exist %%E (
    start "" %%E --app=%URL% --user-data-dir="%LOCALAPPDATA%\Rota403\perfil" --no-first-run
    exit /b 0
  )
)
start "" "%URL%"
