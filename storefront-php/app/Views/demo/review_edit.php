<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var array<string,mixed> $review */
/** @var bool $isNew */
// Figma 13: the Edit Your Review form. FORM UI ONLY: Update Review shows a demo toast, nothing is saved; photo tiles are not uploads.
$text = (string) ($review['text'] ?? '');
$tags = ['Fabric Quality', 'Colour & Design', 'Comfort', 'Value for Money', 'Delivery Experience'];
?><div class="pnvDaq_page pf_dRoot"><?php require dirname(__DIR__) . '/partials/header.php'; ?><main class="pf_dPage pf_dEdit"><form class="pf_dEditForm" novalidate autocomplete="off">
<header class="pf_dEditHead"><div><h1 class="pf_dDisplay"><?= $isNew ? 'Write Your Review' : 'Edit Your Review' ?></h1><p>Update your experience and help other shoppers.</p><span class="pf_dPill">Preview</span></div><a class="pf_dClose pf_dRound" href="/account/reviews" aria-label="Close"><?= Icons::svg('x', 22, 2) ?></a></header>
<section><h2>Your Rating</h2><div class="pf_dRate" role="radiogroup" aria-label="Your rating"><?php for ($i = 1; $i <= 5; $i++) { ?><button type="button" role="radio" aria-checked="false" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>" data-star="<?= $i ?>"><?= Icons::svg('star', 40, 1.2) ?></button><?php } ?><span data-pf="rate-text">0 out of 5</span></div></section>
<section><h2>Your Review</h2><p class="pf_dMuted">Tell us about your experience. What did you like? How was the quality, fabric, colour and overall experience?</p>
<div class="pf_dTextWrap"><textarea name="text" rows="4" maxlength="500" aria-label="Your review" data-pf="rv-text"><?= View::e($isNew ? '' : $text) ?></textarea><small data-pf="rv-count"><?= $isNew ? 0 : strlen($text) ?>/500</small></div>
<div class="pf_dTags"><?php foreach ($tags as $t) { ?><button type="button" aria-pressed="false">+ <?= View::e($t) ?></button><?php } ?></div></section>
<section><h2>Add Photos <small>(Optional)</small></h2><p class="pf_dMuted">Upload photos of your saree to help other customers.</p>
<div class="pf_dPhotos"><?php for ($i = 0; $i < ($isNew ? 0 : min(3, (int) ($review['photos'] ?? 0))); $i++) { ?><span class="pf_dPhoto"><img src="<?= View::e($review['image']) ?>" alt="" width="112" height="119"/><button type="button" aria-label="Remove photo" data-demo-toast="Preview only: nothing was removed."><?= Icons::svg('x', 16, 2) ?></button></span><?php } ?>
<button type="button" class="pf_dAddPhoto" data-demo-toast="Preview only: photos are not uploaded."><?= Icons::svg('camera', 22, 1.6) ?><b>Add More photos</b><small>JPG, PNG (Max 5MB)</small></button></div></section>
<footer class="pf_dEditFoot"><a class="pf_dBtn pf_dGhost" href="/account/reviews"><span>Cancel</span></a><button type="button" class="pf_dBtn" data-demo-toast="Preview only: nothing was saved."><?= Icons::svg('pencil', 16, 1.8) ?><span><?= $isNew ? 'Submit Review' : 'Update Review' ?></span></button></footer>
</form></main><?php require dirname(__DIR__) . '/partials/footer.php'; ?></div>
