<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Catalog\CatalogService;
use Pratyangi\Http\Response;
use Pratyangi\Support\Logger;
use Pratyangi\Views\View;

/** GET / (the landing page) and GET /soon. */
final class LandingController
{
    /** The web's home page shows the first four products, in-stock ones first. */
    private const SHOWN = 4;

    /**
     * @param (\Closure(string):(array<string,mixed>|null))|null $sessionLookup cookie token => customer row or null (the app passes
     *        AuthService::customerForToken; tests pass a fake). It is only called when a pf_session cookie is present.
     */
    public function __construct(private CatalogService $catalog, private ?\Closure $sessionLookup = null, private ?Logger $logger = null)
    {
    }

    /** The signed-in header state, or null (signed out). Never throws: the landing page must render whatever happens to the lookup. */
    private function account(?string $token): ?HeaderAccount
    {
        if ($token === null || $token === '' || $this->sessionLookup === null) {
            return null;
        }
        try {
            $row = ($this->sessionLookup)($token);
            return is_array($row) ? HeaderAccount::fromRow($row) : null;
        } catch (\Throwable $e) {
            $this->logger?->warn('landing: session lookup failed', ['exception' => get_class($e)]); // class only, never the cookie
            return null;
        }
    }

    /** @param string|null $sessionToken the pf_session cookie value, if any */
    public function home(?string $sessionToken = null): Response
    {
        $account = $this->account($sessionToken);
        $products = [];
        $error = false;
        try {
            $page = $this->catalog->list(['category' => null, 'q' => null, 'limit' => 100, 'cursor' => null]);
            $all = $page['page']['products'];
            usort($all, static fn (array $a, array $b): int => (int) $b['inStock'] <=> (int) $a['inStock']); // stable in PHP 8
            $products = array_slice($all, 0, self::SHOWN);
        } catch (\Throwable) {
            $error = true; // never shown to the visitor
        }
        $response = self::page('Pratyangi Fashions | Tradition Meets Elegance', View::render('home', ['products' => $products, 'catalogError' => $error, 'account' => $account]), ['site.js', 'bag.js']); // bag.js: the cards' "Add to cart"
        if ($sessionToken !== null && $sessionToken !== '') {
            $response->setHeader('Cache-Control', 'private, no-store')->appendHeader('Vary', 'Cookie'); // the header differs per visitor
        }
        return $response;
    }

    public function soon(): Response
    {
        return self::page('Coming soon | Pratyangi Fashions', View::render('soon'));
    }

    /** @param list<string> $scripts file names under /assets */
    private static function page(string $title, string $content, array $scripts = ['site.js']): Response
    {
        return Response::html(View::render('layout', ['title' => $title, 'content' => $content, 'scripts' => $scripts]));
    }
}
