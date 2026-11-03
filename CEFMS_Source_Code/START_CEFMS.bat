@echo off
title CEFMS - Cultural Events and Festivals Management System
cd /d "%~dp0"
set XAMPP=C:\xampp
if not exist "%XAMPP%\mysql\bin\mysql.exe" (
  echo XAMPP was not found at C:\xampp. Install XAMPP first, or edit the line "set XAMPP=" in this file.
  pause
  exit /b
)
tasklist | find /i "mysqld.exe" >nul
if errorlevel 1 (
  echo Starting MySQL...
  start "" /b "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
  timeout /t 10 >nul
)
"%XAMPP%\mysql\bin\mysql.exe" -u root -e "use cefms" 2>nul
if errorlevel 1 (
  echo Importing the CEFMS database...
  "%XAMPP%\mysql\bin\mysql.exe" -u root < sql\cefms_demo.sql
  echo Database imported.
)
set CEFMS_DB_USER=root
set CEFMS_DB_PASS=
echo.
echo CEFMS is running. Keep this window open.
echo   Public site : http://localhost:8080/
echo   Admin panel : http://localhost:8080/admin/login.php
echo   Demo logins : see README.md
start "" http://localhost:8080/
"%XAMPP%\php\php.exe" -S localhost:8080 -t public
pause
