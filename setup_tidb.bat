@echo off
chcp 65001 >nul
echo ========================================
echo TiDB Database Setup Tool
echo ========================================
echo.

REM 請在下方填入你的 TiDB 連線資訊
set TIDB_HOST=gateway01.ap-northeast-1.prod.aws.tidbcloud.com
set TIDB_PORT=4000
set TIDB_USER=3kk6v5CbbVeBoEK.root
set TIDB_DB=test

echo Connection Info:
echo Host: %TIDB_HOST%
echo Port: %TIDB_PORT%
echo User: %TIDB_USER%
echo Database: %TIDB_DB%
echo.

REM 檢查是否已修改預設值
if "%TIDB_HOST%"=="your-tidb-host.aws.tidbcloud.com" (
    echo [ERROR] Please edit this file and fill in your TiDB connection info!
    echo Open setup_tidb.bat with Notepad or VS Code and modify lines 7-10
    pause
    exit /b 1
)

echo Connecting to TiDB and creating database structure...
echo Please enter your TiDB password when prompted
echo.

REM 嘗試使用 SSL 連線
C:\xampp\mysql\bin\mysql.exe -h %TIDB_HOST% -P %TIDB_PORT% -u %TIDB_USER% -p --ssl %TIDB_DB% < schema.sql

REM 如果上面失敗，請取消下一行的註解，嘗試不使用 SSL
REM C:\xampp\mysql\bin\mysql.exe -h %TIDB_HOST% -P %TIDB_PORT% -u %TIDB_USER% -p %TIDB_DB% < schema.sql

if %errorlevel% equ 0 (
    echo.
    echo ========================================
    echo Database setup completed successfully!
    echo ========================================
    echo.
    echo Please make sure db.php has the correct connection settings
) else (
    echo.
    echo [ERROR] Setup failed, please check your connection info
)

pause
