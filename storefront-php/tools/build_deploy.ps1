# Builds dist\pratyangi-staging.zip: the shop for the staging server (upload it to /home/<cpanel-user>/pratyangi-staging/ and extract there).
# Contains: app\ (with the vendored mail library), public\ (the document root), sql\, tools\server_check.php, the two settings templates,
# docs\DEPLOY_STAGING.md, and empty storage\logs\ and cache\ folders.
# Never contains: tests\, .claude\, CLAUDE.md, snapshots, logs, caches, config.local.php, any .env, keys, *.ps1. Prints no file content.
# Run from the project folder:  powershell -File tools\build_deploy.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$forbidden = '(^|/)(tests|\.claude|snapshots|\.git|node_modules|dist|storage/logs/[^/]+|cache/[^/]+)(/|$)|(^|/)(CLAUDE\.md|Agents\.md|config\.local\.php|config\.dev\.php|error_log)$|\.(ps1|log|pem|key|env|zip|bak)$|(^|/)\.env'
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

New-Item -ItemType Directory -Force -Path (Join-Path $root 'dist') | Out-Null
$zipPath = Join-Path $root 'dist/pratyangi-staging.zip'
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    foreach ($f in $files) {
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $root $f), $f, 'Optimal')
    }
    # empty, writable-by-PHP folders: the server needs them to exist (their contents are never shipped)
    foreach ($keep in 'storage/logs/.keep', 'cache/.keep') { [void]$zip.CreateEntry($keep) }
} finally { $zip.Dispose() }

# check the zip itself: forward slashes, nothing forbidden, the files the server needs
$read = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = $read.Entries | ForEach-Object { $_.FullName }
} finally { $read.Dispose() }
$bad = $names | Where-Object { $_ -match $forbidden -and $_ -notmatch '^(storage/logs|cache)/\.keep$' }
if ($bad) { throw ('forbidden files in the zip: ' + ($bad -join ', ')) }
foreach ($need in 'public/index.php', 'public/.htaccess', 'app/bootstrap.php', 'sql/ALL_IN_ORDER.sql', 'tools/server_check.php', 'config.staging.example.php', 'docs/DEPLOY_STAGING.md') {
    if ($names -notcontains $need) { throw "the zip lacks $need" }
}
$kb = [math]::Round((Get-Item $zipPath).Length / 1KB)
"built $zipPath : $($names.Count) files, $kb KB"
