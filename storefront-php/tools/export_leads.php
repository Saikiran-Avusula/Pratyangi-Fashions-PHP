<?php
declare(strict_types=1);

// Command line only. Writes a CSV of the customers who said yes to offers (at least one channel ticked and not turned off since):
// name, mobile, mobile_status (verified | unverified | none), email, channels, consent_date (UTC, the newest yes).
// Customers with no consent are not in it. Prints the CSV on screen, or into a file:   php tools/export_leads.php [--out=leads.csv]
// A file is written only into storage/exports/ (outside public/, never served): --out takes a file NAME, any folder part is dropped.
// The file holds personal data: delete it when the campaign is sent.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\Config;
use Pratyangi\Db;

/** A cell that starts with = + - @ would run as a formula in a spreadsheet. */
function lead_cell(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
}

/**
 * @return list<list<string>> header first, then one row per customer
 */
function lead_rows(\PDO $pdo): array
{
    $sql = "SELECT c.id, c.name, c.email, c.mobile, c.mobile_verified_at, l.channels, l.latest,
                   (SELECT a.phone FROM shop_customer_addresses a WHERE a.customer_id = c.id ORDER BY a.is_default DESC, a.id LIMIT 1) AS address_phone
            FROM shop_customers c
            JOIN (SELECT g.customer_id, GROUP_CONCAT(g.channel ORDER BY FIELD(g.channel, 'sms', 'whatsapp', 'email') SEPARATOR ' ') AS channels, MAX(g.created_at) AS latest
                  FROM shop_consent_log g
                  WHERE g.granted = 1 AND g.id = (SELECT MAX(x.id) FROM shop_consent_log x WHERE x.customer_id = g.customer_id AND x.channel = g.channel)
                  GROUP BY g.customer_id) l ON l.customer_id = c.id
            ORDER BY c.id";
    $verified = [];
    foreach ($pdo->query('SELECT customer_id, mobile FROM shop_verified_numbers')->fetchAll(\PDO::FETCH_ASSOC) as $v) {
        $verified[$v['customer_id'] . ':' . $v['mobile']] = true;
    }
    $out = [['name', 'mobile', 'mobile_status', 'email', 'channels', 'consent_date']];
    foreach ($pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $digits = $r['mobile'] !== null ? substr((string) $r['mobile'], -10) : (string) ($r['address_phone'] ?? '');
        $status = 'none';
        if ($digits !== '') {
            $status = ($r['mobile'] !== null && $r['mobile_verified_at'] !== null) || isset($verified[$r['id'] . ':' . $digits]) ? 'verified' : 'unverified';
        }
        $out[] = [lead_cell((string) $r['name']), $digits, $status, lead_cell((string) ($r['email'] ?? '')), (string) $r['channels'], substr((string) $r['latest'], 0, 10)];
    }
    return $out;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $pdo = Db::connect(Config::load());
    } catch (\Throwable) {
        fwrite(STDERR, "Could not read the settings or reach the database.\n");
        exit(1);
    }
    $path = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--out=')) {
            $path = substr($arg, 6);
        }
    }
    if ($path !== null) {
        $dir = dirname(__DIR__) . '/storage/exports';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            fwrite(STDERR, "Could not create storage/exports.
");
            exit(1);
        }
        $path = $dir . '/' . (preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path)) ?: 'leads.csv');
    }
    $handle = $path === null ? fopen('php://output', 'wb') : fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Could not open the output file.\n");
        exit(1);
    }
    $rows = lead_rows($pdo);
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    if ($path !== null) {
        fclose($handle);
        fwrite(STDERR, (count($rows) - 1) . ' customers written to storage/exports/' . basename($path) . ".\nWARNING: this file holds personal data (names, mobiles, emails). Keep it private and delete it after the campaign.\n");
    }
}
