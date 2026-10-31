# -Quick: browser_layout_test with widths 360 390 1024 1440, signed in only. -DemoUi: run with DEMO_UI=true (the preview screens).
param([string[]]$Only = @(), [switch]$Quick, [switch]$DemoUi)
# Throwaway MySQL only (127.0.0.1:3399, *_test database). Prints no secret. Steps: make sure MySQL + database + phase 1 tables exist,
# apply sql/003 to pratyangi_php_test, run the DB test scripts and print their exit codes.
. 'E:\Testing fashion store\flow-test\scripts\lib.ps1'
$ErrorActionPreference = 'Stop'
$project = 'E:\Testing fashion store\PHP_dev\storefront-php'
$db = 'pratyangi_php_test'
$freeGb = [math]::Round((Get-PSDrive C).Free / 1GB, 1)
if ($freeGb -lt 20) { throw "STOP: C: has only $freeGb GB free (need 20 GB). Free up space first; nothing was run." }
# work folders and the Chrome profile live on E: (tests\lib\Tmp.php reads PF_TMP_DIR); TEMP/TMP too, so nothing a child process makes lands on C:
if (-not $env:PF_TMP_DIR) { $env:PF_TMP_DIR = 'E:\Testing fashion store\.tmp' }
New-Item -ItemType Directory -Force $env:PF_TMP_DIR | Out-Null
$oldTemp = @($env:TEMP, $env:TMP); $env:TEMP = $env:PF_TMP_DIR; $env:TMP = $env:PF_TMP_DIR
if ($MysqlPort -ne 3399) { throw 'refusing: port must be 3399' }
if ($db -notmatch '_test$') { throw 'refusing: database name must end in _test' }
if (-not (Test-FlowPort $MysqlPort)) { & 'E:\Testing fashion store\flow-test\scripts\setup-mysql.ps1' | Out-Null }
if (-not (Test-FlowPort $MysqlPort)) { throw 'MySQL on 3399 is not running' }
'MySQL 127.0.0.1:3399 listening'
Invoke-FlowMysql "CREATE DATABASE IF NOT EXISTS ``$db`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
'tables before: ' + ((Invoke-FlowMysql 'SHOW TABLES' -Database $db -Raw) -join ', ')

$env:PF_TEST_DB_HOST = '127.0.0.1'; $env:PF_TEST_DB_PORT = '3399'; $env:PF_TEST_DB_NAME = $db
$env:PF_TEST_DB_USER = 'root'; $env:PF_TEST_DB_PASS = (Get-ErpConfigValue 'pass')
$env:PF_TEST_APPLY_SCHEMA = '1'; $env:PF_TEST_APPLY_OTP = '1'
if ($Quick) { $env:PF_LAYOUT_QUICK = '1' }
if ($DemoUi) { $env:PF_TEST_DEMO_UI = 'true' }
Set-Location $project
try {
foreach ($t in $(if ($Only.Count) { $Only } else { @('db_otp_test', 'db_accounts_test', 'db_flow_test', 'db_address_test', 'db_order_test', 'db_event_mail_test') })) {
  "=== $t"
  # PowerShell 5.1 turns a native program's stderr (the app's own log lines) into errors; keep going and read the exit code instead
  $ErrorActionPreference = 'Continue'
  # the layout test's progress lines are shown as they come (the rest is filtered after the run)
  $out = & $Php "tests\$t.php" 2>&1 | ForEach-Object { $l = "$_"; if ($l -match '^layout test:|^HANG|^TIMEOUT|findings=') { Write-Host ($l -replace $env:PF_TEST_DB_PASS, '<hidden>') }; $l }
  $code = $LASTEXITCODE
  $ErrorActionPreference = 'Stop'
  $out | Where-Object { $_ -match 'FAIL|passed|^  ok|^\s+[A-Za-z\\]+Exception|Exception:|checks passed|^\s+- |Database:|applied|REFUSED|SKIP|^  ok' } | ForEach-Object { $_ -replace $env:PF_TEST_DB_PASS, '<hidden>' }
  "exit=$code"
}
'tables after: ' + ((Invoke-FlowMysql 'SHOW TABLES' -Database $db -Raw) -join ', ')
} finally {
  # each PHP script kills its own server/Chrome by PID and deletes its work folder when it ends (Tmp.php); this only restores TEMP and sweeps what a killed script left
  $env:TEMP = $oldTemp[0]; $env:TMP = $oldTemp[1]
  Get-ChildItem $env:PF_TMP_DIR -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'chrome-profile' } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
}
foreach ($n in 'PF_TEST_DB_USER', 'PF_TEST_DB_PASS', 'PF_TEST_APPLY_SCHEMA', 'PF_TEST_APPLY_OTP', 'PF_LAYOUT_QUICK', 'PF_TEST_DEMO_UI') { Remove-Item "Env:\$n" -ErrorAction SilentlyContinue }
