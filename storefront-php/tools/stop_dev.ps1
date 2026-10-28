# Stops what tools\start_dev.ps1 started: the shop (8100), the scratch ERP (8095) and the throwaway MySQL (3399, shut down cleanly; the data is kept).
# Only a php process on the shop and ERP ports is stopped, and only when it is the one start_dev recorded or the port's own listener.
# If the flow-test Node API (4001) or web (3100) is running it still needs the ERP and MySQL, so those two are left running (use flow-test\stop.ps1).
$ErrorActionPreference = 'Continue'
. 'E:\Testing fashion store\flow-test\scripts\lib.ps1'

function Stop-Port([string]$Name, [string]$Program, [int]$Port) {
  $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique)
  if ($listeners.Count -eq 0) { "${Name}: not running"; return }
  foreach ($id in $listeners) {
    $proc = Get-Process -Id $id -ErrorAction SilentlyContinue
    if ($proc -and $proc.ProcessName -eq $Program) { Stop-Process -Id $id -Force -ErrorAction SilentlyContinue; "${Name}: stopped" }
    else { "${Name}: skipped (port $Port is held by '$($proc.ProcessName)', not by $Program)" }
  }
}

Stop-Port 'Shop   ' 'php' 8100
if ((Test-FlowPort $ApiPort) -or (Test-FlowPort $WebPort)) {
  "ERP / MySQL: left running, the flow-test API ($ApiPort) or web ($WebPort) is still up. Use E:\Testing fashion store\flow-test\stop.ps1 to stop it all."
  return
}
Stop-Port 'ERP    ' 'php' $ErpPort
if (Test-FlowPort $MysqlPort) {
  try {
    $env:MYSQL_PWD = Get-ErpConfigValue 'pass'
    & (Join-Path $MysqlBin 'mysqladmin.exe') --no-defaults --protocol=tcp -h 127.0.0.1 -P $MysqlPort -u root shutdown 2>&1 | Out-Null
  } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
  Start-Sleep -Seconds 3
  if (Test-FlowPort $MysqlPort) { 'MySQL : did not stop (port 3399 is still in use); try flow-test\stop.ps1' } else { 'MySQL : stopped (data kept)' }
} else { 'MySQL : not running' }
