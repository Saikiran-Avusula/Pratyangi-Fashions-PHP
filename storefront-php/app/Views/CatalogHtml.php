<?php
declare(strict_types=1);

namespace Pratyangi\Views;

/** Small markup helpers for the catalogue pages. */
final class CatalogHtml
{
    /** What a visitor sees when the catalogue cannot be read (never the reason). */
    public const UNAVAILABLE = 'We could not load products right now. Please try again in a moment.';
    public const EMPTY = 'No products are available right now. Please check back soon.';
    public const NOT_FOUND = 'We could not find this product. It may no longer be sold.';

    /** The web's CatalogState "error" and "empty" boxes (class names from the compiled CSS). The message is escaped here. */
    public static function note(string $message, bool $error = false, bool $retry = false): string
    {
        return '<div class="GSLLNW_note"' . ($error ? ' role="alert"' : '') . '><p>' . View::e($message) . '</p>'
            . ($retry ? '<button type="button" data-pf="retry">Try again</button>' : '') . '</div>';
    }

    /** 100000 -> "₹1,00,000" (the web's `₹${value.toLocaleString("en-IN")}`, whole rupees). */
    public static function inr(int $rupees): string
    {
        $digits = (string) abs($rupees);
        if (strlen($digits) > 3) {
            $digits = preg_replace('/\B(?=(\d{2})+$)/', ',', substr($digits, 0, -3)) . ',' . substr($digits, -3);
        }
        return ($rupees < 0 ? '-' : '') . '₹' . $digits;
    }

    /**
     * The page buttons the pagination shows (the web's pageList): numbers, with the string "gap" for a "…".
     * @return list<int|string>
     */
    public static function pageList(int $current, int $total): array
    {
        if ($total <= 7) {
            return $total > 0 ? range(1, $total) : [];
        }
        if ($current <= 4) {
            return [1, 2, 3, 4, 5, 'gap', $total];
        }
        if ($current >= $total - 3) {
            return [1, 'gap', $total - 4, $total - 3, $total - 2, $total - 1, $total];
        }
        return [1, 'gap', $current - 1, $current, $current + 1, 'gap', $total];
    }
}
