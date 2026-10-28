# run from storefront-php:  .\tools\check.ps1
"== ports =="
foreach($p in 3399,8095,8100){ "{0}: {1}" -f $p, $(if(Get-NetTCPConnection -State Listen -LocalPort $p -ErrorAction SilentlyContinue){'UP'}else{'DOWN'}) }
"== syntax =="
$bad = Get-ChildItem app,public,tests,tools -Recurse -Filter *.php | ForEach-Object { $o = php -l $_.FullName 2>&1; if($LASTEXITCODE){$o} }
if($bad){$bad}else{'OK'}
"== tests (no database) =="
Get-ChildItem tests -Filter *_test.php | Where-Object { $_.Name -notmatch '^(db_|http_smoke)' } | ForEach-Object { php $_.FullName *> $null; '{0}: {1}' -f $_.Name, $(if($LASTEXITCODE -eq 0){'PASS'}else{'FAIL'}) }
