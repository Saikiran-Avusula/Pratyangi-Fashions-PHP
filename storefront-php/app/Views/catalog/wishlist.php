<?php
declare(strict_types=1);

use Pratyangi\Catalog\ProductImage;
use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
$account = $account ?? null;
// The wishlist lives in the browser (site.js, wishlist.js); the list below is drawn by wishlist.js from the catalogue.
$signedIn = $account !== null; // header.php reuses loop names, so this is read before it runs
?><main class="_4HCqHa_page"><?php require dirname(__DIR__) . '/partials/header.php'; ?><div class="_4HCqHa_catalog"><?php if ($signedIn) { ?><a class="pf_acctBack" href="/account"><?= \Pratyangi\Views\Icons::svg('arrow-left', 20, 2) ?><span>Account</span></a><div class="pnvDaq_layout pf_wishLayout"><?= View::render('partials/account_nav', ['navActive' => 'wishlist']) ?><div class="pnvDaq_content"><?php } ?><section class="_4HCqHa_listing" id="catalog-top" aria-label="Wishlist"><div class="_4HCqHa_intro"><div class="_4HCqHa_introTitle"><h1>My Wishlist</h1><p>Your saved favourites for later</p></div></div><div class="pf_wishlist" data-pf="wishlist" data-pf-img="<?= View::e(str_ends_with($imgBase = ProductImage::url("/"), "/") ? $imgBase : "") ?>" aria-live="polite"><p class="pf_wishlist_note">Loading your wishlist&hellip;</p></div><?php if (!$signedIn) { ?><p class="pf_wishSignIn"><a href="/login?next=/wishlist">Sign in</a> to keep your wishlist on every device.</p><?php } ?></section><?php if ($signedIn) { ?></div></div><?php } ?></div><?php require dirname(__DIR__) . '/partials/footer.php'; ?></main>
