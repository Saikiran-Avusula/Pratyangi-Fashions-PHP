<?php
declare(strict_types=1);

use Pratyangi\Catalog\ProductImage;
use Pratyangi\Views\ShopInfo;
use Pratyangi\Views\View;
use Pratyangi\Web\CatalogLinks;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor, or null (signed out) */
$account = $account ?? null;

// The Shop menu (same groups and links as the web's mega menu: every link opens New Arrivals, some with a fabric or occasion ticked).
$menuGroups = [
    ['WOMEN', [
        ['Half Sarees', CatalogLinks::catalogLink()],
        ['Uppada', CatalogLinks::catalogLink('Uppada Pattu Saree')],
        ['Banarasi', CatalogLinks::catalogLink('Banarasi Saree')],
        ['Kanchipuram Pattu', CatalogLinks::catalogLink('Kanchi Pattu Saree')],
        ['Dharmavaram', CatalogLinks::catalogLink('Dharmavaram Saree')],
        ['Soft Silk', CatalogLinks::catalogLink('Bangalore Silk Saree')],
        ['Bridal Sarees', CatalogLinks::catalogLink(null, 'Wedding')],
        ['Gadwal', CatalogLinks::catalogLink()],
    ]],
    ['MEN', [['Men’s Ethnic Wear', CatalogLinks::catalogLink()]]],
    ['JEWELLERY', [['Jewellery', CatalogLinks::catalogLink()], ['Accessories', CatalogLinks::catalogLink()]]],
];
if ($collectionLinks = CatalogLinks::collections()) {
    $menuGroups[] = ['COLLECTIONS', array_map(static fn (string $slug, string $name): array => [$name, '/collection/' . $slug], array_keys($collectionLinks), $collectionLinks)];
}
// Search entries: label, link, extra words that also match (same list as the web's search box).
$searchEntries = [
    ['New Arrivals', CatalogLinks::catalogLink(), []],
    ['Uppada Pattu Saree', CatalogLinks::catalogLink('Uppada Pattu Saree'), ['uppada']],
    ['Pattu Saree', CatalogLinks::catalogLink('Pattu Saree'), ['pattu', 'pattu silk']],
    ['Pure Silk Saree', CatalogLinks::catalogLink('Pure Silk Saree'), ['pure silk', 'silk']],
    ['Kanchi Pattu Saree', CatalogLinks::catalogLink('Kanchi Pattu Saree'), ['kanchipuram', 'kanchipuram pattu', 'kanchipuram patti', 'kanchi']],
    ['Bangalore Silk Saree', CatalogLinks::catalogLink('Bangalore Silk Saree'), ['bangalore', 'soft silk']],
    ['Banarasi Saree', CatalogLinks::catalogLink('Banarasi Saree'), ['banarasi', 'benarasi']],
    ['Dharmavaram Saree', CatalogLinks::catalogLink('Dharmavaram Saree'), ['dharmavaram']],
    ['Bridal Sarees', CatalogLinks::catalogLink(null, 'Wedding'), ['bridal', 'wedding']],
    ['Wedding Collection', CatalogLinks::catalogLink(null, 'Wedding'), []],
    ['Festive Collections', CatalogLinks::catalogLink(null, 'Festive'), ['festival']],
    ['Best Sellers', '/#arrivals', []],
];
$chevron = static fn (int $size): string => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>';
$searchIcon = static fn (int $size): string => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search" aria-hidden="true"><path d="m21 21-4.34-4.34"></path><circle cx="11" cy="11" r="8"></circle></svg>';
?><div class="pf_topbar"><p><?= ShopInfo::freeShippingText() !== '' ? View::e(ShopInfo::freeShippingText()) . ' &nbsp;|&nbsp; ' : '' ?>Authentic Handlooms</p><p class="pf_topbarLinks"><a href="/contact">Contact</a></p></div><div class="pSCCwa_menuBackdrop" data-pf="menu-backdrop" aria-hidden="true" hidden></div><header class="pSCCwa_header" data-pf="header"><div class="pSCCwa_headerInner"><button class="pSCCwa_shopToggle" type="button" aria-label="Shop menu" aria-expanded="false" aria-controls="shop-menu" data-pf="shop-toggle"><span class="pSCCwa_menuIcon" data-open="false" aria-hidden="true"><span></span><span></span><span></span></span><span>Shop</span><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down pSCCwa_shopChevron" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg></button><a class="pSCCwa_brand" aria-label="Pratyangi Fashions home" href="/"><span class="pSCCwa_brandLogo"><img alt="" width="58" height="58" decoding="async" style="color:transparent" src="<?= View::e(ShopInfo::logoPath()) ?>"/></span><span class="pSCCwa_brandText"><span class="pSCCwa_brandName">PRATYANGI FASHIONS</span><span class="pSCCwa_brandTagline">TRADITION MEETS ELEGANCE</span></span></a><div class="pSCCwa_headerActions"><button class="pSCCwa_searchButton" type="button" aria-label="Search catalog" data-pf="search-open"><span>Search</span><?= $searchIcon(17) ?></button><a class="pSCCwa_iconButton" aria-label="Open your wishlist, 0 items" href="/wishlist" data-pf="wish-link"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg><span class="pSCCwa_count" data-pf="wish-count">0</span></a><a class="pSCCwa_iconButton" aria-label="Open your bag, 0 items" href="/bag" data-pf="bag-link"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path></svg><span class="pSCCwa_count" data-pf="bag-count">0</span></a><?php if ($account !== null) { ?><a class="pSCCwa_accountLink" href="/account" aria-label="<?= View::e($account->full !== '' ? 'My account, signed in as ' . $account->full : 'My account') ?>" title="<?= View::e($account->email) ?>"><span class="pSCCwa_avatar" aria-hidden="true"><?php if ($account->initial !== '') { echo View::e($account->initial); } else { ?><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-round" aria-hidden="true"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg><?php } ?></span><span class="pSCCwa_accountText"><?php if ($account->first !== '') { ?><span class="pSCCwa_accountHello">Hello,</span><span class="pSCCwa_accountName"><?= View::e($account->first) ?></span><?php } else { ?><span class="pSCCwa_accountHello">Hello</span><?php } ?></span></a><?php } else { ?><a class="pSCCwa_iconButton" aria-label="Sign in" href="/login"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-round" aria-hidden="true"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg></a><?php } ?></div></div><nav id="shop-menu" class="pSCCwa_menuPanel" aria-label="Shop sections" data-pf="menu-panel" hidden><?php foreach ($menuGroups as [$title, $links]) { ?><div class="pSCCwa_menuGroup"><h2><?= View::e($title) ?></h2><ul><?php foreach ($links as [$label, $linkHref]) { ?><li><a href="<?= View::e($linkHref) ?>"><?= View::e($label) ?></a></li><?php } ?></ul></div><?php } ?><div class="pSCCwa_menuGroup pf_menuAccount"><h2>ACCOUNT</h2><ul><?php if ($account !== null) { ?><li><a href="/account">My account</a></li><li><a href="/account/orders">My orders</a></li><li><a href="/wishlist">Wishlist</a></li><li><button type="button" data-pf="logout">Sign out</button></li><?php } else { ?><li><a href="/login">Sign in</a></li><li><a href="/signup">Create account</a></li><?php } ?></ul></div></nav></header><div class="pSCCwa_scrim" data-pf="search-scrim" hidden><section class="pSCCwa_dialog" role="dialog" aria-modal="true" aria-label="Search catalog" data-pf="search-dialog" data-pf-img="<?= View::e(str_ends_with($imgBase = ProductImage::url("/"), "/") ? $imgBase : "") ?>"><button type="button" class="pSCCwa_closeButton" aria-label="Close panel" data-pf="search-close"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg></button><h2>Search</h2><label class="pSCCwa_srOnly" for="catalog-search">Search the catalog</label><div class="pSCCwa_searchField"><?= $searchIcon(18) ?><input id="catalog-search" placeholder="Search the catalog" autocomplete="off"/></div><div class="pSCCwa_searchResults" data-pf="search-results"><?php foreach ($searchEntries as [$label, $href, $words]) { ?><a href="<?= View::e($href) ?>" data-pf-text="<?= View::e(strtolower(implode('|', array_merge([$label], $words)))) ?>"><?= View::e($label) ?><?= $chevron(15) ?></a><?php } ?><div data-pf="search-products" hidden></div><p data-pf="search-empty" hidden>No matching items.</p></div></section></div>
