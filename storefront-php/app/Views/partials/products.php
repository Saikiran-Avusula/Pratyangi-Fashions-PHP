<?php
declare(strict_types=1);

use Pratyangi\Catalog\ProductImage;
use Pratyangi\Views\View;
use Pratyangi\Web\CatalogLinks;
use Pratyangi\Web\CatalogPages;

/** @var list<array<string,mixed>> $products */
/** @var bool $catalogError */
// Optional (the New Arrivals listing): $gridClass, $listing = true adds the data-* values the filters read, $shown = sku => true for the cards on the first page (the others are hidden).
$gridClass = $gridClass ?? 'YnyroG_productGrid';
$listing = $listing ?? false;
$shown = $shown ?? null;
// Mirrors the web's ProductCard / CatalogState markup (class names from the built CSS).

if ($catalogError) {
    ?><div class="GSLLNW_note" role="alert"><p>Products are being updated. Please try again in a moment.</p></div><?php
} elseif ($products === []) {
    ?><div class="GSLLNW_note"><p>No products are available right now. Please check back soon.</p></div><?php
} else {
    ?><div class="<?= View::e($gridClass) ?>"<?= $listing ? ' data-pf="grid"' : '' ?>><?php
    foreach ($products as $p) {
        $name = (string) $p['name'];
        $inStock = (bool) $p['inStock'];
        $left = (int) $p['availableQuantity'];
        $href = CatalogLinks::productHref((string) $p['sku']);
        $attrs = '';
        if ($listing) {
            $attrs = ' data-price="' . View::e($p['price']) . '" data-category="' . View::e($p['category'] ?? 'Other') . '" data-fabric="' . View::e($p['fabric'] ?? '')
                . '" data-color="' . View::e($p['color'] ?? '') . '" data-instock="' . ($inStock ? '1' : '0') . '"'
                . ($shown !== null && !isset($shown[$p['sku']]) ? ' hidden=""' : '');
        }
        $tax = (float) ($p['taxPercent'] ?? 0);
        // what the quick-view dialog shows (site.js reads these), the same wording as the product page
        $qv = ' data-qv-sku="' . View::e($p['sku']) . '" data-qv-name="' . View::e($name) . '" data-qv-price="' . View::e(View::rs($p['price'])) . '" data-qv-mrp="' . View::e($p['mrp'] > $p['price'] ? View::rs($p['mrp']) : '') . '" data-qv-tax="' . View::e($tax > 0 ? 'Plus ' . $p['taxPercent'] . '% tax, added at checkout.' : '') . '" data-qv-size="' . View::e($p['size'] ?? '') . '" data-qv-sub="' . View::e(CatalogPages::subtitle($p)) . '" data-qv-stock="' . View::e(!$inStock ? 'Out of stock' : ($left > 0 && $left <= CatalogPages::LOW_STOCK_AT ? 'Only ' . $left . ' left' : 'In stock')) . '" data-qv-instock="' . ($inStock ? '1' : '0') . '" data-qv-img="' . View::e(ProductImage::url($p['imagePath'] ?? null)) . '" data-qv-href="' . View::e($href) . '"';
        ?><article class="JEjkwq_card"<?= $attrs . $qv ?>><div class="JEjkwq_photo"><a class="JEjkwq_photoLink" href="<?= View::e($href) ?>"><img alt="<?= View::e($name) ?>" loading="lazy" decoding="async" src="<?= View::e(ProductImage::url($p['imagePath'] ?? null)) ?>" style="position:absolute;height:100%;width:100%;left:0;top:0;right:0;bottom:0;color:transparent"/></a><div class="JEjkwq_actions"><button type="button" class="JEjkwq_actionBtn" data-pf="wish" data-sku="<?= View::e($p['sku']) ?>" aria-pressed="false" aria-label="Add to wishlist"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-heart"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg></button><button type="button" class="JEjkwq_actionBtn" data-pf="quick-view" aria-haspopup="dialog" aria-label="Quick view"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div><?php if (!$inStock) { ?><span class="JEjkwq_soldOutBadge">Out of stock</span><?php } ?><button type="button" class="JEjkwq_addToCart" data-pf="add-to-bag" data-sku="<?= View::e($p['sku']) ?>"<?= $inStock ? '' : ' disabled=""' ?> aria-label="<?= View::e($inStock ? 'Add ' . $name . ' to cart' : $name . ' is out of stock') ?>"><?= $inStock ? 'Add to cart' : 'Out of stock' ?></button></div><div class="JEjkwq_meta"><h3><a href="<?= View::e($href) ?>"><?= View::e($name) ?></a></h3><p class="JEjkwq_priceRow"><span><?= View::e(View::rs($p['price'])) ?></span><?php if ($p['mrp'] > $p['price']) { ?><del><?= View::e(View::rs($p['mrp'])) ?></del><?php } ?></p><?php if ($inStock && $left > 0 && $left <= 3) { ?><p class="JEjkwq_lowStock">Only <?= View::e($left) ?> left</p><?php } ?></div></article><?php
    }
    ?></div><?php
}
