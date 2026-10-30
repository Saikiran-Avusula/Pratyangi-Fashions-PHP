<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var string $section payments | membership | reviews */
/** @var string $title */
/** @var string $sub */
/** @var string $inner the page's own content (already rendered) */
// The account shell of the preview pages: the same sidebar as /account (with Membership and My Reviews, which exist only in the preview) and a back arrow to the phone menu.
$nav = [['house', 'Overview', '/account', ''], ['package', 'My Orders', '/account/orders', 'orders'], ['heart', 'Wishlist', '/wishlist', ''], ['map-pin', 'Addresses', '/account/addresses', 'addresses'],
    ['credit-card', 'Payment Methods', '/account/payments', 'payments'], ['crown', 'Membership', '/account/membership', 'membership'], ['star', 'My Reviews', '/account/reviews', 'reviews'],
    ['bell', 'Notifications', '/account/notifications', 'notifications'], ['headset', 'Help &amp; Support', '/account/help', 'help']];
$pageTitle = $title; // header.php overwrites $title (a menu loop variable)
?><div class="pnvDaq_page pf_dRoot"><?php require dirname(__DIR__) . '/partials/header.php'; ?><main class="pnvDaq_main pf_dAcct">
<a class="pf_acctBack" href="/account"><?= Icons::svg('arrow-left', 20, 2) ?><span>Account</span></a>
<header class="pnvDaq_headingRow"><div class="pnvDaq_heading"><h1><?= View::e($pageTitle) ?></h1><p><?= View::e($sub) ?></p></div><span class="pf_dPill">Preview</span></header>
<div class="pnvDaq_layout"><aside class="pnvDaq_sidebar"><nav aria-label="Account sections"><?php foreach ($nav as [$icon, $label, $href, $key]) { $on = $key === $section; ?><a class="pnvDaq_navItem<?= $on ? ' pnvDaq_navActive' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?> href="<?= $href ?>"><?= Icons::svg($icon, 24, 1.5) ?><span><?= $label ?></span><?= Icons::svg('chevron-right', 15) ?></a><?php } ?></nav>
<button type="button" class="pnvDaq_logout" data-pf="logout"><?= Icons::svg('log-out', 16) ?>Logout</button></aside>
<section class="pnvDaq_content pf_dContent"><?= $inner ?></section></div></main><?php require dirname(__DIR__) . '/partials/footer.php'; ?></div>
