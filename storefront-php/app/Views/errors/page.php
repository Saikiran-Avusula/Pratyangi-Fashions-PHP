<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;
use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var HeaderAccount|null $account */
/** @var string $errIcon */
/** @var string $errTitle */
/** @var string $errLine */
/** @var bool $search the 404 page also has a search box */
$account = $account ?? null; // header.php reuses loop names like $title, so this page's values have their own names
// The search box is the header's search field (pSCCwa_searchField) inside THE empty state.
$errSearch = $search ? '<form class="pf_errSearch" action="/search" method="get" role="search"><div class="pSCCwa_searchField">' . Icons::svg('search', 18, 2) . '<input type="search" name="q" placeholder="Search products" aria-label="Search products" minlength="2" maxlength="80" required/></div></form>' : '';
// An error page: the site's header and footer around THE empty state.
?><main class="pf_doc"><?php require dirname(__DIR__) . '/partials/header.php'; ?><?= View::render('partials/empty_state', ['icon' => $errIcon, 'title' => $errTitle, 'line' => $errLine, 'actions' => [['Go to home', '/'], ['New arrivals', '/new-arrivals']], 'attrs' => ' role="alert"', 'extra' => $errSearch]) ?><?php require dirname(__DIR__) . '/partials/footer.php'; ?></main>
