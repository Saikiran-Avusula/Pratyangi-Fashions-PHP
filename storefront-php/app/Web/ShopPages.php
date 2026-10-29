<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Address\AddressRepo;
use Pratyangi\Catalog\CatalogService;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Support\Logger;
use Pratyangi\Views\View;

/**
 * GET /bag, GET /checkout and GET /account (the pages of the web's app/bag, app/checkout and app/account).
 * The bag lives in the browser, so /bag and /checkout are drawn by public/assets/bag-page.js from the saved bag and the catalogue; the server
 * prints the frame (and, on /bag, the "You May Also Like" cards). /checkout and /account need a session: a signed-out visitor is sent to
 * /login?next=<the page>, as the web does. Every dynamic value is escaped with View::e; nothing from the address is printed.
 */
final class ShopPages
{
    private const COOKIE = 'pf_session';
    private const BAG_STYLES = ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css'];
    private const RECOMMENDED = 4;
    private const MAX_PRODUCTS = 2000;

    /**
     * @param (\Closure(string):(array<string,mixed>|null))|null $sessionLookup cookie token => customer row or null; only called when a pf_session cookie is sent
     * @param (\Closure():AddressRepo)|null $addressRepoFactory
     */
    public function __construct(
        private CatalogService $catalog,
        private ?\Closure $sessionLookup = null,
        private ?Logger $logger = null,
        private ?\Closure $addressRepoFactory = null,
        private bool $smsEnabled = true,
        private ?\Closure $orderRepoFactory = null, // (\Closure():OrderRepo)
    ) {
    }

    public function bag(Request $request): Response
    {
        $row = $this->customer($request->cookie(self::COOKIE));
        $recommended = $this->recommended();
        $body = View::render('shop/bag', [
            'account' => $row === null ? null : HeaderAccount::fromRow($row),
            'checkoutHref' => $row === null ? '/login?next=/checkout' : '/checkout',
            'recommended' => $recommended,
        ]);
        return $this->page($request, 'Your Bag | Pratyangi Fashions', 'Review the handwoven sarees in your Pratyangi Fashions bag.', $body, ['site.js', 'bag.js', 'bag-page.js'], $recommended === null);
    }

    public function checkout(Request $request): Response
    {
        $row = $this->customer($request->cookie(self::COOKIE));
        if ($row === null) {
            return $this->signIn($request, '/login?next=/checkout');
        }
        $addresses = [];
        if ($this->addressRepoFactory !== null) {
            try {
                $repo = ($this->addressRepoFactory)();
                if ($repo instanceof AddressRepo) {
                    $addresses = $repo->listForCustomer((int) $row['id']);
                }
            } catch (\Throwable $e) {
                $this->logger?->warn('shop pages: addresses lookup failed', ['exception' => get_class($e)]);
            }
        }
        $body = View::render('shop/checkout', [
            'account' => HeaderAccount::fromRow($row),
            'addresses' => $addresses,
            'smsEnabled' => $this->smsEnabled,
        ]);
        return $this->page($request, 'Checkout | Pratyangi Fashions', 'Choose your delivery address and delivery option, then review your Pratyangi Fashions order.', $body, ['site.js', 'bag.js', 'offers.js', 'address-book.js', 'bag-page.js']);
    }

    public function account(Request $request, string $section = 'overview'): Response
    {
        $row = $this->customer($request->cookie(self::COOKIE));
        if ($row === null) {
            return $this->signIn($request, '/login?next=/account' . ($section === 'overview' ? '' : '/' . $section));
        }
        $orderCount = 0;
        if ($this->orderRepoFactory !== null) {
            try {
                $orderCount = ($this->orderRepoFactory)()->countForCustomer((int) $row['id']);
            } catch (\Throwable $e) {
                $this->logger?->warn('shop pages: orders count failed', ['exception' => get_class($e)]);
            }
        }
        $mobile = '';
        foreach (['mobile', 'phone'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                $mobile = trim($row[$key]);
                break;
            }
        }
        $addresses = [];
        if ($this->addressRepoFactory !== null) {
            try {
                $repo = ($this->addressRepoFactory)();
                if ($repo instanceof AddressRepo) {
                    $addresses = $repo->listForCustomer((int) $row['id']);
                }
            } catch (\Throwable $e) {
                $this->logger?->warn('shop pages: addresses lookup failed', ['exception' => get_class($e)]);
            }
        }
        $body = View::render('shop/account', [
            'account' => HeaderAccount::fromRow($row),
            'memberSince' => self::memberSince($row['created_at'] ?? null),
            'mobile' => $mobile,
            'emailVerified' => ($row['email_verified_at'] ?? null) !== null,
            'showVerify' => ($request->query['verify'] ?? '') === '1', // the banner only when asked for: before a first order, a password reset or a change of email
            'addresses' => $addresses,
            'smsEnabled' => $this->smsEnabled,
            'orderCount' => $orderCount,
            'section' => $section,
        ]);
        return $this->page($request, ($section === 'overview' ? 'My Account' : ucfirst($section)) . ' | Pratyangi Fashions', 'Manage your Pratyangi Fashions orders, addresses and more.', $body, ['site.js', 'offers.js', 'address-book.js', 'account.js']);
    }

    /** "Oct 2026" from the SQL created_at (UTC), or "" when it is missing or unreadable (the web's memberSince()). */
    private static function memberSince(mixed $createdAt): string
    {
        if (!is_string($createdAt) || $createdAt === '') {
            return '';
        }
        $time = strtotime($createdAt . ' UTC');
        return $time === false ? '' : gmdate('M Y', $time);
    }

    /** The first four in-stock products in the catalogue's order, or null when the catalogue cannot be read (the reason is never shown). @return list<array<string,mixed>>|null */
    private function recommended(): ?array
    {
        try {
            $result = $this->catalog->list(['category' => null, 'q' => null, 'limit' => self::MAX_PRODUCTS, 'cursor' => null]);
        } catch (\Throwable $e) {
            $this->logger?->warn('shop pages: catalogue unavailable', ['exception' => get_class($e)]);
            return null;
        }
        if ($result['page']['nextCursor'] !== null) {
            return null;
        }
        $inStock = array_values(array_filter($result['page']['products'], static fn (array $p): bool => (bool) $p['inStock']));
        return array_slice($inStock, 0, self::RECOMMENDED);
    }

    private function signIn(Request $request, string $to): Response
    {
        $token = $request->cookie(self::COOKIE);
        $response = Response::html('', 302)->setHeader('Location', $to . ($token !== null && $token !== '' ? '&expired=1' : '')); // a cookie that no longer works = the session ended
        return self::perVisitor($response, $request->cookie(self::COOKIE), true);
    }

    /** @param list<string> $scripts file names under /assets */
    private function page(Request $request, string $title, string $description, string $body, array $scripts, bool $noStore = false): Response
    {
        $response = Response::html(View::render('layout', [
            'title' => $title,
            'description' => $description,
            'content' => $body,
            'styles' => self::BAG_STYLES,
            'scripts' => $scripts,
        ]));
        return self::perVisitor($response, $request->cookie(self::COOKIE), $noStore);
    }

    /** The header differs per visitor when a session cookie is sent; a temporary failure or a redirect must not be kept by a cache. */
    private static function perVisitor(Response $response, ?string $token, bool $noStore): Response
    {
        if ($noStore) {
            $response->setHeader('Cache-Control', 'no-store');
        } elseif ($token !== null && $token !== '') {
            $response->setHeader('Cache-Control', 'private, no-store');
        }
        if ($token !== null && $token !== '') {
            $response->appendHeader('Vary', 'Cookie');
        }
        return $response;
    }

    /** The signed-in customer row, or null. Never throws: a page must render whatever happens to the lookup. @return array<string,mixed>|null */
    private function customer(?string $token): ?array
    {
        if ($token === null || $token === '' || $this->sessionLookup === null) {
            return null;
        }
        try {
            $row = ($this->sessionLookup)($token);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            $this->logger?->warn('shop pages: session lookup failed', ['exception' => get_class($e)]); // class only, never the cookie
            return null;
        }
    }
}
