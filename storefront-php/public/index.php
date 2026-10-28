<?php
declare(strict_types=1);

// The only entry point. Everything under /api/v1 (and GET /health) comes through here.

require dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\App;
use Pratyangi\Config;
use Pratyangi\ConfigException;
use Pratyangi\Http\Request;

try {
    $config = Config::load();
    $app = App::create($config);
    $request = Request::fromGlobals();
    $app->handle($request)->send($request->method);
} catch (ConfigException $e) {
    // the text names settings, never values, and goes to the server log only
    error_log('storefront settings problem: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"message":"Something went wrong on our side. Please try again in a moment."}';
} catch (\Throwable $e) {
    error_log('storefront fatal ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo '{"message":"Something went wrong on our side. Please try again in a moment."}';
}
