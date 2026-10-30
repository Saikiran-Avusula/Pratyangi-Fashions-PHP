<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\ShopInfo;

/** @var string $navActive the key of the open page: overview | orders | wishlist | addresses | payments | membership | reviews | notifications | help */
// The account sidebar of the pages that sit beside it (the wishlist). Same classes as /account.
$navRows = [['house', 'Overview', '/account', 'overview'], ['package', 'My Orders', '/account/orders', 'orders'], ['heart', 'Wishlist', '/wishlist', 'wishlist'], ['map-pin', 'Addresses', '/account/addresses', 'addresses'],
    ['credit-card', 'Payment Methods', '/account/payments', 'payments']];
if (ShopInfo::demoUi()) {
    $navRows[] = ['crown', 'Membership', '/account/membership', 'membership'];
    $navRows[] = ['star', 'My Reviews', '/account/reviews', 'reviews'];
}
$navRows[] = ['bell', 'Notifications', '/account/notifications', 'notifications'];
$navRows[] = ['headset', 'Help &amp; Support', '/account/help', 'help'];
?><aside class="pnvDaq_sidebar"><nav aria-label="Account sections"><?php foreach ($navRows as [$navIcon, $navLabel, $navHref, $navKey]) { $on = $navKey === $navActive; ?><a class="pnvDaq_navItem<?= $on ? ' pnvDaq_navActive' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?> href="<?= $navHref ?>"><?= Icons::svg($navIcon, 24, 1.5) ?><span><?= $navLabel ?></span><?= Icons::svg('chevron-right', 15) ?></a><?php } ?></nav><button type="button" class="pnvDaq_logout" data-pf="logout"><?= Icons::svg('log-out', 16) ?>Logout</button></aside>