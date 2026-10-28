# Local PHP storefront environment

Local environment (the PHP site only works when all three are up):
- 127.0.0.1:3399 throwaway MySQL (database pratyangi_php_test)
- 127.0.0.1:8095 scratch ERP (supplies the product catalogue)
- 127.0.0.1:8100 PHP site: php -S 127.0.0.1:8100 -t public (from storefront-php)

To start MySQL and the ERP, the user runs in PowerShell:
  cd "E:\Testing fashion store\flow-test"
  .\start.ps1 -NoPayments
(it also starts the Node API on 4001 and web on 3100; the PHP site doesn't need them). Do not try to start these yourself.

Check ports first: Get-NetTCPConnection -State Listen -LocalPort 3399,8095,8100
If a page says "We could not load products right now", the cause is almost always port 8095 being DOWN. Check the ports before touching code or the database. Never open config.local.php to diagnose it.
