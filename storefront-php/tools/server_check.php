<?php
declare(strict_types=1);

// The server health page: GET /server-check?key=<SERVER_CHECK_KEY>. Routed by App; it answers 404 (as if there were no such page) unless the
// key is set in the settings and matches. It reports yes/no and short fixed words only: never a password, key, host name, path or server reply.
use Pratyangi\Config;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;

return static function (Config $config, Request $request, \Closure $pdo): Response {
    $given = $request->query['key'] ?? '';
    if (!is_string($given) || !$config->serverCheckKeyMatches($given)) {
        return Response::json(['message' => 'Not found.'], 404);
    }
    $root = dirname(__DIR__);
    $yes = static fn (bool $ok): string => $ok ? 'ok' : 'FAIL';
    $checks = ['php' => PHP_VERSION . ' ' . $yes(version_compare(PHP_VERSION, '8.1.0', '>='))];
    foreach (['pdo_mysql', 'openssl', 'mbstring', 'json', 'curl'] as $ext) {
        $checks['ext ' . $ext] = $yes(extension_loaded($ext));
    }
    try {
        $pdo()->query('SELECT 1');
        $checks['database'] = 'ok';
    } catch (\Throwable) {
        $checks['database'] = 'FAIL (cannot connect: check DB_* in the settings file)';
    }
    // every table in sql/ALL_IN_ORDER.sql must exist; a migration is "missing" when a table its section creates is not there
    $sql = is_file($root . '/sql/ALL_IN_ORDER.sql') ? (string) file_get_contents($root . '/sql/ALL_IN_ORDER.sql') : '';
    if ($sql === '') {
        $checks['required tables'] = 'FAIL sql/ALL_IN_ORDER.sql not found';
    } elseif ($checks['database'] === 'ok') {
        $have = $pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $missing = [];
        $badMigrations = [];
        foreach (preg_split('/^-- ===== /m', $sql) ?: [] as $section) {
            if (preg_match('/^(\d+[a-z]?)_/', $section, $n) !== 1) {
                continue;
            }
            preg_match_all('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/', $section, $t);
            $lost = array_diff($t[1], $have);
            if ($lost !== []) {
                $missing = array_merge($missing, $lost);
                $badMigrations[] = $n[1];
            }
        }
        $checks['required tables'] = $missing === [] ? 'ok' : 'FAIL missing ' . implode(', ', array_unique($missing));
        $checks['migrations'] = $badMigrations === [] ? 'ok' : 'FAIL missing ' . implode(', ', $badMigrations);
    } else {
        $checks['required tables'] = 'FAIL (no database)';
    }
    foreach (['storage', 'cache'] as $dir) {
        $checks['writable ' . $dir] = $yes(is_dir($root . '/' . $dir) && is_writable($root . '/' . $dir));
    }
    $checks['settings file outside public'] = $yes(is_file($root . '/config.local.php'));
    $checks['mail'] = $config->mailMode === 'smtp' ? ($config->smtpHost !== null && $config->smtpUser !== null && $config->smtpPassword() !== null ? 'smtp, settings present' : 'FAIL smtp settings missing') : 'mode ' . $config->mailMode;
    $checks['erp'] = $config->erpMode === 'http' ? ($config->erpBaseUrl !== null && $config->erpApiKey() !== null ? 'http, settings present' : 'FAIL erp settings missing') : 'mode ' . $config->erpMode;
    if ($config->erpMode === 'http' && $config->erpBaseUrl !== null) {
        // any answer at all (even 401/404) proves the server can reach the ERP; no key is sent
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
        $checks['erp reachable'] = $yes(@file_get_contents(rtrim($config->erpBaseUrl, '/') . '/', false, $ctx) !== false || (isset($http_response_header) && $http_response_header !== []));
    }
    $checks['staging'] = $config->staging ? 'true (noindex)' : 'false';
    $checks['payments / checkout'] = ($config->paymentsEnabled ? 'on' : 'off') . ' / ' . ($config->checkoutEnabled ? 'on' : 'off');
    $response = Response::json($checks);
    $response->setHeader('Cache-Control', 'no-store');
    return $response;
};
