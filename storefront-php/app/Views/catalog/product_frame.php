<?php
declare(strict_types=1);

use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
/** @var string $inner already-escaped page content (the product, or a message box) */
$account = $account ?? null;
// ProductFrame of the web: header, a "Back to New Arrivals" link, the content, footer.
?><main class="udK_nG_page"><?php require dirname(__DIR__) . '/partials/header.php'; ?><div class="udK_nG_wrap"><nav class="udK_nG_back" aria-label="Breadcrumb"><a href="/new-arrivals">← Back to New Arrivals</a></nav><?= $inner ?></div><?php require dirname(__DIR__) . '/partials/footer.php'; ?></main>
