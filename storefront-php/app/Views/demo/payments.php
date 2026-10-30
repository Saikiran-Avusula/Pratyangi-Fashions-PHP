<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var list<array<string,mixed>> $options */
/** @var array{upi:list<array<string,string>>,cards:list<array<string,string>>} $saved */
// Figma 9: the payment options (as in step 2) plus the saved UPI IDs and cards. Cards are shown MASKED only (brand and last 4); the shop never holds a card number.
$toast = ' data-demo-toast="Preview only: nothing was removed."';
?><?= View::render('demo/step_payment', ['options' => $options]) ?>
<div class="pf_dBelow pf_dBelowEnd"><a class="pf_dBtn pf_dBtnWide" href="/checkout/review" aria-disabled="true" data-pf="demo-continue"><?= Icons::svg('lock-keyhole', 18, 1.8) ?><span>Use this payment method</span><?= Icons::svg('arrow-right', 18, 2) ?></a></div>
<section class="pf_dCard"><header class="pf_dCardHead pf_dRule"><span class="pf_dIcon"><?= Icons::svg('credit-card', 26, 1.4) ?></span><div><h2>Saved payment methods</h2><p>Only the last digits are shown</p></div>
<button type="button" class="pf_dLink pf_dAdd" aria-haspopup="dialog" data-demo-open="card">+ Add card</button></header>
<h3 class="pf_dSubTitle">UPI IDs</h3><ul class="pf_dSaved"><?php foreach ($saved['upi'] as $u) { ?><li><span class="pf_dPayMark" aria-hidden="true">UPI</span><strong><?= View::e($u['label']) ?></strong><button type="button" class="pf_dPlain"<?= $toast ?>>Remove</button></li><?php } ?></ul>
<h3 class="pf_dSubTitle">Cards</h3><ul class="pf_dSaved"><?php foreach ($saved['cards'] as $c) { ?><li><span class="pf_dPayMark" aria-hidden="true"><?= Icons::svg('credit-card', 18, 1.6) ?></span><span><strong><?= View::e($c['label']) ?></strong><small><?= View::e($c['nick']) ?> · Expires <?= View::e($c['expiry']) ?></small></span><button type="button" class="pf_dPlain"<?= $toast ?>>Remove</button></li><?php } ?></ul>
</section>
