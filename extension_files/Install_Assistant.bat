@echo off
title Wubachat Smart Dispatcher Setup
color 0A

echo ========================================================
echo          Wubachat Smart Dispatcher Setup
echo ========================================================
echo.

set TARGET_DIR=C:\Wubachat-Smart-Dispatcher
if not exist "%TARGET_DIR%" mkdir "%TARGET_DIR%"

copy /y "%~dp0manifest.json" "%TARGET_DIR%\" >nul
copy /y "%~dp0content.js" "%TARGET_DIR%\" >nul

echo [OK] Extension files ready in C:\Wubachat-Smart-Dispatcher
echo.
echo ========================================================
echo                  FINAL SETUP STEPS:
echo ========================================================
echo 1. Turn ON 'Developer mode' (Top-Right in Chrome).
echo 2. Click 'Load unpacked' (Top-Left).
echo 3. Select folder: C:\Wubachat-Smart-Dispatcher
echo ========================================================
echo.

explorer.exe "%TARGET_DIR%"
start chrome "chrome://extensions/"

pause