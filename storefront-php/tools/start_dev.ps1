# Starts the local dev setup, each part in its own window, and skips any part whose port is already in use:
#   1. the throwaway MySQL on 127.0.0.1:3399          (E:\Testing fashion store\flow-test\scripts\setup-mysql.ps1)
#   2. the scratch ERP on 127.0.0.1:8095              (php -S on the scratch copy flow-test\erp\public; the copy is only read)
#   3. this shop on http://127.0.0.1:8100             (php -S, settings from config.dev.php = your config.local.php + the local test setup)
# Then it asks the scratch ERP whether the two keys really work. Prints no key, password or value.  Stop everything with tools\stop_dev.ps1.
# Mail: MAIL_MODE comes from config.local.php (smtp = real mail to real inboxes). -LogMail writes mails to storage\logs instead. SMS is always in log mode here.
# The last lines say which mode is active. A shop that is already running keeps its old mode: run tools\stop_dev.ps1, then start again.
param([switch]$LogMail)
. 'E:\Testing fashion store\flow-test\scripts\lib.ps1'
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
if ($MysqlPort -ne 3399) { throw 'refusing: the database port must be 3399' }
$shopPort = 8100
$shopDb = 'pratyangi_php_test'
if ($shopDb -notmatch '_test$') { throw 'refusing: the database name must end in _test' }

# ---- 1. MySQL 3399 (its own window; setup-mysql.ps1 starts the server hidden and prints what it did)
if (Test-FlowPort $MysqlPort) { "MySQL   : already running on 127.0.0.1:$MysqlPort, skipped" }
else {
  Start-Process -FilePath 'powershell.exe' -ArgumentList '-NoProfile', '-NoExit', '-ExecutionPolicy', 'Bypass', '-Command', "`$host.UI.RawUI.WindowTitle = 'MySQL 3399 (setup)'; & '$FlowRoot\scripts\setup-mysql.ps1'" | Out-Null
  if (-not (Wait-FlowPort $MysqlPort 120)) { throw 'MySQL did not start on 3399 (see flow-test\logs\mysql-error.log)' }
  "MySQL   : started on 127.0.0.1:$MysqlPort"
}
Invoke-FlowMysql "CREATE DATABASE IF NOT EXISTS ``$shopDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
$tables = Invoke-FlowMysql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$shopDb' AND TABLE_NAME IN ('shop_customers', 'shop_orders')" -Raw
if ([int]($tables | Select-Object -First 1) -lt 2) { "MySQL   : the shop tables are missing in $shopDb. Run  tools\run_db.ps1  once; it creates them." }

# ---- settings for the dev server (always rewritten: cheap, and it follows config.local.php)
& (Join-Path $PSScriptRoot 'dev_config.ps1') -LogMail:$LogMail

# ---- 2. scratch ERP 8095 (its own window; closing it stops the ERP)
if (Test-FlowPort $ErpPort) { "ERP     : already running on 127.0.0.1:$ErpPort, skipped" }
else {
  if (-not (Test-Path (Join-Path $FlowRoot 'erp\public'))) { throw "the scratch ERP copy was not found at $FlowRoot\erp\public" }
  $p = Start-Process -FilePath $Php -ArgumentList '-S', "127.0.0.1:$ErpPort", '-t', "`"$(Join-Path $FlowRoot 'erp\public')`"" -WindowStyle Normal -PassThru
  Set-FlowPid 'erp' $p.Id
  if (-not (Wait-FlowPort $ErpPort 30)) { throw "the scratch ERP did not start on $ErpPort" }
  "ERP     : started on 127.0.0.1:$ErpPort (scratch copy)"
}

# ---- 3. this shop on 8100 (its own window; closing it stops the server)
if (Test-FlowPort $shopPort) { "Shop    : already running on 127.0.0.1:$shopPort, skipped (it keeps the settings it started with)" }
else {
  $env:PF_CONFIG = Join-Path $project 'config.dev.php'
  try {
    $s = Start-Process -FilePath $Php -ArgumentList '-S', "127.0.0.1:$shopPort", '-t', "`"$(Join-Path $project 'public')`"", "`"$(Join-Path $project 'public\index.php')`"" -WorkingDirectory $project -WindowStyle Normal -PassThru
  } finally { Remove-Item Env:\PF_CONFIG -ErrorAction SilentlyContinue }
  Set-FlowPid 'shop' $s.Id
  if (-not (Wait-FlowPort $shopPort 30)) { throw "the shop did not start on $shopPort" }
  "Shop    : started on http://127.0.0.1:$shopPort"
}

# ---- the keys: does the scratch ERP accept ERP_CATALOG_KEY and ERP_ORDER_KEY?
& (Join-Path $PSScriptRoot 'dev_config.ps1') -Check
''
$env:PF_CONFIG = Join-Path $project 'config.dev.php'
try { $mailMode = & $Php -r "require '$($project.Replace('\','/'))/app/bootstrap.php'; echo Pratyangi\Config::load()->mailMode;" } finally { Remove-Item Env:\PF_CONFIG -ErrorAction SilentlyContinue }
"Mail mode : $mailMode" + $(if ($mailMode -eq 'smtp') { '  (real e-mail: codes and receipts reach real inboxes)' } else { '  (log: mails are written to storage\logs, nothing is sent; start without -LogMail and set MAIL_MODE=smtp in config.local.php to send)' })
'SMS mode  : log (never sends on the dev server)'
"Open http://127.0.0.1:$shopPort   |   stop with tools\stop_dev.ps1"
