<?php
declare(strict_types=1);

// Sets the company details (SHOP_* keys below) in config.local.php. Run:  php tools/set_shop_config.php
// It copies every other line through untouched and unread, never prints a line or a value (only the key names it changed), and writes through a
// temporary file in the same folder. A key that is already in the file has its line replaced; a missing one is added before the closing "];".
// shortcut: assumes each setting sits on one line ('KEY' => 'value',), as in config.example.php; a value spread over several lines needs a manual edit.
$values = [
    'SHOP_NAME' => 'Pratyangi Fashions',
    'SHOP_LEGAL_NAME' => 'Vistaraverse E-Cart Private Limited',
    'SHOP_ADDRESS' => '#102, Sahiti Ramkuteer, Jai Hind Gandhi Road, VIP Hills, Silicon Valley, Madhapur, Hyderabad, Telangana 500081',
    'SHOP_CIN' => 'U46901AP2026PTC126193',
    'SHOP_SE_REG' => 'SEA/RAN/ALO/BN/1425883/2026',
    'SHOP_GSTIN' => '36AAMCV3017H1Z9',
    'SHOP_PHONE' => '+91 99892 33328',
    'SHOP_WHATSAPP' => '919989233328',
    'SHOP_SUPPORT_EMAIL' => 'info@pratyangi.com',
    'SHOP_GRIEVANCE_NAME' => '',
    'SHOP_GRIEVANCE_EMAIL' => 'grievance@pratyangi.com',
    'SHOP_HOURS' => '9:00 AM - 5:00 PM, Monday to Saturday',
];
$path = dirname(__DIR__) . '/config.local.php';
if (!is_file($path)) {
    fwrite(STDERR, "config.local.php not found\n");
    exit(1);
}
$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    exit(1);
}
$pending = $values;
foreach ($lines as $i => $line) {
    if (preg_match("/^\s*'(SHOP_[A-Z_]+)'\s*=>/", $line, $m) === 1 && array_key_exists($m[1], $values)) {
        $lines[$i] = "    '" . $m[1] . "' => " . var_export($values[$m[1]], true) . ',';
        unset($pending[$m[1]]);
    }
}
if ($pending !== []) {
    $close = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*\];\s*$/', $line) === 1) {
            $close = $i;
        }
    }
    if ($close === null) {
        fwrite(STDERR, "closing ]; not found; nothing written\n");
        exit(1);
    }
    $add = [];
    foreach ($pending as $key => $value) {
        $add[] = "    '" . $key . "' => " . var_export($value, true) . ',';
    }
    array_splice($lines, $close, 0, $add);
}
$tmp = $path . '.tmp' . bin2hex(random_bytes(3));
if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
    exit(1);
}
// the new file must still be PHP that returns settings which load; only setting NAMES are ever reported
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    putenv('PF_CONFIG=' . $tmp);
    \Pratyangi\Config::load();
} catch (\Throwable $e) {
    unlink($tmp);
    fwrite(STDERR, 'not written: the file would not load (' . ($e instanceof \Pratyangi\ConfigException ? $e->getMessage() : get_class($e)) . ")\n");
    exit(1);
}
rename($tmp, $path);
echo 'set: ' . implode(', ', array_keys($values)) . "\nsettings load: ok\n";
