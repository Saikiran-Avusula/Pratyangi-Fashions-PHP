<?php
declare(strict_types=1);

use Pratyangi\Views\CatalogHtml;
use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
/** @var string $checkoutHref "/checkout" when signed in, "/login?next=/checkout" when signed out (the web's link rule) */
/** @var list<array<string,mixed>>|null $recommended up to four in-stock products, or null when the catalogue cannot be read */
$account = $account ?? null;
$img = static fn (string $file, int $size): string => '<img alt="" loading="lazy" width="' . $size . '" height="' . $size . '" decoding="async" data-nimg="1" style="color:transparent" src="/assets/images/figma/' . $file . '"/>';
// BagView of the web (the real-store variant): the lines, the totals and the notices are drawn by public/assets/bag-page.js from the saved bag and the catalogue.
?><main class="BpPmcW_page" data-pf-bag-page="bag" data-pf-image-base="<?= View::e(\Pratyangi\Catalog\ProductImage::base()) ?>" data-checkout-href="<?= View::e($checkoutHref) ?>"><?php require dirname(__DIR__) . '/partials/header.php'; ?><section class="BpPmcW_bag" aria-labelledby="bag-title"><div class="BpPmcW_intro"><div class="BpPmcW_titleRow"><h1 id="bag-title">Your Bag <span data-pf="title-count">(0 items)</span></h1><a class="BpPmcW_continue" href="/new-arrivals"><?= $img('arrow-left.svg', 20) ?>Continue Shopping</a></div><p data-pf="bag-lead"></p></div><div data-pf="bag-notices"></div><div class="BpPmcW_layout"><div class="BpPmcW_items" data-pf="bag-items"></div><div class="BpPmcW_panels"><div class="BpPmcW_summary" style="display:none"><h2>Order Summary</h2><dl class="BpPmcW_breakdown"><div><dt data-pf="subtotal-label">Subtotal (0 items)</dt><dd data-pf="subtotal">Rs. 0.00</dd></div><div data-pf="tax-row" hidden><dt>Tax (added on top)</dt><dd data-pf="tax">Rs. 0.00</dd></div><div><dt>Shipping</dt><dd>Confirmed by the shop</dd></div></dl><hr/><div class="BpPmcW_total"><span data-pf="total-label">Total Amount</span><strong data-pf="total">Rs. 0.00</strong></div><p class="BpPmcW_status">Delivery charges are not included.</p><div data-pf="total-note"></div><button type="button" class="BpPmcW_checkout" data-pf="checkout-slot" disabled="">Proceed to Checkout<?= $img('checkout-arrow.svg', 27) ?></button><div class="BpPmcW_assurance"><p class="BpPmcW_secure"><?= $img('lock-keyhole.svg', 18) ?>Checkout</p><p>Online payment is not available yet &nbsp; | &nbsp; No payment is taken</p></div></div><p class="BpPmcW_status">Coupons are not available yet.</p></div></div></section><section class="BpPmcW_recommended" aria-labelledby="recommended-title"><div class="BpPmcW_recommendedIntro"><h2 id="recommended-title">You May Also Like</h2><p>Complete your look with these beautiful sarees</p></div><?php
if ($recommended === null) {
    echo CatalogHtml::note(CatalogHtml::UNAVAILABLE, true, true);
} else {
    echo $recommended === [] ? '<div class="BpPmcW_recommendedGrid"></div>' : View::render('partials/products', ['products' => $recommended, 'catalogError' => false, 'gridClass' => 'BpPmcW_recommendedGrid']);
}
?></section><?php require dirname(__DIR__) . '/partials/why.php'; require dirname(__DIR__) . '/partials/footer.php'; ?></main>
