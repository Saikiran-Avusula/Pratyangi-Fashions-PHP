<?php
declare(strict_types=1);

namespace Pratyangi\Views;

/** Tiny template support: escaping, money text and rendering a template file with variables. */
final class View
{
    /** NAV1: the header's bag / wishlist counts, from the pf_counts cookie site.js keeps in step with the browser's bag and wishlist (App::handle sets them). */
    public static array $counts = ['bag' => 0, 'wish' => 0];

    /** The badge's attributes + text, as site.js would paint it: ' hidden>0' at 0, '>3', '>9+' (so nothing changes after load). */
    public static function badge(string $kind): string
    {
        $n = self::$counts[$kind] ?? 0;
        return $n < 1 ? ' hidden>0' : '>' . ($n > 9 ? '9+' : (string) $n);
    }

    /** "/assets/site.css" -> "/assets/site.css?v=<version>": public/version.txt when the build made one, else a hash of the file. A new file = a new URL, so styles and scripts can be cached for a year. */
    public static function asset(string $path): string
    {
        static $seen = [];
        $file = dirname(__DIR__, 2) . '/public' . $path;
        $seen[$path] ??= is_file($file) ? substr(md5_file($file) ?: '', 0, 10) : '0';
        return $path . '?v=' . $seen[$path];
    }

    /** Escape a dynamic value for HTML text and quoted attributes. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The one price format (UI9c): 1799 -> "₹1,799", 1888.95 -> "₹1,888.95", 100000 -> "₹1,00,000" (Indian grouping; paise only when there are any). */
    public static function rs(int|float $rupees): string
    {
        return self::paise((int) round($rupees * 100));
    }

    /** Same format from whole paise (the database unit). */
    public static function paise(int $paise): string
    {
        $whole = (string) intdiv(abs($paise), 100);
        if (strlen($whole) > 3) {
            $whole = preg_replace('/\B(?=(\d{2})+$)/', ',', substr($whole, 0, -3)) . ',' . substr($whole, -3);
        }
        $fraction = abs($paise) % 100;
        return ($paise < 0 ? '-' : '') . '₹' . $whole . ($fraction === 0 ? '' : '.' . sprintf('%02d', $fraction));
    }

    /** @param array<string,mixed> $vars */
    public static function render(string $template, array $vars = []): string
    {
        $file = __DIR__ . '/' . $template . '.php';
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };
        return $render($file, $vars);
    }
}
