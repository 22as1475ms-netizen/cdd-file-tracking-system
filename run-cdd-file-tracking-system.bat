@echo off
setlocal

set "APACHE_START=C:\xampp\apache_start.bat"
set "MYSQL_EXE=C:\xampp\mysql\bin\mysqld.exe"
set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
set "APP_URL=http://localhost/CDD-File-Tracking-System/login"

tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
  echo Starting MariaDB...
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%MYSQL_EXE%' -ArgumentList '--defaults-file=%MYSQL_INI%','--standalone' -WorkingDirectory 'C:\xampp' -WindowStyle Hidden"
  timeout /t 5 /nobreak >nul
) else (
  echo MariaDB is already running.
)

tasklist /FI "IMAGENAME eq httpd.exe" | find /I "httpd.exe" >nul
if errorlevel 1 (
  echo Starting Apache...
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%APACHE_START%' -WindowStyle Hidden"
  timeout /t 5 /nobreak >nul
) else (
  echo Apache is already running.
)

echo Opening CDD-File-Tracking-System...
start "" "%APP_URL%"

echo.
echo CDD-File-Tracking-System should now be running at %APP_URL%
echo If the page was already open, refresh the browser once.
echo You can close this window.
endlocal
