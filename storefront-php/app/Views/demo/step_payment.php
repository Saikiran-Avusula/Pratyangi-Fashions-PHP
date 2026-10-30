<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var list<array<string,mixed>> $options */
// Figma 7: the payment options as radio rows with the brand chips. Cash on delivery is "Coming soon" until DemoData::codEnabled() (a later setting).
?><section class="pf_dCard">
<header class="pf_dCardHead pf_dRule"><span class="pf_dIcon"><?= Icons::svg('credit-card', 26, 1.4) ?></span><div><h2>Payment Method</h2><p>Choose a secure payment option</p></div></header>
<div class="pf_dPays" role="radiogroup" aria-label="Payment method">
<?php foreach ($options as $o) { ?><label class="pf_dPay<?= $o['enabled'] ? '' : ' is-off' ?>"><input type="radio" name="pay" value="<?= View::e($o['id']) ?>"<?= $o['enabled'] ? '' : ' disabled' ?>/><span class="pf_dRadio" aria-hidden="true"></span>
<span class="pf_dPayText"><strong><?= View::e($o['title']) ?><?php if (!$o['enabled']) { ?> <em class="pf_dBadge pf_dSoon">Coming soon</em><?php } ?></strong><?php if ($o['sub'] !== '') { ?><small><?= View::e($o['sub']) ?></small><?php } ?></span>
<?php if ($o['chips']) { ?><span class="pf_dChips" aria-hidden="true"><?php foreach ($o['chips'] as $c) { ?><i><?= View::e($c) ?></i><?php } ?></span><?php } ?></label>
<?php } ?></div></section>
