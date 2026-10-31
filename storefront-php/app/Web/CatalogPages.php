<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Catalog\CatalogService;
use Pratyangi\Catalog\Collation;
use Pratyangi\Catalog\ProductImage;
use Pratyangi\Catalog\Variants;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Support\Logger;
use Pratyangi\Support\Text;
use Pratyangi\Views\CatalogHtml;
use Pratyangi\Views\View;

/**
 * GET /new-arrivals (the listing, the target of every Shop menu and search link) and GET /product?sku=...
 * Both are drawn from the same catalogue service as the home page, with the markup and wording of the web's
 * NewArrivalsView and ProductView. The web loads the whole catalogue in the browser and filters it there; here the page is
 * rendered with the whole catalogue on it (the first page of the filtered set visible) and public/assets/listing.js does the filtering.
 * Nothing from the address is printed unescaped; an unknown ?fabric= value is ignored, as in the web.
 */
final class CatalogPages
{
    private const COOKIE = 'pf_session';
    /** The web's page size (9), price step (500) and "low stock" level (3). */
    public const PAGE_SIZE = 9;
    public const PRICE_STEP = 500;
    public const LOW_STOCK_AT = 3;
    /** The web gives up on a catalogue of more than 2000 products ("too large to load in one go"). */
    private const MAX_PRODUCTS = 2000;
    private const STYLES = ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css'];
    /** Quick price ranges under the slider; null = no limit on that side. */
    private const PRESETS = [[null, 10000], [10000, 25000], [25000, 50000], [50000, 100000], [100000, null]];

    /**
     * @param (\Closure(string):(array<string,mixed>|null))|null $sessionLookup cookie token => customer row or null; only called when a pf_session cookie is sent
     */
    public function __construct(private CatalogService $catalog, private ?\Closure $sessionLookup = null, private ?Logger $logger = null)
    {
    }

    /** $collection = a category slug (/collection/<slug>): the same listing with only that category's products; 404 when the catalogue has none. */
    public function newArrivals(Request $request, ?string $collection = null): Response
    {
        $account = $this->account($request->cookie(self::COOKIE));
        $products = $this->products();
        $title = 'New Arrivals | Pratyangi Fashions';
        if ($collection !== null && $products !== null) {
            $products = array_values(array_filter($products, static fn (array $p): bool => CatalogLinks::slug((string) ($p['category'] ?? '')) === $collection));
            if ($products === []) {
                $body = View::render('catalog/product_frame', ['account' => $account, 'inner' => CatalogHtml::note(CatalogHtml::NOT_FOUND)]);
                return $this->page($request, self::PRODUCT_TITLE, self::PRODUCT_DESCRIPTION, $body, self::STYLES, ['site.js'], 404);
            }
            $title = $products[0]['category'] . ' | Pratyangi Fashions';
        }
        if ($products === null || $products === []) {
            $note = $products === null ? CatalogHtml::note(CatalogHtml::UNAVAILABLE, true, true) : CatalogHtml::note(CatalogHtml::EMPTY);
            $body = View::render('catalog/listing_message', ['account' => $account, 'note' => $note]);
            return $this->page($request, 'New Arrivals | Pratyangi Fashions', self::LISTING_DESCRIPTION, $body, ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css'], ['site.js'], $products === null ? 503 : 200, $products === null);
        }

        $prices = array_map(static fn (array $p): float => (float) $p['price'], $products);
        $priceMin = (int) (floor(min($prices) / 1000) * 1000);
        $priceMax = max((int) (ceil(max($prices) / 5000) * 5000), $priceMin + self::PRICE_STEP);
        $category = static fn (array $p): string => (string) ($p['category'] ?? 'Other');
        $fabricNames = self::distinct(array_map(static fn (array $p): string => (string) ($p['fabric'] ?? ''), $products));
        $count = static function (string $key, string $value) use ($products, $category): int {
            $n = 0;
            foreach ($products as $p) {
                $mine = $key === 'category' ? $category($p) : (string) ($p[$key] ?? '');
                if ($mine === $value) {
                    $n++;
                }
            }
            return $n;
        };

        // ?fabric=... (repeatable) ticks fabrics this catalogue has; ?occasion=... is accepted and ignored (the ERP has no occasion)
        $selected = array_values(array_filter(self::all($request, 'fabric'), static fn (string $name): bool => in_array($name, $fabricNames, true)));
        $matches = array_values(array_filter($products, static fn (array $p): bool => $selected === [] || in_array((string) ($p['fabric'] ?? ''), $selected, true)));
        $shown = [];
        foreach (array_slice($matches, 0, self::PAGE_SIZE) as $p) {
            $shown[$p['sku']] = true;
        }
        $showAllFabrics = false;
        foreach ($selected as $name) {
            if (array_search($name, $fabricNames, true) >= 5) {
                $showAllFabrics = true;
            }
        }

        $body = View::render('catalog/listing', [
            'account' => $account,
            'products' => $products,
            'heading' => $collection !== null ? (string) $products[0]['category'] : 'New Arrivals',
            'shown' => $shown,
            'categoryNames' => self::distinct(array_map($category, $products)),
            'fabricNames' => $fabricNames,
            'colorNames' => self::distinct(array_map(static fn (array $p): string => (string) ($p['color'] ?? ''), $products)),
            'count' => $count,
            'inStockCount' => count(array_filter($products, static fn (array $p): bool => (bool) $p['inStock'])),
            'selectedFabrics' => $selected,
            'showAllFabrics' => $showAllFabrics,
            'matchCount' => count($matches),
            'totalPages' => max(1, (int) ceil(count($matches) / self::PAGE_SIZE)),
            'pageSize' => self::PAGE_SIZE,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'priceStep' => self::PRICE_STEP,
            'presets' => self::PRESETS,
        ]);
        return $this->page($request, $title, self::LISTING_DESCRIPTION, $body,
            ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css', '0xslsry9_slir.css'], ['site.js', 'bag.js', 'listing.js']);
    }

    /** GET /search?q=: the matches of the header search (the same catalogue and cache as /api/v1/catalog/products?q=), up to 48. */
    public function search(Request $request): Response
    {
        $q = $request->query['q'] ?? '';
        $q = is_string($q) ? Text::trim($q) : '';
        $q = Text::length($q) > 80 ? '' : $q;
        $products = [];
        $hint = '';
        $error = false;
        if (Text::length($q) < 2) {
            $hint = 'Type at least 2 characters to search.';
        } else {
            try {
                $products = $this->catalog->list(['category' => null, 'q' => $q, 'limit' => 48, 'cursor' => null])['page']['products'];
            } catch (\Throwable $e) {
                $this->logger?->warn('search page: catalogue unavailable', ['exception' => get_class($e)]);
                $error = true;
            }
            $hint = $products === [] && !$error ? 'No products found' : '';
        }
        $body = View::render('catalog/search', ['account' => $this->account($request->cookie(self::COOKIE)), 'q' => $q, 'products' => $products, 'catalogError' => $error, 'hint' => $hint]);
        return $this->page($request, 'Search | Pratyangi Fashions', self::LISTING_DESCRIPTION, $body, ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css', '0xslsry9_slir.css'], ['site.js', 'bag.js'], 200, $error)->setHeader('X-Robots-Tag', 'noindex');
    }

    /** GET /wishlist: the frame; wishlist.js draws the saved items (the list is in the browser, so nothing here is per visitor). */
    public function wishlist(Request $request): Response
    {
        // UI10: the sized products' sizes (Variants, the one source) so "Move to bag" can open the size picker; the catalogue JSON stays as the contract says
        $sizes = [];
        foreach ($this->products() ?? [] as $p) {
            if (($attr = Variants::attr($p)) !== '') {
                $sizes[(string) $p['sku']] = $attr;
            }
        }
        $body = View::render('catalog/wishlist', ['account' => $this->account($request->cookie(self::COOKIE)), 'sizes' => $sizes]);
        return $this->page($request, 'Wishlist | Pratyangi Fashions', self::LISTING_DESCRIPTION, $body, ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css', '0xslsry9_slir.css'], ['site.js', 'bag.js', 'wishlist.js'])->setHeader('X-Robots-Tag', 'noindex');
    }

    public function product(Request $request): Response
    {
        $account = $this->account($request->cookie(self::COOKIE));
        $sku = self::all($request, 'sku')[0] ?? '';
        $products = $sku === '' ? [] : $this->products();
        $found = null;
        if ($products !== null) {
            foreach ($products as $p) {
                if ((string) $p['sku'] === $sku) {
                    $found = $p;
                    break;
                }
            }
        }
        if ($found === null) {
            // no sku, a sku the catalogue does not have (404), or a catalogue that cannot be read (a retry button, not a "not found")
            $unavailable = $products === null;
            $note = $unavailable ? CatalogHtml::note(CatalogHtml::UNAVAILABLE, true, true) : CatalogHtml::note(CatalogHtml::NOT_FOUND);
            $body = View::render('catalog/product_frame', ['account' => $account, 'inner' => $note]);
            return $this->page($request, self::PRODUCT_TITLE, self::PRODUCT_DESCRIPTION, $body, self::STYLES, ['site.js'], $unavailable ? 503 : 404, $unavailable);
        }

        $inner = View::render('catalog/product', ['p' => $found, 'options' => self::otherOptions($products ?? [], $found)]);
        $body = View::render('catalog/product_frame', ['account' => $account, 'inner' => $inner]);
        return $this->page($request, self::PRODUCT_TITLE, self::PRODUCT_DESCRIPTION, $body, self::STYLES, ['site.js', 'bag.js', 'product.js'], 200, false, self::shareMeta($request, $found));
    }

    /**
     * UI10: Open Graph + Twitter tags so WhatsApp/Facebook show a rich preview (absolute URLs).
     * shortcut: the origin comes from the Host header (escaped); switch to a configured public origin when one exists.
     * @param array<string,mixed> $p
     */
    public static function shareMeta(Request $request, array $p): string
    {
        $host = (string) ($request->header('Host') ?? '');
        $origin = (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) === 1 ? 'http://' : 'https://') . $host;
        $abs = static fn (string $path): string => str_starts_with($path, 'http') ? $path : $origin . $path;
        $title = (string) $p['name'] . ' | Pratyangi Fashions';
        $desc = View::rs($p['price']) . ' · ' . trim(self::subtitle($p) . ' · Pratyangi Fashions', ' ·');
        $tags = ['og:type' => 'product', 'og:site_name' => 'Pratyangi Fashions', 'og:title' => $title, 'og:description' => $desc,
            'og:url' => $abs(CatalogLinks::productHref((string) $p['sku'])), 'og:image' => $abs(ProductImage::url($p['imagePath'] ?? null)),
            'product:price:amount' => number_format((float) $p['price'], 2, '.', ''), 'product:price:currency' => 'INR'];
        $out = '';
        foreach ($tags as $k => $v) {
            $out .= '<meta property="' . $k . '" content="' . View::e($v) . '"/>';
        }
        foreach (['twitter:card' => 'summary_large_image', 'twitter:title' => $title, 'twitter:description' => $desc, 'twitter:image' => $tags['og:image']] as $k => $v) {
            $out .= '<meta name="' . $k . '" content="' . View::e($v) . '"/>';
        }
        return $out;
    }

    private const LISTING_DESCRIPTION = 'Discover the latest additions to the Pratyangi Fashions collection of handwoven silk sarees.';
    private const PRODUCT_TITLE = 'Product | Pratyangi Fashions';
    private const PRODUCT_DESCRIPTION = 'See a Pratyangi Fashions product, choose an option and add it to your bag.';

    /** The web's otherOptions(): other SKUs with the same name and category, ordered by their label (colour and size) then SKU. @param list<array<string,mixed>> $products @param array<string,mixed> $selected @return list<array<string,mixed>> */
    public static function otherOptions(array $products, array $selected): array
    {
        $key = static fn (array $p): string => $p['name'] . '|' . ($p['category'] ?? 'Other');
        $mine = $key($selected);
        $out = array_values(array_filter($products, static fn (array $p): bool => $p['sku'] !== $selected['sku'] && $key($p) === $mine));
        usort($out, static fn (array $a, array $b): int => Collation::compare(self::label($a), self::label($b)) ?: Collation::compare((string) $a['sku'], (string) $b['sku']));
        return $out;
    }

    /** The colour and size as the web's mapper writes them ("Maroon · M"); the SKU when there is neither. @param array<string,mixed> $p */
    public static function subtitle(array $p): string
    {
        return implode(' · ', array_filter([(string) ($p['color'] ?? ''), (string) ($p['size'] ?? '')], static fn (string $s): bool => $s !== ''));
    }

    /** @param array<string,mixed> $p */
    public static function label(array $p): string
    {
        $subtitle = self::subtitle($p);
        return $subtitle !== '' ? $subtitle : (string) $p['sku'];
    }

    /** The whole catalogue in the service's order, or null when it cannot be read (the reason is never shown). @return list<array<string,mixed>>|null */
    private function products(): ?array
    {
        try {
            $result = $this->catalog->list(['category' => null, 'q' => null, 'limit' => self::MAX_PRODUCTS, 'cursor' => null]);
        } catch (\Throwable $e) {
            $this->logger?->warn('catalogue pages: catalogue unavailable', ['exception' => get_class($e)]);
            return null;
        }
        return $result['page']['nextCursor'] !== null ? null : $result['page']['products'];
    }

    /**
     * @param list<string> $styles
     * @param list<string> $scripts
     */
    private function page(Request $request, string $title, string $description, string $body, array $styles, array $scripts, int $status = 200, bool $noStore = false, string $meta = ''): Response
    {
        $response = Response::html(View::render('layout', [
            'meta' => $meta,
            'title' => $title,
            'description' => $description,
            'content' => $body,
            'styles' => $styles,
            'scripts' => $scripts,
        ]), $status);
        $token = $request->cookie(self::COOKIE);
        if ($noStore) {
            $response->setHeader('Cache-Control', 'no-store'); // a temporary failure must not be kept by a cache
        } elseif ($token !== null && $token !== '') {
            $response->setHeader('Cache-Control', 'private, no-cache'); // the header differs per visitor; no-cache (not no-store) keeps Back instant (NAV1: no secrets on catalogue pages)
        }
        if ($token !== null && $token !== '') {
            $response->appendHeader('Vary', 'Cookie');
        }
        return $response;
    }

    /** The signed-in visitor, or null. Never throws: a page must render whatever happens to the lookup. */
    private function account(?string $token): ?HeaderAccount
    {
        if ($token === null || $token === '' || $this->sessionLookup === null) {
            return null;
        }
        try {
            $row = ($this->sessionLookup)($token);
            return is_array($row) ? HeaderAccount::fromRow($row) : null;
        } catch (\Throwable $e) {
            $this->logger?->warn('catalogue pages: session lookup failed', ['exception' => get_class($e)]); // class only, never the cookie
            return null;
        }
    }

    /** Every value of a query parameter, like URLSearchParams.getAll. @return list<string> */
    private static function all(Request $request, string $name): array
    {
        $value = $request->query[$name] ?? [];
        return array_values(is_array($value) ? $value : [$value]);
    }

    /** The web's distinct(): no blanks, no repeats, sorted like localeCompare("en"). @param list<string> $values @return list<string> */
    private static function distinct(array $values): array
    {
        $unique = array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
        usort($unique, static fn (string $a, string $b): int => Collation::compare($a, $b));
        return $unique;
    }
}
