# Builds a numbered staging package:
#   dist\pratyangi-testing_vN.zip         the shop (upload it to /home/pimfi131/pratyangi-testing/ and extract there)
#   dist\pratyangi-testing_vN_update.sql  only the migrations newer than version N-1 (safe to run once)
#   dist\pratyangi-testing_vN_NOTES.md    new config keys with their staging values, and the upload steps
# Refuses to overwrite any of the three. Version N-1 is read from dist\pratyangi-testing_v(N-1).zip (v1 = dist\pratyangi-testing.zip).
# The zip holds app\ (with the vendored mail library), public\ (the document root, plus public\version.txt made here), sql\, tools\server_check.php,
# the two settings templates, docs\DEPLOY_STAGING.md, and empty storage\logs\ and cache\ folders.
# Never contains: tests\, .claude\, CLAUDE.md, snapshots, logs, caches, config.local.php, any .env, keys, *.ps1. Prints no file content.
# Run from the project folder:  powershell -File tools\build_deploy.ps1 -Version 3 -Summary "what changed"
# -OutDir is for trying the script out without touching dist\ (the previous version is still looked up in dist\).
param(
    [Parameter(Mandatory = $true)][int]$Version,
    [string]$Summary = '(no summary given)',
    [string]$OutDir = ''
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
if ($Version -lt 2) { throw 'Version must be 2 or more (v1 is dist\pratyangi-testing.zip)' }
if ($OutDir -eq '') { $OutDir = Join-Path $root 'dist' }
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$zipPath = Join-Path $OutDir "pratyangi-testing_v$Version.zip"
$sqlPath = Join-Path $OutDir "pratyangi-testing_v${Version}_update.sql"
$notesPath = Join-Path $OutDir "pratyangi-testing_v${Version}_NOTES.md"
foreach ($p in $zipPath, $sqlPath, $notesPath) { if (Test-Path $p) { throw "refusing to overwrite $p (pick a new -Version)" } }

$prev = $Version - 1
$prevZip = Join-Path $root "dist/pratyangi-testing_v$prev.zip"
if (-not (Test-Path $prevZip) -and $prev -eq 1) { $prevZip = Join-Path $root 'dist/pratyangi-testing.zip' }
if (-not (Test-Path $prevZip)) { throw "the previous version is missing: $prevZip" }

$forbidden = '(^|/)(tests|\.claude|snapshots|\.git|node_modules|dist|storage/logs/[^/]+|cache/[^/]+)(/|$)|(^|/)(CLAUDE\.md|Agents\.md|config\.local\.php|config\.dev\.php|error_log|version\.txt)$|\.(ps1|log|pem|key|env|zip|bak)$|(^|/)\.env'
$files = New-Object System.Collections.Generic.List[string]
foreach ($dir in 'app', 'public', 'sql') {
    Get-ChildItem -Path (Join-Path $root $dir) -Recurse -File -Force | ForEach-Object { $files.Add($_.FullName.Substring($root.Length + 1).Replace('\', '/')) }
}
foreach ($f in 'tools/server_check.php', 'config.example.php', 'config.staging.example.php', 'docs/DEPLOY_STAGING.md') {
    if (-not (Test-Path (Join-Path $root $f))) { throw "missing file: $f" }
    $files.Add($f)
}
$files = $files | Where-Object { $_ -notmatch $forbidden } | Sort-Object

# every PHP file must at least parse (php -l prints nothing on success)
foreach ($f in $files | Where-Object { $_ -like '*.php' }) {
    $out = & php -l (Join-Path $root $f) 2>&1
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax error in $f" }
}

$date = Get-Date -Format 'yyyy-MM-dd HH:mm'
$versionText = "v$Version`n$date`n$Summary`n"
$utf8 = New-Object System.Text.UTF8Encoding($false)
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    foreach ($f in $files) {
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $root $f), $f, 'Optimal')
    }
    # empty, writable-by-PHP folders: the server needs them to exist (their contents are never shipped)
    foreach ($keep in 'storage/logs/.keep', 'cache/.keep') { [void]$zip.CreateEntry($keep) }
    $entry = $zip.CreateEntry('public/version.txt')
    $writer = New-Object System.IO.StreamWriter($entry.Open(), $utf8)
    $writer.Write($versionText)
    $writer.Dispose()
} finally { $zip.Dispose() }

# check the zip itself: forward slashes, nothing forbidden, the files the server needs
$read = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = $read.Entries | ForEach-Object { $_.FullName }
} finally { $read.Dispose() }
$bad = $names | Where-Object { $_ -match $forbidden -and $_ -notmatch '^(storage/logs|cache)/\.keep$' -and $_ -ne 'public/version.txt' }
if ($bad) { throw ('forbidden files in the zip: ' + ($bad -join ', ')) }
foreach ($need in 'public/index.php', 'public/.htaccess', 'public/version.txt', 'app/bootstrap.php', 'sql/ALL_IN_ORDER.sql', 'tools/server_check.php', 'config.staging.example.php', 'docs/DEPLOY_STAGING.md') {
    if ($names -notcontains $need) { throw "the zip lacks $need" }
}

# what the previous version already had
$old = [System.IO.Compression.ZipFile]::OpenRead($prevZip)
try {
    $oldSql = @($old.Entries | Where-Object { $_.FullName -match '^sql/\d+[a-z]?_.*\.sql$' } | ForEach-Object { $_.FullName.Substring(4) })
    $cfgEntry = $old.GetEntry('config.staging.example.php')
    $reader = New-Object System.IO.StreamReader($cfgEntry.Open())
    $oldConfig = $reader.ReadToEnd()
    $reader.Dispose()
} finally { $old.Dispose() }
$keyPattern = "(?m)^\s*'([A-Z0-9_]+)'\s*=>\s*(.+?),\s*(//.*)?$"
$oldKeys = @([regex]::Matches($oldConfig, $keyPattern) | ForEach-Object { $_.Groups[1].Value })

# migrations newer than the previous version
$newSql = @($files | Where-Object { $_ -match '^sql/\d+[a-z]?_.*\.sql$' } | ForEach-Object { $_.Substring(4) } | Where-Object { $oldSql -notcontains $_ })
$sql = New-Object System.Text.StringBuilder
[void]$sql.AppendLine("-- Pratyangi staging v$Version update: migrations newer than v$prev. Import once into the staging database (cPanel > phpMyAdmin > Import).")
[void]$sql.AppendLine("-- Every statement is CREATE TABLE IF NOT EXISTS or a guarded change, so a second run changes nothing.")
if ($newSql.Count -eq 0) {
    [void]$sql.AppendLine("-- v$prev already holds every migration in sql\ (up to $($oldSql | Sort-Object | Select-Object -Last 1)): there is nothing to run.")
    [void]$sql.AppendLine('SELECT 1;')
}
foreach ($n in $newSql) {
    [void]$sql.AppendLine('')
    [void]$sql.AppendLine("-- ===== $n =====")
    [void]$sql.AppendLine((Get-Content -Raw -Encoding UTF8 (Join-Path $root "sql/$n")))
}
[System.IO.File]::WriteAllText($sqlPath, $sql.ToString(), $utf8)

# new config keys (name and the value the staging template uses; secrets there are placeholders)
$cfgNow = Get-Content -Raw -Encoding UTF8 (Join-Path $root 'config.staging.example.php')
$rows = @([regex]::Matches($cfgNow, $keyPattern) | Where-Object { $oldKeys -notcontains $_.Groups[1].Value } | ForEach-Object { "| ``$($_.Groups[1].Value)`` | ``$($_.Groups[2].Value.Trim())`` |" })
$keysText = if ($rows.Count -eq 0) { "No new keys since v$prev." } else { "| Key | Staging value (secrets are placeholders) |`n|---|---|`n" + ($rows -join "`n") }
$sqlText = if ($newSql.Count -eq 0) { "No new migration since v$prev (v$prev already had: $($oldSql -join ', ')). Skip the import." } else { "Import ``pratyangi-testing_v${Version}_update.sql`` once. New files: $($newSql -join ', ')." }
$notes = @"
# Staging v$Version ($date)

$Summary

Files: ``pratyangi-testing_v$Version.zip``, ``pratyangi-testing_v${Version}_update.sql``, this file.
After the upload, ``/server-check?key=...`` shows ``version`` = v$Version.

## 1. Database (before the upload)

Database ``pimfi131_pftesting`` (cPanel > phpMyAdmin > select it > Import).
$sqlText

## 2. New settings in /home/pimfi131/pratyangi-testing/config.local.php

$keysText

Add them to the existing file; never replace it.

## 3. Upload

1. Optional: set ``MAINTENANCE`` to ``true`` in ``/home/pimfi131/pratyangi-testing/config.local.php`` (keep ``MAINTENANCE_ALLOW_IP`` as your own IP).
2. cPanel > File Manager > ``/home/pimfi131/pratyangi-testing/`` > Upload ``pratyangi-testing_v$Version.zip`` > Extract here (overwrite). The zip has no ``config.local.php``, so yours stays.
3. ``MAINTENANCE`` back to ``false``. Delete the zip from the server.
4. Open ``https://test.pratyangi.com/server-check?key=<SERVER_CHECK_KEY>``: version v$Version, every row ok.
5. Check ``https://test.pratyangi.com/robots.txt`` (STAGING = Disallow: /) and ``https://www.test.pratyangi.com`` (301 to the bare host, if that name exists in DNS).
"@
[System.IO.File]::WriteAllText($notesPath, $notes, $utf8)

$kb = [math]::Round((Get-Item $zipPath).Length / 1KB)
"built $zipPath : $($names.Count) files, $kb KB; migrations: $(if ($newSql.Count) { $newSql -join ', ' } else { 'none new' }); new config keys: $($rows.Count)"

# build check: every page and email link/asset exists, and every asset is in this zip and served from it (fails the build otherwise)
$php = (Get-Command php).Source
$unpacked = Join-Path ([System.IO.Path]::GetTempPath()) ('pf_build_' + [guid]::NewGuid().ToString('N'))
[System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $unpacked)
& $php (Join-Path $root 'tests\links_crawl_test.php') $unpacked | Select-Object -Last 3
$checkCode = $LASTEXITCODE
Remove-Item -Recurse -Force $unpacked
if ($checkCode -ne 0) { throw 'build check failed: a page links to something missing from the zip or not served (run: php tests\links_crawl_test.php <unpacked folder>)' }
