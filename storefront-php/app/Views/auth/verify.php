<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;

/** @var bool $hasToken the address has a non-empty ?token= (auth.js then asks the API; otherwise the page shows "Open the link from your email") */
/** @var bool $signedIn the visitor has a valid session (Node: useSession().user) */
// VerifyEmailView.tsx: the page starts as "checking" (a token is present) or "missing"; the other outcomes are templates.
$states = AuthHtml::verifyStates($signedIn);
?><?= $hasToken ? $states['checking'] : $states['missing'] ?>
