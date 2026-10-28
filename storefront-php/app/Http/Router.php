<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** A small route table. Matching is like Express's defaults: case-insensitive, one optional trailing slash, HEAD served by GET routes. */
final class Router
{
    /** @var list<array{method:string,path:string,handler:\Closure,bucket:?string,auth:bool,pattern:?string,paramNames:list<string>}> */
    private array $routes = [];

    /**
     * @param \Closure(Request,Context,...mixed):Response $handler
     * @param string|null $bucket rate-limit bucket checked after the sign-in check and before the handler
     * @param bool $auth true = answer 401 when nobody is signed in
     */
    public function add(string $method, string $path, \Closure $handler, ?string $bucket = null, bool $auth = false): void
    {
        $path = strtolower($path);
        $paramNames = [];
        $pattern = null;
        if (str_contains($path, ':')) {
            $parts = explode('/', trim($path, '/'));
            $regexParts = [];
            foreach ($parts as $part) {
                if (str_starts_with($part, ':')) {
                    $paramNames[] = substr($part, 1);
                    $regexParts[] = '([^/]+)';
                } else {
                    $regexParts[] = preg_quote($part, '#');
                }
            }
            $pattern = '#^/' . implode('/', $regexParts) . '$#D';
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'bucket' => $bucket,
            'auth' => $auth,
            'pattern' => $pattern,
            'paramNames' => $paramNames,
        ];
    }

    /** @return array{method:string,path:string,handler:\Closure,bucket:?string,auth:bool,params:list<string>}|null */
    public function find(string $method, string $path): ?array
    {
        $path = self::normalise($path);
        $method = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if ($route['pattern'] === null) {
                if ($route['path'] === $path) {
                    return [
                        'method' => $route['method'],
                        'path' => $route['path'],
                        'handler' => $route['handler'],
                        'bucket' => $route['bucket'],
                        'auth' => $route['auth'],
                        'params' => [],
                    ];
                }
            } else {
                if (preg_match($route['pattern'], $path, $matches) === 1) {
                    $params = array_slice($matches, 1);
                    return [
                        'method' => $route['method'],
                        'path' => $route['path'],
                        'handler' => $route['handler'],
                        'bucket' => $route['bucket'],
                        'auth' => $route['auth'],
                        'params' => $params,
                    ];
                }
            }
        }
        return null;
    }

    /** Lower case, one trailing slash removed ("/" stays). */
    public static function normalise(string $path): string
    {
        $path = strtolower($path);
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }
        return $path === '' ? '/' : $path;
    }

    /** The part after /api/v1 ("" for the prefix itself), or null when the path is outside the API. */
    public static function apiPath(string $path): ?string
    {
        $path = self::normalise($path);
        if ($path === '/api/v1') {
            return '/';
        }
        return str_starts_with($path, '/api/v1/') ? substr($path, strlen('/api/v1')) : null;
    }
}
