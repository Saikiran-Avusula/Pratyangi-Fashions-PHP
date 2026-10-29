<?php
declare(strict_types=1);

use Pratyangi\Views\View;

/** @var string $title */
/** @var string $content already-rendered, already-escaped page body */
// Optional: the page description, the compiled stylesheets (file names under /assets/css) and the page scripts (file names under /assets).
$description = $description ?? 'Discover heirloom Indian sarees, festive wear, and timeless occasion dressing at Pratyangi Fashions.';
$styles = $styles ?? ['1_e1s2q5g68a2.css', '31pkxlgg4o4yb.css', '3wh3hbs4o_ca8.css'];
$scripts = $scripts ?? ['site.js'];
$links = '';
foreach ($styles as $style) {
    $links .= '<link rel="stylesheet" href="/assets/css/' . View::e($style) . '"/>';
}
$tags = '';
foreach (array_merge($scripts, in_array('cookies.js', $scripts, true) ? [] : ['cookies.js']) as $script) { // the cookie notice is on every page
    $tags .= '<script defer src="/assets/' . View::e($script) . '"></script>';
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/><title><?= View::e($title) ?></title><meta name="description" content="<?= View::e($description) ?>"/><?= ($noindex ?? false) ? '<meta name="robots" content="noindex"/>' : '' ?><link rel="icon" href="/assets/icon.svg" sizes="any" type="image/svg+xml"/><?= $links ?><link rel="stylesheet" href="/assets/site.css"/><?= $tags ?></head>
<body>
<?= $content ?>
<?php require __DIR__ . '/partials/cookie_bar.php'; ?></body></html>
