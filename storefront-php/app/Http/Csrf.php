<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/**
 * CSRF defence on top of the SameSite=Lax cookie: a request that changes anything must name our own website in its Origin header.
 * Exact match against WEB_ORIGIN (one trailing slash ignored). A missing Origin is refused. Runs before the session lookup.
 */
final class Csrf
{
    public const MESSAGE = 'This request did not come from the Pratyangi Fashions website.';

    /** @var list<string> */
    private array $allowed;

    /** @param list<string> $allowedOrigins */
    public function __construct(array $allowedOrigins)
    {
        $this->allowed = array_values(array_filter(array_map(static fn (string $o): string => self::strip($o), $allowedOrigins), static fn (string $o): bool => $o !== ''));
    }

    public function check(Request $request): void
    {
        if (!$request->isUnsafe()) {
            return;
        }
        $origin = self::strip($request->header('origin') ?? '');
        if ($origin === '' || !in_array($origin, $this->allowed, true)) {
            throw ApiException::forbidden(self::MESSAGE);
        }
    }

    private static function strip(string $origin): string
    {
        return str_ends_with($origin, '/') ? substr($origin, 0, -1) : $origin;
    }
}
