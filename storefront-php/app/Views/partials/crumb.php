<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;

/** @var string $crumbLabel the open page: Wishlist, My Orders, ... ("" on the overview) */
// Home > My Account > page, from tablet width up; a phone gets the back arrow (.pf_acctBack) instead.
?><nav class="pf_crumb" aria-label="Breadcrumb"><a href="/">Home</a><?= Icons::svg('chevron-right', 14) ?><?php if ($crumbLabel === '') { ?><span aria-current="page">My Account</span><?php } else { ?><a href="/account">My Account</a><?= Icons::svg('chevron-right', 14) ?><span aria-current="page"><?= $crumbLabel ?></span><?php } ?></nav>
