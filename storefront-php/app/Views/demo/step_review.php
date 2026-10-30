<?php
declare(strict_types=1);

use Pratyangi\Demo\DemoData as D;
use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var array<string,mixed> $address */
/** @var list<array<string,mixed>> $lines */
/** @var array<string,int|string> $sum */
/** @var array<string,mixed> $chosen */
// Figma 8: Delivery Address, Items in Your Order (a table from 768px, stacked cards on a phone), Payment Method, Offers & Benefits, Important Information.
?><section class="pf_dCard">
<header class="pf_dCardHead pf_dRule"><span class="pf_dIcon"><?= Icons::svg('map-pin', 24, 1.4) ?></span><div><h2>Delivery Address</h2></div></header>
<div class="pf_dRevAddr"><div><strong><?= Icons::svg('map-pin', 16, 1.6) ?><?= View::e($address['name']) ?> <em class="pf_dBadge pf_dTag"><?= View::e($address['type']) ?></em></strong><p><?= View::e($address['line']) ?></p><p>+91 <?= View::e($address['phone']) ?></p></div>
<a class="pf_dOutline" href="/checkout/address"><?= Icons::svg('pencil', 14, 2) ?>Edit Address</a></div></section>
<section class="pf_dCard">
<header class="pf_dCardHead"><span class="pf_dIcon"><?= Icons::svg('shopping-bag', 24, 1.4) ?></span><div><h2>Items in Your Order <small>(<?= (int) $sum['items'] ?> items)</small></h2></div></header>
<table class="pf_dTable"><thead><tr><th scope="col">Product</th><th scope="col">Price</th><th scope="col">Quantity</th><th scope="col">Discounted Price</th></tr></thead><tbody>
<?php foreach ($lines as $l) { ?><tr><td class="pf_dProd"><img src="<?= View::e($l['image']) ?>" alt="" width="84" height="98" loading="lazy"/><div><strong><?= View::e($l['name']) ?></strong><small><?= View::e($l['variant']) ?></small></div></td>
<td data-label="Price"><?= D::inr($l['mrpPaise']) ?></td><td data-label="Quantity"><span class="pf_dQtyBox"><span aria-hidden="true">-</span><span><?= (int) $l['qty'] ?> <i class="pf_dSr">units</i></span><span aria-hidden="true">+</span></span></td><td data-label="Discounted Price" class="pf_dGood"><?= D::inr($l['pricePaise']) ?></td></tr>
<?php } ?></tbody></table></section>
<section class="pf_dCard">
<header class="pf_dCardHead"><span class="pf_dIcon"><?= Icons::svg('credit-card', 24, 1.4) ?></span><div><h2>Payment Method</h2></div></header>
<div class="pf_dRevPay"><span class="pf_dPayMark" aria-hidden="true"><?= View::e(strtoupper(substr((string) $chosen['id'], 0, 4))) ?></span><div><strong><?= View::e(['upi' => 'UPI', 'card' => 'Credit / Debit Card', 'cod' => 'Cash on Delivery'][$chosen['id']] ?? '') ?></strong><small><?= View::e($chosen['sub']) ?></small></div><a class="pf_dOutline" href="/checkout/payment"><?= Icons::svg('pencil', 14, 2) ?>Edit Payment</a></div></section>
<section class="pf_dCard">
<header class="pf_dCardHead"><span class="pf_dIcon"><?= Icons::svg('tag', 24, 1.4) ?></span><div><h2>Offers &amp; Benefits</h2></div><a class="pf_dLink pf_dPlain" href="/bag">Edit</a></header>
<div class="pf_dOffer pf_dOfferOk"><?= Icons::svg('circle-check', 30, 1.6) ?><div><strong>Coupon applied: <?= D::COUPON_CODE ?></strong><small><?= D::COUPON_PERCENT ?>% off on your first order</small></div><b>You save <?= D::inr($sum['discountPaise']) ?></b><button type="button" class="pf_dIconBtn" disabled aria-label="Remove coupon"><?= Icons::svg('trash-2', 16, 1.8) ?></button></div>
<div class="pf_dOffer pf_dOfferGift"><?= Icons::svg('gift', 30, 1.4) ?><div><strong>Your order qualifies for FREE Shipping</strong><small>Yay! No delivery charges on this order.</small></div></div>
<h3 class="pf_dInfoTitle"><?= Icons::svg('info', 16, 1.8) ?>Important Information</h3>
<ul class="pf_dInfo"><li>By placing this order, you agree to our Terms &amp; Conditions and Privacy Policy.</li><li>Product colour may slightly vary due to photographic lighting or screen settings.</li><li>You can track your order from the ‘Track Order’ section after successful payment.</li><li>Easy returns within 7 days of delivery.</li></ul></section>
