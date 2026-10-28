param([string[]]$Only = @())
# Throwaway MySQL only (127.0.0.1:3399, *_test database). Prints no secret. Steps: make sure MySQL + database + phase 1 tables exist,
# apply sql/003 to pratyangi_php_test, run the DB test scripts and print their exit codes.
. 'E:\Testing fashion store\flow-test\scripts\lib.ps1'
$ErrorActionPreference = 'Stop'
$project = 'E:\Testing fashion store\PHP_dev\storefront-php'
$db = 'pratyangi_php_test'
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
Set-Location $project
foreach ($t in $(if ($Only.Count) { $Only } else { @('db_otp_test', 'db_accounts_test', 'db_flow_test', 'db_address_test', 'db_order_test') })) {
  "=== $t"
  # PowerShell 5.1 turns a native program's stderr (the app's own log lines) into errors; keep going and read the exit code instead
  $ErrorActionPreference = 'Continue'
  $out = & $Php "tests\$t.php" 2>&1 | ForEach-Object { "$_" }
  $code = $LASTEXITCODE
  $ErrorActionPreference = 'Stop'
  $out | Where-Object { $_ -match 'FAIL|passed|^  ok|^\s+[A-Za-z\\]+Exception|Exception:|checks passed|^\s+- |Database:|applied|REFUSED|SKIP|^  ok' } | ForEach-Object { $_ -replace $env:PF_TEST_DB_PASS, '<hidden>' }
  "exit=$code"
}
'tables after: ' + ((Invoke-FlowMysql 'SHOW TABLES' -Database $db -Raw) -join ', ')
foreach ($n in 'PF_TEST_DB_USER', 'PF_TEST_DB_PASS', 'PF_TEST_APPLY_SCHEMA', 'PF_TEST_APPLY_OTP') { Remove-Item "Env:\$n" -ErrorAction SilentlyContinue }
