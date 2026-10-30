<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var array<string,mixed> $m */
// Figma 11: Choose Your Membership (Silver / Platinum / Gold), Compare Membership Benefits, Why Join, FAQ. The tiers are made up (DemoData::membership()).
?><section class="pf_dCard pf_dPlain">
<header class="pf_dTiersHead"><div><h2 class="pf_dDisplay">Choose Your Membership</h2><p>Discover a world of exclusive benefits tailored for every saree lover.</p></div>
<div class="pf_dToggle" role="group" aria-label="Billing period"><button type="button" aria-pressed="false">Monthly</button><button type="button" aria-pressed="true">Yearly</button></div></header>
<div class="pf_dTiers"><?php foreach ($m['tiers'] as $t) { ?><article class="pf_dTier pf_dTier_<?= View::e($t['id']) ?>"><span class="pf_dTierIcon" aria-hidden="true"><?= Icons::svg($t['id'] === 'silver' ? 'check' : ($t['id'] === 'platinum' ? 'lock-keyhole' : 'x'), 20, 1.6) ?></span>
<h3><?= View::e($t['name']) ?></h3><span class="pf_dBadge pf_dTierBadge"><?= View::e($t['badge']) ?></span><p><?= View::e($t['text']) ?></p>
<button type="button" class="pf_dTierBtn" data-demo-toast="Preview only: memberships are not on sale yet."><?= View::e($t['cta']) ?><?= Icons::svg('arrow-right', 14, 2) ?></button></article><?php } ?></div></section>
<section class="pf_dCard pf_dCompare"><header><h2 class="pf_dDisplay">Compare Membership Benefits</h2><p>Find the perfect plan for your saree journey.</p></header>
<div class="pf_dTableWrap"><table class="pf_dBenefits"><thead><tr><th scope="col">Benefits</th><th scope="col" class="pf_dCol_silver">Silver</th><th scope="col" class="pf_dCol_platinum">Platinum</th><th scope="col" class="pf_dCol_gold">Gold</th></tr></thead><tbody>
<?php foreach ($m['benefits'] as $row) { ?><tr><th scope="row"><?= View::e($row[0]) ?></th><?php foreach (array_slice($row, 1) as $i => $cell) { ?><td data-label="<?= ['Silver', 'Platinum', 'Gold'][$i] ?>"<?= $cell === '✓' ? ' class="pf_dGood"' : '' ?>><?= View::e($cell) ?></td><?php } ?></tr>
<?php } ?></tbody></table></div></section>
<section class="pf_dWhy"><h2 class="pf_dDisplay">Why Join Our Membership?</h2><ul><?php foreach ($m['why'] as [$h, $p]) { ?><li><span aria-hidden="true"><?= Icons::svg('gift', 20, 1.4) ?></span><div><strong><?= View::e($h) ?></strong><small><?= View::e($p) ?></small></div></li><?php } ?></ul></section>
<section class="pf_dCard pf_dFaq"><h2 class="pf_dDisplay"><?= Icons::svg('info', 20, 1.8) ?>Frequently Asked Questions</h2><?php foreach ($m['faq'] as [$q, $a]) { ?><details><summary><?= View::e($q) ?><?= Icons::svg('chevron-down', 16, 2) ?></summary><p><?= View::e($a) ?></p></details><?php } ?></section>
