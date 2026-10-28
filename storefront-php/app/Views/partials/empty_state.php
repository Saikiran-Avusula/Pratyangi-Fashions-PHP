<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var string $icon an Icons name: shopping-bag | heart | package | search | map-pin */
/** @var string $title */
/** @var string $line one short line under the title */
/** @var list<array{0:string,1:string,2?:string}> $actions [label, address, extra attributes]; an empty address makes a button */
/** @var string $attrs optional extra attributes of the box (already escaped) */
// THE empty state of the shop (bag, checkout, wishlist, orders, search, addresses): centred in its area, same on a phone and on a desktop.
// public/assets/site.js has the same box for the pages that draw their lists in the browser (window.PratyangiEmptyState).
$actions = $actions ?? [['Start shopping', '/new-arrivals']];
?><div class="pf_emptyState"<?= $attrs ?? '' ?>><?= Icons::svg($icon, 40, 1.5) ?><h2><?= View::e($title) ?></h2><p><?= View::e($line) ?></p><div class="pf_emptyActions"><?php foreach ($actions as $i => $a) { $cls = 'pf_emptyBtn' . ($i > 0 ? ' pf_emptyGhost' : ''); if ($a[1] === '') { ?><button type="button" class="<?= $cls ?>"<?= $a[2] ?? '' ?>><?= View::e($a[0]) ?></button><?php } else { ?><a class="<?= $cls ?>" href="<?= View::e($a[1]) ?>"><?= View::e($a[0]) ?></a><?php } } ?></div></div>
