<?php
declare(strict_types=1);

// Common start-up for the web entry point and for the test scripts. Nothing here reads a settings file.

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
// shortest exact float text in json_encode (11499.5 stays 11499.5)
ini_set('serialize_precision', '-1');
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pratyangi\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Warnings and notices become exceptions (so a silent failure cannot slip through); deprecations are only written to the PHP log.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
        error_log('php deprecation: ' . $message . ' in ' . basename($file) . ':' . $line);
        return true;
    }
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Last line of defence for anything thrown outside App::handle: a generic JSON 500, never the exception text.
set_exception_handler(static function (\Throwable $e): void {
    error_log('uncaught ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '{"message":"Something went wrong on our side. Please try again in a moment."}';
});
