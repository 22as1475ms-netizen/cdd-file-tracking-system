@echo off
setlocal

set "MYSQL_DUMP=C:\xampp\mysql\bin\mysqldump.exe"
set "BACKUP_DIR=%~dp0storage\backups"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HHmmss"') do set "STAMP=%%I"
set "BACKUP_FILE=%BACKUP_DIR%\cddfts-backup-%STAMP%.sql"

echo Creating backup at %BACKUP_FILE%
"%MYSQL_DUMP%" --protocol=tcp -h 127.0.0.1 -P 3306 --single-transaction --quick --skip-lock-tables --routines --triggers --databases cddfts > "%BACKUP_FILE%"

if errorlevel 1 (
  echo Backup failed.
  del "%BACKUP_FILE%" >nul 2>nul
  exit /b 1
)

echo Backup complete.
echo %BACKUP_FILE%
endlocal