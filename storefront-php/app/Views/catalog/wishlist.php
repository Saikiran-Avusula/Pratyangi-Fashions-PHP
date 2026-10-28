<?php
declare(strict_types=1);

use Pratyangi\Catalog\ProductImage;
use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
$account = $account ?? null;
// The wishlist lives in the browser (site.js, wishlist.js); the list below is drawn by wishlist.js from the catalogue.
?><main class="_4HCqHa_page"><?php require dirname(__DIR__) . '/partials/header.php'; ?><div class="_4HCqHa_catalog"><section class="_4HCqHa_listing" id="catalog-top" aria-label="Wishlist"><div class="_4HCqHa_intro"><div class="_4HCqHa_introTitle"><h1>Your Wishlist</h1><p>Saved on this device</p></div></div><div class="pf_wishlist" data-pf="wishlist" data-pf-img="<?= View::e(str_ends_with($imgBase = ProductImage::url("/"), "/") ? $imgBase : "") ?>" aria-live="polite"><p class="pf_wishlist_note">Loading your wishlist&hellip;</p></div></section></div><?php require dirname(__DIR__) . '/partials/footer.php'; ?></main>
