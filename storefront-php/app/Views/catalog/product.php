<?php
declare(strict_types=1);

use Pratyangi\Catalog\ProductImage;
use Pratyangi\Views\View;
use Pratyangi\Web\CatalogLinks;
use Pratyangi\Web\CatalogPages;

/** @var array<string,mixed> $p the product (a catalogue item) */
/** @var list<array<string,mixed>> $options other SKUs with the same name and category */
// The web's ProductView: photo, name, colour and size, price (MRP struck through when higher), tax line, stock line, other options, add to bag.
$name = (string) $p['name'];
$sku = (string) $p['sku'];
$inStock = (bool) $p['inStock'];
$left = (int) $p['availableQuantity'];
$subtitle = CatalogPages::subtitle($p);
$tax = (float) ($p['taxPercent'] ?? 0);
if (!$inStock) {
    $stock = 'Out of stock';
} elseif ($left > 0 && $left <= CatalogPages::LOW_STOCK_AT) {
    $stock = 'Only ' . $left . ' left';
} else {
    $stock = 'In stock';
}
?><article class="udK_nG_product" aria-labelledby="product-title" data-pf="product" data-sku="<?= View::e($sku) ?>"><div class="udK_nG_photo"><img alt="<?= View::e($name) ?>" fetchpriority="high" decoding="async" data-nimg="fill" style="position:absolute;height:100%;width:100%;left:0;top:0;right:0;bottom:0;object-position:center top;color:transparent" sizes="(max-width: 900px) 92vw, 46vw" src="<?= View::e(ProductImage::url($p['imagePath'] ?? null)) ?>"/></div><div class="udK_nG_info"><h1 id="product-title"><?= View::e($name) ?></h1><?php if ($subtitle !== '') { ?><p class="udK_nG_subtitle"><?= View::e($subtitle) ?></p><?php } ?><p class="udK_nG_price"><strong><?= View::e(View::rs($p['price'])) ?></strong><?php if ($p['mrp'] > $p['price']) { ?><del><?= View::e(View::rs($p['mrp'])) ?></del><?php } ?></p><?php if ($tax > 0) { ?><p class="udK_nG_stock">Plus <?= View::e($p['taxPercent']) ?>% tax, added at checkout.</p><?php } ?><p class="<?= $inStock ? 'udK_nG_stock' : 'udK_nG_stock udK_nG_soldOut' ?>"><?= View::e($stock) ?></p><?php if ($options !== []) { ?><section class="udK_nG_options" aria-labelledby="options-title"><h2 id="options-title">Other options</h2><ul><li><span class="udK_nG_chip udK_nG_chipCurrent" aria-current="true"><?= View::e(CatalogPages::label($p)) ?></span></li><?php foreach ($options as $o) { $label = CatalogPages::label($o); ?><li><a href="<?= View::e(CatalogLinks::productHref((string) $o['sku'])) ?>" class="<?= $o['inStock'] ? 'udK_nG_chip' : 'udK_nG_chip udK_nG_chipSoldOut' ?>" aria-label="<?= View::e($o['inStock'] ? $label : $label . ' (sold out)') ?>"><?= View::e($label) ?></a></li><?php } ?></ul></section><?php } ?><button type="button" class="udK_nG_add" data-pf="add-product" data-sku="<?= View::e($sku) ?>" data-bag-href="<?= View::e(CatalogLinks::BAG) ?>"<?= $inStock ? '' : ' disabled=""' ?>><?= $inStock ? 'Add to bag' : 'Sold out' ?></button><button type="button" class="udK_nG_add pf_wishText" data-pf="wish" data-sku="<?= View::e($sku) ?>" aria-pressed="false">Add to wishlist</button><?php if (!$inStock) { ?><p class="udK_nG_note" role="status">This option is sold out.</p><?php } ?></div></article>
