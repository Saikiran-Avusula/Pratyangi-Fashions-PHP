<?php
declare(strict_types=1);

// Writes public/errors/{403,404,500,503}.html: plain copies of the error pages for Apache's ErrorDocument (errors PHP never sees).
// Run after changing the texts in app/Web/ErrorPages.php: php tools/build_error_pages.php
$text = [403 => ['Access denied', 'You do not have permission to see this page.'], 404 => ['Page not found', 'The page you are looking for does not exist or may have moved.'],
    500 => ['Something went wrong on our side', 'Please try again in a moment.'], 503 => ["We'll be right back", 'The shop is being updated. Please check back in a little while.']];
foreach ($text as $status => [$title, $line]) {
    $t = htmlspecialchars($title, ENT_QUOTES);
    file_put_contents(dirname(__DIR__) . "/public/errors/$status.html", '<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><meta name="robots" content="noindex"/><title>' . $t . ' | Pratyangi Fashions</title><link rel="icon" href="/assets/icon.svg" type="image/svg+xml"/><link rel="stylesheet" href="/assets/css/1_e1s2q5g68a2.css"/><link rel="stylesheet" href="/assets/site.css"/></head>
<body><main class="pf_doc"><div class="pf_emptyState" role="alert" style="padding:96px 16px"><h2>' . $t . '</h2><p>' . htmlspecialchars($line, ENT_QUOTES) . '</p><div class="pf_emptyActions"><a class="pf_emptyBtn" href="/">Go to home</a><a class="pf_emptyBtn pf_emptyGhost" href="/new-arrivals">New arrivals</a></div></div></main></body></html>
');
}
