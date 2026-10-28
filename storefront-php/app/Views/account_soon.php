<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;

/** @var bool $signedIn */
// The account pages are not built yet. Where the web has its "Logout" button (AccountShell sidebar) a signed-in visitor gets the same button.
?><main class="YnyroG_page"><div class="GSLLNW_note" style="margin:96px auto;max-width:520px;text-align:center"><p>This page comes in the next build phase.</p><?php if ($signedIn) { ?><div style="max-width:240px;margin:0 auto 16px;display:grid"><button type="button" class="pnvDaq_logout" data-pf="logout"><?= AuthHtml::icon('log-out', 16) ?>Logout</button></div><?php } ?><p><a href="/">Back to the home page</a></p></div></main>
