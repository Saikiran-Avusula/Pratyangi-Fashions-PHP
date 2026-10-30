<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var list<array<string,mixed>> $reviews */
/** @var list<array<string,mixed>> $pending */
// Figma 12: Reviewed (n) / Pending Reviews (n) tabs and the review cards. Made-up reviews (DemoData::reviews()); Delete only shows a toast.
$stars = static fn (float $r): string => '<span class="pf_dStars" role="img" aria-label="' . $r . ' out of 5" style="--r:' . ($r * 20) . '%"></span>';
?><div class="pf_dTabs" role="tablist"><button type="button" role="tab" aria-selected="true" aria-controls="pf-rv-done" id="pf-tab-done">Reviewed (<?= count($reviews) ?>)</button><button type="button" role="tab" aria-selected="false" aria-controls="pf-rv-todo" id="pf-tab-todo" tabindex="-1">Pending Reviews (<?= count($pending) ?>)</button></div>
<div id="pf-rv-done" role="tabpanel" aria-labelledby="pf-tab-done"><?php foreach ($reviews as $r) { ?><article class="pf_dReview"><div class="pf_dRvImg"><img src="<?= View::e($r['image']) ?>" alt="" width="184" height="195" loading="lazy"/><?php if ($r['photos'] > 0) { ?><span><?= (int) $r['photos'] ?>+ Images</span><?php } ?></div>
<div class="pf_dRvBody"><header><div><h3><?= View::e($r['name']) ?></h3><p><?= View::e($r['variant']) ?></p></div><small>Reviewed on <?= View::e($r['date']) ?></small></header>
<p class="pf_dRating"><?= $stars((float) $r['rating']) ?><span><?= View::e((string) $r['rating']) ?></span></p><p class="pf_dRvText"><?= View::e($r['text']) ?></p>
<div class="pf_dRvActs"><a class="pf_dOutline pf_dGoldLine" href="/account/reviews/edit?id=<?= (int) $r['id'] ?>"><?= Icons::svg('pencil', 14, 2) ?>Edit Review</a><button type="button" class="pf_dOutline" data-demo-toast="Preview only: nothing was deleted."><?= Icons::svg('trash-2', 14, 2) ?>Delete</button></div></div></article>
<?php } ?></div>
<div id="pf-rv-todo" role="tabpanel" aria-labelledby="pf-tab-todo" hidden><?php foreach ($pending as $r) { ?><article class="pf_dReview"><div class="pf_dRvImg"><img src="<?= View::e($r['image']) ?>" alt="" width="184" height="195" loading="lazy"/></div>
<div class="pf_dRvBody"><header><div><h3><?= View::e($r['name']) ?></h3><p><?= View::e($r['variant']) ?></p></div><small><?= View::e($r['date']) ?></small></header><p class="pf_dRvText">How did you like it? Tell other shoppers about the fabric, colour and fit.</p>
<div class="pf_dRvActs"><a class="pf_dOutline pf_dGoldLine" href="/account/reviews/edit?id=<?= (int) $r['id'] ?>"><?= Icons::svg('pencil', 14, 2) ?>Write Review</a></div></div></article><?php } ?></div>
