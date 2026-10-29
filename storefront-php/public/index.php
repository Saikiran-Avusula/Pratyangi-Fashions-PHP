<?php
declare(strict_types=1);

// The only entry point. Everything under /api/v1 (and GET /health) comes through here.

require dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\App;
use Pratyangi\Config;
use Pratyangi\ConfigException;
use Pratyangi\Http\Request;

/** The last-resort 500 (the app itself could not start or crashed): a browser page gets the static HTML, /api/* and /health get JSON. Never any detail. */
function pf_fatal(): void
{
    if (headers_sent()) {
        echo '{"message":"Something went wrong on our side. Please try again in a moment."}';
        return;
    }
    http_response_code(500);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!str_starts_with($path, '/api/') && $path !== '/health' && is_file(__DIR__ . '/errors/500.html')) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        readfile(__DIR__ . '/errors/500.html');
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo '{"message":"Something went wrong on our side. Please try again in a moment."}';
}

try {
    $config = Config::load();
    $app = App::create($config);
    $request = Request::fromGlobals();
    $app->handle($request)->send($request->method);
} catch (ConfigException $e) {
    // the text names settings, never values, and goes to the server log only
    error_log('storefront settings problem: ' . $e->getMessage());
    pf_fatal();
} catch (\Throwable $e) {
    error_log('storefront fatal ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    pf_fatal();
}
