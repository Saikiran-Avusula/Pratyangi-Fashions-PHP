<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Http\Response;
use Pratyangi\Views\View;

/** The branded HTML pages for 403, 404, 429, 500 and 503 (browser pages only; /api/* keeps its JSON errors). Never shows error details. */
final class ErrorPages
{
    /** status => [title, line, icon] */
    private const TEXT = [
        403 => ['Access denied', 'You do not have permission to see this page.', 'package'],
        404 => ['Page not found', 'The page you are looking for does not exist or may have moved.', 'search'],
        429 => ['Too many tries', 'Please wait a minute and try again.', 'package'],
        500 => ['Something went wrong on our side', 'Please try again in a moment.', 'package'],
        503 => ["We'll be right back", 'The shop is being updated. Please check back in a little while.', 'package'],
    ];

    /** @return list<int> */
    public static function statuses(): array
    {
        return array_keys(self::TEXT);
    }

    public static function response(int $status, ?HeaderAccount $account = null): Response
    {
        $status = isset(self::TEXT[$status]) ? $status : 500;
        [$title, $line, $icon] = self::TEXT[$status];
        $body = View::render('errors/page', ['account' => $account, 'errIcon' => $icon, 'errTitle' => $title, 'errLine' => $line, 'search' => $status === 404]);
        $response = Response::html(View::render('layout', ['title' => $title . ' | Pratyangi Fashions', 'content' => $body, 'scripts' => ['site.js', 'bag.js'], 'noindex' => true]), $status)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Robots-Tag', 'noindex');
        return $status === 429 || $status === 503 ? $response->setHeader('Retry-After', '60') : $response;
    }
}
