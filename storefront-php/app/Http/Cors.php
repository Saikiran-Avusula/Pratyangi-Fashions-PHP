<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** CORS like Node's `cors({ origin: [list], credentials: true })`: exact allow-list, credentials, preflight 204, requested headers reflected. */
final class Cors
{
    private const METHODS = 'GET,HEAD,PUT,PATCH,POST,DELETE';

    /** @param list<string> $allowedOrigins exact origins, no trailing slash */
    public function __construct(private array $allowedOrigins)
    {
    }

    /** Adds the headers every response gets. */
    public function apply(Request $request, Response $response): void
    {
        $origin = $request->header('origin');
        if ($origin !== null && in_array($origin, $this->allowedOrigins, true)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
        }
        $response->appendHeader('Vary', 'Origin');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
    }

    /** The answer to any OPTIONS request, or null for other methods. */
    public function preflight(Request $request): ?Response
    {
        if ($request->method !== 'OPTIONS') {
            return null;
        }
        $response = new Response(204);
        $this->apply($request, $response);
        $response->setHeader('Access-Control-Allow-Methods', self::METHODS);
        $requested = $request->header('access-control-request-headers');
        $response->appendHeader('Vary', 'Access-Control-Request-Headers');
        if ($requested !== null && $requested !== '') {
            $response->setHeader('Access-Control-Allow-Headers', $requested);
        }
        $response->setHeader('Content-Length', '0');
        return $response;
    }
}
