<?php
declare(strict_types=1);

use Pratyangi\Demo\DemoData as D;
use Pratyangi\Views\Icons;
use Pratyangi\Views\View;

/** @var array<string,int|string> $sum */
// "Place Order" in the preview lands here. No order exists: nothing was saved, paid, sent to the ERP or e-mailed.
?><div class="pnvDaq_page pf_dRoot"><?php require dirname(__DIR__) . '/partials/header.php'; ?><main class="pf_dPage"><section class="pf_dCard pf_dDone2" role="status">
<?= Icons::svg('circle-check', 48, 1.4) ?><h1>Order preview</h1><span class="pf_dPill">Preview</span>
<p>This is a demo confirmation. <strong>No order was placed</strong>, no payment was taken and nothing was sent to the shop, the ERP or your e-mail.</p>
<p class="pf_dMuted">Preview total: <?= D::inr($sum["totalPaise"]) ?> (subtotal, discount, GST and shipping as on the review page)</p>
<a class="pf_dBtn" href="/new-arrivals"><span>Continue shopping</span></a></section></main><?php require dirname(__DIR__) . '/partials/footer.php'; ?></div>
