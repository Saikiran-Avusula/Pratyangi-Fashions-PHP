<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;

/** @var bool $signedIn the visitor has a valid session */
// The outcomes auth.js shows after the API answers: it clones one and fills the [data-pf-slot] places with text.
$states = AuthHtml::verifyStates($signedIn);
?><template data-pf-tpl="verified"><?= $states['verified'] ?></template><template data-pf-tpl="failed"><?= $states['failed'] ?></template>
