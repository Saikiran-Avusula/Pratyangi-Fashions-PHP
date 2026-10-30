<?php
declare(strict_types=1);

use Pratyangi\Demo\DemoData as D;
use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var int $step 1 | 2 | 3 */
/** @var string $title */
/** @var string $sub */
/** @var array{0:string,1:string,2:string} $cta [label, href, extra attributes] */
/** @var string $main the step's own cards (already rendered) */
/** @var list<array<string,mixed>> $lines */
/** @var array<string,int|string> $sum */
/** @var string $pay */
// Figma 6-8: the checkout shell (heading with the 1-2-3 bar, the step's cards, the order summary, a sticky bar on phones). Made-up data only (DEMO_UI).
$steps = [1 => 'Delivery', 2 => 'Payment', 3 => 'Review & Place Order'];
$ctaHtml = static fn (string $class): string => '<a class="pf_dBtn ' . $class . '" href="' . View::e($cta[1]) . '"' . $cta[2] . '>' . Icons::svg('lock-keyhole', 18, 1.8) . '<span>' . View::e($cta[0]) . '</span>' . Icons::svg('arrow-right', 18, 2) . '</a>';
$review = $step === 3;
$pageTitle = $title; // header.php overwrites $title (a menu loop variable)
?><div class="pnvDaq_page pf_dRoot"><?php require dirname(__DIR__) . '/partials/header.php'; ?><main class="pf_dPage">
<header class="pf_dHead"><div><h1><?= View::e($pageTitle) ?></h1><p><?= View::e($sub) ?></p><span class="pf_dPill">Preview</span></div>
<ol class="pf_dSteps" aria-label="Checkout steps"><?php foreach ($steps as $n => $label) { ?><li class="<?= $n < $step ? 'pf_dDone' : ($n === $step ? 'pf_dOn' : '') ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>><b><?= $n ?></b><span><?= View::e($label) ?></span></li><?php } ?></ol></header>
<div class="pf_dGrid"><div class="pf_dMain"><?= $main ?>
<?php if ($step === 2) { ?><div class="pf_dBelow"><?= $ctaHtml('pf_dBtnWide') ?></div><?php } ?></div>
<aside class="pf_dCard pf_dSummary" aria-label="Order summary">
<header class="pf_dSumHead"><h2>Order Summary</h2><?php if ($review) { ?><span class="pf_dMuted">(<?= (int) $sum['items'] ?> items)</span><a class="pf_dLink" href="/bag">Edit Cart</a><?php } else { ?><p class="pf_dMuted"><?= (int) $sum['items'] ?> items in your bag</p><?php } ?></header>
<?php if (!$review) { ?><ul class="pf_dSumItems"><?php foreach ($lines as $l) { ?><li><img src="<?= View::e($l['image']) ?>" alt="" width="64" height="64" loading="lazy"/><div><strong><?= View::e($l['name']) ?></strong><small><?= View::e($l['variant']) ?></small><b><?= D::inr($l['pricePaise']) ?></b></div><span class="pf_dQty">Qty: <?= (int) $l['qty'] ?></span></li><?php } ?></ul><?php } ?>
<details class="pf_dCoupon"<?= $review ? '' : ' open' ?>><summary><?= Icons::svg('tag', 18, 1.6) ?><span>Have a Coupon Code?</span><?= Icons::svg('chevron-down', 16, 2) ?></summary><div class="pf_dCouponRow"><input type="text" placeholder="Enter coupon code" value="" disabled aria-label="Coupon code"/><button type="button" disabled title="Preview only">Apply</button></div></details>
<h3 class="pf_dSumTitle">Price Details</h3>
<dl class="pf_dRows">
<div><dt>Subtotal (<?= (int) $sum['items'] ?> items)</dt><dd><?= D::inr($sum['subtotalPaise']) ?></dd></div>
<div><dt>Discount (<?= D::COUPON_CODE ?>)</dt><dd class="pf_dGood"><?= D::inr(-$sum['discountPaise']) ?></dd></div>
<div><dt>GST (<?= View::e($sum['gstRate']) ?>%)</dt><dd><?= D::inr($sum['gstPaise']) ?></dd></div>
<div><dt>Shipping Charges</dt><dd class="pf_dGood"><?= $sum['shippingPaise'] === 0 ? 'FREE' : D::inr($sum['shippingPaise']) ?></dd></div>
</dl>
<div class="pf_dTotal"><span>Total Amount</span><b><?= D::inr($sum['totalPaise']) ?></b></div>
<p class="pf_dSave"><?= Icons::svg('circle-check', 20, 1.6) ?>You are saving <?= D::inr($sum['savingsPaise']) ?> on this order!</p>
<?= $ctaHtml('pf_dBtnWide pf_dSumCta') ?>
<p class="pf_dTrust"><span><?= Icons::svg('shield-check', 14, 1.8) ?>100% Secure Payments</span><span>Easy Returns within 7 days</span></p>
<?php if ($review) { ?><ul class="pf_dPerks"><li><?= Icons::svg('truck', 26, 1.4) ?><b>Free Shipping</b><small>on orders above ₹1,999</small></li><li><?= Icons::svg('package', 26, 1.4) ?><b>Easy Returns</b><small>within 7 days</small></li><li><?= Icons::svg('shield-check', 26, 1.4) ?><b>100% Secure</b><small>Payments</small></li><li><?= Icons::svg('star', 26, 1.4) ?><b>Authentic</b><small>Handlooms</small></li></ul><?php } ?>
</aside></div>
<div class="pf_dSticky"><div><small>Total</small><b><?= D::inr($sum['totalPaise']) ?></b></div><?= $ctaHtml('') ?></div>
</main><?= $step === 1 ? View::render('partials/address_dialog', ['smsEnabled' => $smsEnabled ?? false]) : '' ?><?php require dirname(__DIR__) . '/partials/footer.php'; ?></div>
