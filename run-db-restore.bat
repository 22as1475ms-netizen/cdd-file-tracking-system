@echo off
setlocal

set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
set "BACKUP_DIR=%~dp0storage\backups"
set "BACKUP_FILE=%~1"

if not defined BACKUP_FILE (
  for /f "delims=" %%I in ('powershell -NoProfile -Command "Get-ChildItem -Path ''%BACKUP_DIR%'' -Filter ''cddfts-backup-*.sql'' | Sort-Object LastWriteTime -Descending | Select-Object -First 1 -ExpandProperty FullName"') do set "BACKUP_FILE=%%I"
)

if not exist "%BACKUP_FILE%" (
  echo No backup file found.
  exit /b 1
)

echo Restoring from %BACKUP_FILE%
"%MYSQL_EXE%" --protocol=tcp -h 127.0.0.1 -P 3306 < "%BACKUP_FILE%"

if errorlevel 1 (
  echo Restore failed.
  exit /b 1
)

echo Restore complete.
endlocal