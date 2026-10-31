<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** A response being built. Nothing is sent until send(), so tests can inspect it. */
final class Response
{
    /** Like JSON.stringify: slashes and non-ASCII letters are written as they are. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var array<string,array{0:string,1:string}> lower-case name => [name, value] */
    private array $headers = [];
    /** @var list<string> */
    private array $cookies = [];
    private string $body = '';

    public function __construct(private int $status = 200)
    {
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $text = json_encode($data, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
        $response = new self($status);
        $response->body = $text;
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    /** A page or file: the body is sent as given with the given Content-Type. */
    public static function raw(string $body, string $contentType, int $status = 200): self
    {
        $response = new self($status);
        $response->body = $body;
        $response->setHeader('Content-Type', $contentType);
        return $response;
    }

    public static function html(string $body, int $status = 200): self
    {
        return self::raw($body, 'text/html; charset=utf-8', $status);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function fromException(ApiException $e): self
    {
        return self::json($e->body(), $e->status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<mixed> */
    public function decoded(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = [$name, $value];
        return $this;
    }

    /** Adds a value to a list header such as Vary (no duplicates). */
    public function appendHeader(string $name, string $value): self
    {
        $key = strtolower($name);
        if (!isset($this->headers[$key])) {
            return $this->setHeader($name, $value);
        }
        $existing = array_map('trim', explode(',', $this->headers[$key][1]));
        if (!in_array($value, $existing, true)) {
            $this->headers[$key][1] .= ', ' . $value;
        }
        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string,string> name => value as set */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }
        return $out;
    }

    /** Set-Cookie lines. @return list<string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Same text Express writes: name=value; Path=/; Expires=<GMT>; HttpOnly; [Secure; ]SameSite=Lax. No Domain, no Max-Age.
     * A past $expires clears the cookie.
     */
    public function setCookie(string $name, string $value, int $expires, bool $secure): self
    {
        $this->cookies[] = $name . '=' . rawurlencode($value)
            . '; Path=/'
            . '; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT'
            . '; HttpOnly'
            . ($secure ? '; Secure' : '')
            . '; SameSite=Lax';
        return $this;
    }

    /** Writes status, headers and body. HEAD keeps the headers and drops the body. */
    public function send(string $method = 'GET'): void
    {
        http_response_code($this->status);
        header_remove('X-Powered-By');
        if (str_starts_with((string) $this->header('Content-Type'), 'text/html') && !$this->hasHeader('Cache-Control')) {
            $this->headers[] = ['Cache-Control', 'private, no-cache']; // pages are always re-checked; only the versioned /assets files are kept. private: the header's badges come from the visitor's pf_counts cookie (NAV1)
        }
        foreach ($this->headers as [$name, $value]) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if ($this->status === 204 || $this->status === 304) {
            return;
        }
        header('Content-Length: ' . strlen($this->body), true);
        if ($method !== 'HEAD') {
            echo $this->body;
        }
    }
}
