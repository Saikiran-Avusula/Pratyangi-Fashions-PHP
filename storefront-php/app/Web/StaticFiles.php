<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Http\Response;

/** Serves files under public/assets (needed when php -S routes everything through index.php). */
final class StaticFiles
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];

    /** The only JavaScript files that are served (site.js: every page; auth.js: the sign-in pages; bag.js: the bag in the browser; listing.js: New Arrivals; product.js: the product page; bag-page.js: the bag and checkout pages; account.js: the account page; auth-otp.js: the mobile code form on the sign-in and sign-up pages). */
    private const SCRIPTS = ['site.js', 'auth.js', 'bag.js', 'listing.js', 'product.js', 'bag-page.js', 'account.js', 'auth-otp.js', 'address-book.js', 'wishlist.js'];

    public function __construct(private string $root)
    {
    }

    /** @param string $path request path starting with /assets/; null = not a servable file */
    public function serve(string $path): ?Response
    {
        $relative = rawurldecode(substr($path, strlen('/assets/')));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || preg_match('#(^|/)\.{1,2}(/|$)#', $relative) === 1) {
            return null;
        }
        $base = realpath($this->root);
        $file = realpath($this->root . '/' . $relative);
        if ($base === false || $file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if ($type === null && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'js') {
            // only the named page scripts, directly under public/assets, are served as JavaScript (no wildcard)
            foreach (self::SCRIPTS as $script) {
                if ($file === $base . DIRECTORY_SEPARATOR . $script) {
                    $type = 'text/javascript; charset=utf-8';
                }
            }
        }
        if ($type === null) {
            return null; // only known static types are ever served
        }
        $body = file_get_contents($file);
        if ($body === false) {
            return null;
        }
        // scripts and styles are checked again on every visit, so a changed file is never run against an old one (pictures keep an hour)
        $fresh = in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['js', 'css'], true);
        return Response::raw($body, $type)->setHeader('Cache-Control', $fresh ? 'no-cache' : 'public, max-age=3600');
    }
}
