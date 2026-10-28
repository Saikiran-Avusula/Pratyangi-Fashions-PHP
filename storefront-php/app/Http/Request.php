<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** One incoming request, independent of the PHP SAPI so tests can build it by hand. */
final class Request
{
    public const MAX_BODY_BYTES = 20480; // "20kb" as Node's body parser reads it

    /** @var array<string,string> lower-case name => value */
    public readonly array $headers;
    /** @var array<string,string> */
    public readonly array $cookies;
    /** @var array<string,string|list<string>> */
    public readonly array $query;
    public readonly string $path;
    private bool $bodyParsed = false;
    /** @var array<mixed>|null */
    private ?array $body = null;

    /**
     * @param array<string,string> $headers any case
     * @param string $remoteAddr the socket peer (REMOTE_ADDR)
     * @param bool $bodyTruncated true when the body was longer than MAX_BODY_BYTES (only MAX_BODY_BYTES + 1 bytes are kept)
     */
    public function __construct(
        public readonly string $method,
        string $uri,
        array $headers = [],
        public readonly string $rawBody = '',
        public readonly string $remoteAddr = '127.0.0.1',
        public readonly bool $bodyTruncated = false,
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $lower;
        $qPos = strpos($uri, '?');
        $this->path = $qPos === false ? $uri : substr($uri, 0, $qPos);
        $this->query = self::parseQuery($qPos === false ? '' : substr($uri, $qPos + 1));
        $this->cookies = self::parseCookies($lower['cookie'] ?? '');
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        // never read more than the limit plus one byte
        $stream = fopen('php://input', 'rb');
        $raw = $stream === false ? '' : (string) stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        if ($stream !== false) {
            fclose($stream);
        }
        $declared = isset($headers['content-length']) && ctype_digit($headers['content-length']) ? (int) $headers['content-length'] : 0;
        $truncated = strlen($raw) > self::MAX_BODY_BYTES || $declared > self::MAX_BODY_BYTES;
        return new self(
            strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/',
            $headers,
            $raw,
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0',
            $truncated,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isUnsafe(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * The visitor's address. TRUST_PROXY = number of reverse proxies in front of PHP: with N proxies the address is the one
     * N hops from the socket in X-Forwarded-For, counted from the right (the part the visitor cannot forge). Like Express.
     */
    public function clientIp(int $trustProxy): string
    {
        if ($trustProxy <= 0) {
            return $this->remoteAddr;
        }
        $chain = [$this->remoteAddr];
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== null) {
            foreach (array_reverse(explode(',', $forwarded)) as $hop) {
                $hop = trim($hop);
                if ($hop !== '') {
                    $chain[] = self::stripPort($hop);
                }
            }
        }
        $ip = $chain[min($trustProxy, count($chain) - 1)];
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $this->remoteAddr;
    }

    /** "1.2.3.4:5678" -> "1.2.3.4", "[::1]:5678" and "[::1]" -> "::1". Anything else is returned unchanged (and fails the IP check). */
    private static function stripPort(string $hop): string
    {
        if (preg_match('/^\[([^\]]+)\](?::\d{1,5})?$/D', $hop, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/D', $hop, $m) === 1) {
            return $m[1];
        }
        return $hop;
    }

    /**
     * The JSON body as an array (an object, or {} when there is none). Only bodies sent as application/json are read, like
     * Node's express.json: other content types count as an empty body. Throws 413 when too large and 400 when it cannot be read.
     * A top-level JSON list is returned as a list; the validator turns that into a 422.
     * @return array<mixed>
     */
    public function jsonBody(): array
    {
        if ($this->bodyParsed) {
            return $this->body ?? [];
        }
        $this->bodyParsed = true;
        $type = strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
        if ($type !== 'application/json') {
            return $this->body = [];
        }
        if ($this->bodyTruncated || strlen($this->rawBody) > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'That request is too large.');
        }
        if (trim($this->rawBody) === '') {
            return $this->body = [];
        }
        // strict mode like Node: only an object or a list at the top
        $first = ltrim($this->rawBody)[0] ?? '';
        $decoded = ($first === '{' || $first === '[') ? json_decode($this->rawBody, true, 64, JSON_BIGINT_AS_STRING) : null;
        if (!is_array($decoded)) {
            throw ApiException::badRequest('The request could not be read. Please try again.');
        }
        return $this->body = $decoded;
    }

    /** Session cookie text or null. */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** @return array<string,string|list<string>> */
    private static function parseQuery(string $qs): array
    {
        $out = [];
        if ($qs === '') {
            return $out;
        }
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $key = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
            $value = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
            if (!array_key_exists($key, $out)) {
                $out[$key] = $value;
            } elseif (is_array($out[$key])) {
                $out[$key][] = $value;
            } else {
                $out[$key] = [$out[$key], $value];
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function parseCookies(string $header): array
    {
        $out = [];
        foreach (explode(';', $header) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($part, 0, $eq));
            $value = trim(substr($part, $eq + 1));
            if ($name === '' || array_key_exists($name, $out)) {
                continue; // the first one wins, like the cookie package
            }
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = substr($value, 1, -1);
            }
            $out[$name] = rawurldecode($value);
        }
        return $out;
    }
}
