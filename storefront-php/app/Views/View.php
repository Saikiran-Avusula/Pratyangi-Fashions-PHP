<?php
declare(strict_types=1);

namespace Pratyangi\Views;

/** Tiny template support: escaping, money text and rendering a template file with variables. */
final class View
{
    /** Escape a dynamic value for HTML text and quoted attributes. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 12999 -> "Rs. 12,999.00", 129999.5 -> "Rs. 1,29,999.50" (Indian digit grouping, like the web's formatRs). */
    public static function rs(int|float $rupees): string
    {
        $paise = (int) round($rupees * 100);
        $whole = (string) intdiv($paise, 100);
        $fraction = str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
        if (strlen($whole) > 3) {
            $head = substr($whole, 0, -3);
            $whole = preg_replace('/\B(?=(\d{2})+$)/', ',', $head) . ',' . substr($whole, -3);
        }
        return 'Rs. ' . $whole . '.' . $fraction;
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
