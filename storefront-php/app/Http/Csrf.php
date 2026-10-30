<?php
declare(strict_types=1);

namespace Pratyangi\Http;

use Pratyangi\Support\Logger;

/**
 * CSRF defence on top of the SameSite=Lax cookie: a request that changes anything must name our own website in its Origin header.
 * Match against WEB_ORIGIN after lower-casing and dropping a trailing slash or the default port (:443 for https, :80 for http).
 * Without an Origin header (some older browsers) the Referer's origin is used instead; "null" and anything else is refused.
 * Runs before the session lookup. A refusal is logged (WARNING) with the addresses involved, never cookies or the body.
 */
final class Csrf
{
    /** @var list<string> */
    private array $allowed;

    /** @param list<string> $allowedOrigins */
    public function __construct(array $allowedOrigins, private readonly ?Logger $logger = null)
    {
        $this->allowed = array_values(array_filter(array_map(static fn (string $o): string => self::normalise($o), $allowedOrigins), static fn (string $o): bool => $o !== ''));
    }

    public function check(Request $request): void
    {
        if (!$request->isUnsafe()) {
            return;
        }
        $given = $request->header('origin');
        $referer = $request->header('referer') ?? '';
        $origin = self::normalise($given ?? '');
        if ($given === null || $given === '') {
            $origin = self::normalise(self::originOf($referer));
        }
        if ($origin !== '' && in_array($origin, $this->allowed, true)) {
            return;
        }
        $this->logger?->warn('origin check refused', [
            'origin' => self::clip($given),
            'referer_host' => self::clip(parse_url($referer, PHP_URL_HOST) ?: null),
            'host' => self::clip($request->header('host')),
            'https' => is_string($_SERVER['HTTPS'] ?? null) ? $_SERVER['HTTPS'] : '',
            'x_forwarded_proto' => self::clip($request->header('x-forwarded-proto')),
            'allowed' => $this->allowed,
        ]);
        throw ApiException::forbidden('Please open ' . ($this->allowed[0] ?? '') . ' and try again.');
    }

    private static function clip(mixed $value): ?string
    {
        return is_string($value) ? substr($value, 0, 200) : null;
    }

    /** "https://Host:443/path?x" -> "https://Host:443"; '' when it is not an http(s) address. */
    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return '';
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function normalise(string $origin): string
    {
        $origin = strtolower(rtrim(trim($origin), '/'));
        return preg_replace('~^(https://.+):443$|^(http://.+):80$~', '$1$2', $origin) ?? $origin;
    }
}
