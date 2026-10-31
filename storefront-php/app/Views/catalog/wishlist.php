<?php
declare(strict_types=1);

use Pratyangi\Catalog\ProductImage;
use Pratyangi\Views\Icons;
use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
$account = $account ?? null;
// The wishlist lives in the browser (site.js, wishlist.js); the list below is drawn by wishlist.js from the catalogue.
// Same shell as the /account pages (docs/figma/update/Pratyangi Fashions Wishlist.png): breadcrumb, title, the account sidebar, the content.
$signedIn = $account !== null; // header.php reuses loop names, so this is read before it runs
?><div class="pnvDaq_page"><?php require dirname(__DIR__) . '/partials/header.php'; ?><main class="pnvDaq_main"><a class="pf_acctBack" href="<?= $signedIn ? '/account' : '/' ?>"><?= Icons::svg('arrow-left', 20, 2) ?><span><?= $signedIn ? 'Account' : 'Home' ?></span></a><?php $crumbLabel = 'Wishlist'; require dirname(__DIR__) . '/partials/crumb.php'; ?><header class="pnvDaq_headingRow"><div class="pnvDaq_heading"><h1>My Wishlist</h1><p>Your saved favourites for later</p></div></header><div class="pnvDaq_layout<?= $signedIn ? '' : ' pf_noSidebar' ?>"><?= $signedIn ? View::render('partials/account_nav', ['navActive' => 'wishlist']) : '' ?><section class="pnvDaq_content" aria-label="Wishlist"><div class="pf_wishTools" data-pf="wish-tools" hidden><p class="pf_wishBanner"><?= Icons::svg('heart', 20, 2) ?><span><strong>Saved with love</strong>Your favourite pieces, all in one place.</span></p><div class="pf_wishBar"><span class="pf_wishCount" data-pf="wish-total"></span><label>Sort by <select data-pf="wish-sort"><option value="new">Newest First</option><option value="low">Price: Low to High</option><option value="high">Price: High to Low</option></select></label></div></div><div class="pf_wishlist" data-pf="wishlist" data-pf-sizes="<?= View::e(json_encode($sizes ?? [], JSON_FORCE_OBJECT)) ?>" data-pf-img="<?= View::e(str_ends_with($imgBase = ProductImage::url("/"), "/") ? $imgBase : "") ?>" aria-live="polite"></div><?php if (!$signedIn) { ?><p class="pf_wishSignIn"><a href="/login?next=/wishlist">Sign in</a> to keep your wishlist on every device.</p><?php } ?></section></div></main><?php require dirname(__DIR__) . '/partials/footer.php'; ?></div>