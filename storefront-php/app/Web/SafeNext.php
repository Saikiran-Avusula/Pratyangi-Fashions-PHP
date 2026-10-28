<?php
declare(strict_types=1);

namespace Pratyangi\Web;

/**
 * Where a sign-in page sends the visitor afterwards: the landing page "/", or "/checkout" when ?next=/checkout (a sign-in started there).
 * Any other ?next= is ignored, so the answer is always a single-"/" same-origin path (no "//", no scheme). The sign-in pages print the
 * answer into the page (data-pf-next) and auth.js follows it, so this is the one place the rule lives.
 */
final class SafeNext
{
    public const DEFAULT = '/';

    public static function path(?string $next): string
    {
        return $next === '/checkout' ? $next : self::DEFAULT;
    }
}
