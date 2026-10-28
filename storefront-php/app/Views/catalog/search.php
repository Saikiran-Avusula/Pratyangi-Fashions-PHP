<?php
declare(strict_types=1);

use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
/** @var string $q what was searched for (trimmed) */
/** @var list<array<string,mixed>> $products the matches */
/** @var bool $catalogError the catalogue could not be read */
/** @var string $hint a line that replaces the products ("" when there is none) */
$account = $account ?? null;
// the New Arrivals frame around a product grid, for the header search's results page (/search?q=)
?><main class="_4HCqHa_page"><?php require dirname(__DIR__) . '/partials/header.php'; ?><div class="_4HCqHa_catalog"><section class="_4HCqHa_listing" id="catalog-top" aria-label="Search results"><div class="_4HCqHa_intro"><div class="_4HCqHa_introTitle"><h1>Search results</h1><p><?= $q === '' ? 'Search the catalogue' : 'for &ldquo;' . View::e($q) . '&rdquo;' ?></p></div></div><?php
if ($hint !== '') {
    $found = $hint === 'No products found';
    $icon = 'search';
    $title = $found ? 'No products found' : 'Search the catalogue';
    $line = $found ? 'Try another word, or browse our collections.' : $hint;
    $actions = [['Start shopping', '/new-arrivals']];
    require dirname(__DIR__) . '/partials/empty_state.php';
} else {
    require dirname(__DIR__) . '/partials/products.php';
}
?></section></div><?php require dirname(__DIR__) . '/partials/footer.php'; ?></main>
