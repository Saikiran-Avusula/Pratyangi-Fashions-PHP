<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var list<array<string,mixed>> $addresses */
/** @var list<array<string,mixed>> $options */
// Figma 6: Delivery Address (radio list, Default badge, Edit / Delete, "+ Add New Address" opens the existing address sheet) and Delivery Options.
$toast = ' data-demo-toast="Preview only: nothing was changed."';
?><section class="pf_dCard">
<header class="pf_dCardHead"><span class="pf_dIcon"><?= Icons::svg('map-pin', 26, 1.4) ?></span><div><h2>Delivery Address</h2><p>Where should we deliver your order?</p></div>
<button type="button" class="pf_dLink pf_dAdd" aria-haspopup="dialog" data-pf-open-address>+ Add New Address</button></header>
<div class="pf_dList" role="radiogroup" aria-label="Delivery address">
<?php foreach ($addresses as $a) { ?><label class="pf_dAddr"><input type="radio" name="addr" value="<?= (int) $a['id'] ?>"<?= $a['isDefault'] ? ' checked' : '' ?>/><span class="pf_dRadio" aria-hidden="true"></span><span class="pf_dAddrBody">
<strong><?= View::e($a['type']) ?><?php if ($a['isDefault']) { ?> <em class="pf_dBadge">DEFAULT</em><?php } ?></strong>
<b><?= View::e($a['name']) ?></b><span><?= View::e($a['line']) ?> +91 <?= View::e($a['phone']) ?></span>
<span class="pf_dActs"><button type="button"<?= $toast ?>><?= Icons::svg('pencil', 14, 2) ?>Edit</button><i aria-hidden="true"></i><button type="button" class="pf_dPlain"<?= $toast ?>>Delete</button></span></span></label>
<?php } ?></div></section>
<section class="pf_dCard">
<header class="pf_dCardHead"><span class="pf_dIcon"><?= Icons::svg('truck', 26, 1.4) ?></span><div><h2>Delivery Options</h2><p>Choose a delivery option that works for you</p></div></header>
<div class="pf_dOpts" role="radiogroup" aria-label="Delivery option">
<?php foreach ($options as $i => $o) { ?><label class="pf_dOpt"><input type="radio" name="delivery" value="<?= View::e($o['id']) ?>"<?= $i === 0 ? ' checked' : '' ?>/><span class="pf_dOptIcon" aria-hidden="true"><?= Icons::svg('truck', 22, 1.4) ?></span>
<span><strong><?= View::e($o['name']) ?><em class="<?= $o['price'] === 'FREE' ? 'pf_dGood' : '' ?>"><?= View::e($o['price']) ?></em></strong><small><?= View::e($o['days']) ?></small><small><?= View::e($o['note']) ?></small></span></label>
<?php } ?></div></section>
