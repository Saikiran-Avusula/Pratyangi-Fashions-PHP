<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\View;
use Pratyangi\Web\HeaderAccount;

/** @var string $page login | signup | forgot | verify */
/** @var string $card the card's inner HTML (already escaped) */
/** @var string $templates inert <template> elements auth.js clones for the later states (already escaped) */
/** @var HeaderAccount|null $account the signed-in visitor for the header, or null */
/** @var string $next the safe path to go to after a sign-in ("/" unless ?next= is a safe same-site path) */
$account = $account ?? null;
$next = $next ?? '/';
// The page frame of AuthShell: the site header and footer around the brand panel and the form card (Node: <main className={styles.page}>).
?><main class="neurva_page" data-pf-auth="<?= View::e($page) ?>" data-pf-next="<?= View::e($next) ?>"<?= ($smsEnabled ?? true) ? '' : ' data-pf-sms="0"' ?>><?php require dirname(__DIR__) . '/partials/header.php'; ?><section class="neurva_stage"><?= AuthHtml::brandPanel() ?><div class="neurva_card" data-pf="auth-card"><?= $card ?></div></section><?php require dirname(__DIR__) . '/partials/footer.php'; ?><template data-pf-tpl="icon-eye-off"><?= AuthHtml::icon('eye-off', 18) ?></template><?= $templates ?></main>
