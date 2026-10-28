<?php
declare(strict_types=1);

// Shared by tools/capture_node.php and tools/compare_php.php: the fixed scenario, the HTTP runner and the normaliser.
// Only localhost / 127.0.0.1 is ever contacted, and only with throw-away test accounts. Needs no database access of its own.

const PF_TOOL_HEADERS = [
    'content-type', 'cache-control', 'ratelimit-policy', 'ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset',
    'retry-after', 'access-control-allow-origin', 'access-control-allow-credentials', 'access-control-allow-methods',
    'access-control-allow-headers', 'vary', 'x-request-id', 'x-content-type-options', 'x-frame-options', 'referrer-policy',
];
// headers whose value changes on every call: compared by presence only
const PF_TOOL_VOLATILE_HEADERS = ['x-request-id', 'ratelimit-remaining', 'ratelimit-reset', 'retry-after'];

/** Refuses anything but a plain http address on localhost / 127.0.0.1. Returns the base URL without a trailing slash. */
function pf_check_base(string $base): string
{
    $parts = parse_url($base);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    if (!in_array($host, ['localhost', '127.0.0.1'], true) || !in_array($scheme, ['http', 'https'], true)) {
        fwrite(STDERR, "Refused: only http(s)://localhost or http(s)://127.0.0.1 may be used.\n");
        exit(2);
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        fwrite(STDERR, "Refused: no credentials in the address.\n");
        exit(2);
    }
    return rtrim($base, '/');
}

/**
 * @return array{base:string,origin:string,dir:string}
 */
function pf_parse_args(array $argv, string $usage): array
{
    $base = null;
    $origin = 'http://localhost:3000';
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--origin=')) {
            $origin = substr($arg, 9);
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, $usage . "\n");
            exit(2);
        } else {
            $base = $arg;
        }
    }
    if ($base === null) {
        fwrite(STDERR, $usage . "\n");
        exit(2);
    }
    return ['base' => pf_check_base($base), 'origin' => $origin, 'dir' => dirname(__DIR__, 2) . '/tests/reference'];
}

/**
 * Steps in order. A step: case, method, path, optional headers/body/rawBody, cookie (true = send the current session cookie),
 * origin (null = none, default = the allowed one), keepCookie (true = remember the Set-Cookie value), values (false = compare shape only).
 * @return list<array<string,mixed>>
 */
function pf_scenario(string $email, string $password, string $origin): array
{
    $p = '/api/v1';
    $other = 'https://evil.example.invalid';
    return [
        ['case' => 'health', 'method' => 'GET', 'path' => $p . '/health'],
        ['case' => 'health_root', 'method' => 'GET', 'path' => '/health'],
        ['case' => 'not_found', 'method' => 'GET', 'path' => $p . '/no/such/route'],
        ['case' => 'preflight', 'method' => 'OPTIONS', 'path' => $p . '/auth/login', 'headers' => ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type']],
        ['case' => 'signup', 'method' => 'POST', 'path' => $p . '/auth/signup', 'body' => ['name' => 'Ref Tester', 'email' => $email, 'password' => $password], 'keepCookie' => true],
        ['case' => 'signup_duplicate', 'method' => 'POST', 'path' => $p . '/auth/signup', 'body' => ['name' => 'Ref Tester', 'email' => $email, 'password' => $password]],
        ['case' => 'me', 'method' => 'GET', 'path' => $p . '/auth/me', 'cookie' => true],
        ['case' => 'me_no_cookie', 'method' => 'GET', 'path' => $p . '/auth/me'],
        ['case' => 'logout', 'method' => 'POST', 'path' => $p . '/auth/logout', 'cookie' => true],
        ['case' => 'me_after_logout', 'method' => 'GET', 'path' => $p . '/auth/me', 'cookie' => true],
        ['case' => 'login', 'method' => 'POST', 'path' => $p . '/auth/login', 'body' => ['email' => $email, 'password' => $password], 'keepCookie' => true],
        ['case' => 'login_wrong_password', 'method' => 'POST', 'path' => $p . '/auth/login', 'body' => ['email' => $email, 'password' => 'Wrong1234pass']],
        ['case' => 'login_unknown_email', 'method' => 'POST', 'path' => $p . '/auth/login', 'body' => ['email' => 'nobody-' . substr(md5($email), 0, 8) . '@example.test', 'password' => 'Wrong1234pass']],
        ['case' => 'login_empty_422', 'method' => 'POST', 'path' => $p . '/auth/login', 'body' => []],
        ['case' => 'signup_invalid_422', 'method' => 'POST', 'path' => $p . '/auth/signup', 'body' => ['name' => 'x', 'email' => 'not-an-email', 'password' => 'short']],
        ['case' => 'signup_no_body_422', 'method' => 'POST', 'path' => $p . '/auth/signup'],
        ['case' => 'verify_bad_token', 'method' => 'POST', 'path' => $p . '/auth/email/verify', 'body' => ['token' => str_repeat('a', 43)]],
        ['case' => 'verify_malformed_422', 'method' => 'POST', 'path' => $p . '/auth/email/verify', 'body' => ['token' => 'short']],
        ['case' => 'forgot', 'method' => 'POST', 'path' => $p . '/auth/password/forgot', 'body' => ['email' => $email]],
        ['case' => 'forgot_bad_email_422', 'method' => 'POST', 'path' => $p . '/auth/password/forgot', 'body' => ['email' => 'nope']],
        ['case' => 'reset_bad_code', 'method' => 'POST', 'path' => $p . '/auth/password/reset', 'body' => ['email' => $email, 'code' => '000000', 'password' => 'NewSaree2027']],
        ['case' => 'reset_bad_format_422', 'method' => 'POST', 'path' => $p . '/auth/password/reset', 'body' => ['email' => $email, 'code' => '12', 'password' => 'short']],
        ['case' => 'csrf_wrong_origin', 'method' => 'POST', 'path' => $p . '/auth/login', 'body' => ['email' => $email, 'password' => $password], 'origin' => $other],
        ['case' => 'csrf_missing_origin', 'method' => 'POST', 'path' => $p . '/auth/login', 'body' => ['email' => $email, 'password' => $password], 'origin' => null],
        ['case' => 'csrf_before_session', 'method' => 'POST', 'path' => $p . '/auth/email/resend', 'body' => [], 'origin' => $other],
        ['case' => 'resend_no_session_401', 'method' => 'POST', 'path' => $p . '/auth/email/resend', 'body' => []],
        ['case' => 'catalog_default', 'method' => 'GET', 'path' => $p . '/catalog/products', 'values' => false],
        ['case' => 'catalog_limit2', 'method' => 'GET', 'path' => $p . '/catalog/products?limit=2', 'values' => false],
        ['case' => 'catalog_cursor', 'method' => 'GET', 'path' => $p . '/catalog/products?limit=2&cursor=2', 'values' => false],
        ['case' => 'catalog_category_q', 'method' => 'GET', 'path' => $p . '/catalog/products?category=Sarees&q=silk', 'values' => false],
        ['case' => 'catalog_limit_too_big_422', 'method' => 'GET', 'path' => $p . '/catalog/products?limit=101'],
        ['case' => 'catalog_bad_cursor_422', 'method' => 'GET', 'path' => $p . '/catalog/products?cursor=abc'],
        ['case' => 'catalog_cors', 'method' => 'GET', 'path' => $p . '/catalog/products?limit=1', 'headers' => ['Origin' => $origin], 'values' => false],
    ];
}

/**
 * Runs the steps and returns case => normalised result. Test accounts only: the email is generated per run.
 * @param list<array<string,mixed>> $steps
 * @return array<string,array<string,mixed>>
 */
function pf_run(string $base, string $origin, array $steps, string $email): array
{
    $cookie = null;
    $out = [];
    foreach ($steps as $step) {
        $headers = (array) ($step['headers'] ?? []);
        $stepOrigin = array_key_exists('origin', $step) ? $step['origin'] : $origin;
        if ($stepOrigin !== null && $step['method'] !== 'GET' && !isset($headers['Origin'])) {
            $headers['Origin'] = $stepOrigin;
        }
        if (!empty($step['cookie']) && $cookie !== null) {
            $headers['Cookie'] = 'pf_session=' . $cookie;
        }
        $raw = null;
        if (array_key_exists('body', $step)) {
            $raw = json_encode($step['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }
        $res = pf_http($base . $step['path'], (string) $step['method'], $headers, $raw);
        if ($res === null) {
            fwrite(STDERR, 'No answer for case ' . $step['case'] . " (is the server running on that address?)\n");
            exit(3);
        }
        if (!empty($step['keepCookie'])) {
            foreach ($res['setCookies'] as $line) {
                if (preg_match('/^pf_session=([^;]*)/', $line, $m) === 1 && $m[1] !== '') {
                    $cookie = $m[1];
                }
            }
        }
        $out[(string) $step['case']] = pf_normalise($step, $res, $email);
    }
    return $out;
}

/**
 * @param array<string,string> $headers
 * @return array{status:int,headers:array<string,string>,setCookies:list<string>,body:string}|null
 */
function pf_http(string $url, string $method, array $headers, ?string $raw): ?array
{
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = $k . ': ' . $v;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $lines),
        'content' => $raw ?? '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 20,
    ]]);
    $body = @file_get_contents($url, false, $context);
    /** @var list<string>|null $responseHeaders */
    $responseHeaders = $http_response_header ?? null;
    if ($body === false || $responseHeaders === null || $responseHeaders === []) {
        return null;
    }
    $status = 0;
    $map = [];
    $setCookies = [];
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
            $map = [];
            $setCookies = [];
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        if ($name === 'set-cookie') {
            $setCookies[] = $value;
        } else {
            $map[$name] = isset($map[$name]) ? $map[$name] . ', ' . $value : $value;
        }
    }
    return ['status' => $status, 'headers' => $map, 'setCookies' => $setCookies, 'body' => $body];
}

/**
 * @param array<string,mixed> $step
 * @param array{status:int,headers:array<string,string>,setCookies:list<string>,body:string} $res
 * @return array<string,mixed>
 */
function pf_normalise(array $step, array $res, string $email): array
{
    $headers = [];
    foreach (PF_TOOL_HEADERS as $name) {
        if (isset($res['headers'][$name])) {
            $headers[$name] = in_array($name, PF_TOOL_VOLATILE_HEADERS, true) ? '<VARIES>' : $res['headers'][$name];
        }
    }
    $cookies = [];
    foreach ($res['setCookies'] as $line) {
        $parts = array_map('trim', explode(';', $line));
        $pair = explode('=', array_shift($parts), 2);
        $flags = ['name' => $pair[0], 'value' => ($pair[1] ?? '') === '' ? '<EMPTY>' : '<TOKEN>', 'httpOnly' => false, 'secure' => false, 'sameSite' => null, 'path' => null, 'expires' => false, 'maxAge' => false, 'domain' => false];
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            switch (strtolower($kv[0])) {
                case 'httponly':
                    $flags['httpOnly'] = true;
                    break;
                case 'secure':
                    $flags['secure'] = true;
                    break;
                case 'samesite':
                    $flags['sameSite'] = $kv[1] ?? '';
                    break;
                case 'path':
                    $flags['path'] = $kv[1] ?? '';
                    break;
                case 'expires':
                    $flags['expires'] = true;
                    break;
                case 'max-age':
                    $flags['maxAge'] = true;
                    break;
                case 'domain':
                    $flags['domain'] = true;
                    break;
            }
        }
        $cookies[] = $flags;
    }
    $decoded = json_decode($res['body'], true);
    $body = $res['body'] === '' ? null : (json_last_error() === JSON_ERROR_NONE ? pf_placeholders($decoded, $email, null) : '<NOT JSON>');
    return [
        'case' => $step['case'],
        'request' => $step['method'] . ' ' . preg_replace('/[?].*$/', '?...', (string) $step['path']),
        'compareValues' => ($step['values'] ?? true) !== false,
        'status' => $res['status'],
        'headers' => $headers,
        'cookies' => $cookies,
        'bodyEmpty' => $res['body'] === '',
        'body' => $body,
    ];
}

/** Replaces values that change per run (ids, times, the test email) with fixed markers. */
function pf_placeholders(mixed $value, string $email, ?string $key): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = pf_placeholders($v, $email, (string) $k);
        }
        return $out;
    }
    if (is_string($value)) {
        if ($key === 'id') {
            return '<ID>';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/D', $value) === 1) {
            return '<ISO_TIME>';
        }
        return str_replace($email, '<EMAIL>', $value);
    }
    return $value;
}

/** Describes the type of a decoded value: used to compare shapes. */
function pf_type(mixed $v): string
{
    if (is_array($v)) {
        return array_is_list($v) ? 'list' : 'object';
    }
    return get_debug_type($v);
}

/**
 * Differences between a reference and a fresh result. Checks status, header names and values, cookie flags, key order, types
 * and (unless the step says shape only) values.
 * @param array<string,mixed> $ref
 * @param array<string,mixed> $now
 * @return list<string>
 */
function pf_diff(array $ref, array $now): array
{
    $d = [];
    if ($ref['status'] !== $now['status']) {
        $d[] = 'status: expected ' . $ref['status'] . ', got ' . $now['status'];
    }
    foreach (array_unique(array_merge(array_keys($ref['headers']), array_keys($now['headers']))) as $h) {
        $a = $ref['headers'][$h] ?? '<absent>';
        $b = $now['headers'][$h] ?? '<absent>';
        if ($h === 'content-type') {
            $a = strtolower(str_replace(' ', '', $a));
            $b = strtolower(str_replace(' ', '', $b));
        }
        if ($a !== $b) {
            $d[] = "header $h: expected $a, got $b";
        }
    }
    if (count($ref['cookies']) !== count($now['cookies'])) {
        $d[] = 'cookies: expected ' . count($ref['cookies']) . ' Set-Cookie header(s), got ' . count($now['cookies']);
    } else {
        foreach ($ref['cookies'] as $i => $c) {
            foreach ($c as $flag => $expected) {
                if ($expected !== $now['cookies'][$i][$flag]) {
                    $d[] = "cookie $i $flag: expected " . json_encode($expected) . ', got ' . json_encode($now['cookies'][$i][$flag]);
                }
            }
        }
    }
    if ($ref['bodyEmpty'] !== $now['bodyEmpty']) {
        $d[] = 'body: ' . ($ref['bodyEmpty'] ? 'expected empty' : 'expected content, got empty');
    } else {
        pf_diff_value($ref['body'], $now['body'], '$', (bool) $ref['compareValues'], $d);
    }
    return $d;
}

/** @param list<string> $d */
function pf_diff_value(mixed $a, mixed $b, string $path, bool $values, array &$d): void
{
    $ta = pf_type($a);
    $tb = pf_type($b);
    if ($ta !== $tb) {
        $d[] = "$path: expected type $ta, got $tb";
        return;
    }
    if ($ta === 'object') {
        if (array_keys($a) !== array_keys($b)) {
            $d[] = "$path: keys expected [" . implode(',', array_keys($a)) . '] in that order, got [' . implode(',', array_keys($b)) . ']';
        }
        foreach ($a as $k => $v) {
            if (array_key_exists($k, $b)) {
                pf_diff_value($v, $b[$k], $path . '.' . $k, $values, $d);
            }
        }
        return;
    }
    if ($ta === 'list') {
        if ($values && count($a) !== count($b)) {
            $d[] = "$path: expected " . count($a) . ' items, got ' . count($b);
        }
        for ($i = 0, $n = min(count($a), count($b), $values ? PHP_INT_MAX : 1); $i < $n; $i++) {
            pf_diff_value($a[$i], $b[$i], $path . '[' . $i . ']', $values, $d);
        }
        return;
    }
    if ($values && $a !== $b) {
        $d[] = "$path: expected " . json_encode($a) . ', got ' . json_encode($b);
    }
}
