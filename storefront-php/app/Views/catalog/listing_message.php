<?php
declare(strict_types=1);

use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
/** @var string $note already-escaped CatalogHtml::note() box (the catalogue could not be read, or is empty) */
$account = $account ?? null;
// NewArrivalsMessage of the web: the page frame around one message.
?><main class="_4HCqHa_page"><?php require dirname(__DIR__) . '/partials/header.php'; ?><div class="_4HCqHa_catalog"><section class="_4HCqHa_listing" id="catalog-top" aria-label="New arrivals"><div class="_4HCqHa_intro"><div class="_4HCqHa_introTitle"><h1>New Arrivals</h1><p>Discover the latest additions to our exclusive collection</p></div></div><?= $note ?></section></div><?php require dirname(__DIR__) . '/partials/footer.php'; ?></main>
