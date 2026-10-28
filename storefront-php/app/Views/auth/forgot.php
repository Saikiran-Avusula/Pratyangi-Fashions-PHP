<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\View;

/** @var string $query "" or "?next=/checkout" */
// ForgotView.tsx, first step: ask for the email. The "enter the code" and "done" steps are <template>s (see forgot_templates.php).
?><span class="neurva_badge" aria-hidden="true"><?= AuthHtml::icon('key-round', 26) ?></span><h1 class="neurva_title">Forgot your password?</h1><p class="neurva_lead"><?= $smsEnabled ?? true ? 'We will send a code to the verified email or mobile number on your account, so you can set a new password.' : 'We will email a code to the verified email address on your account, so you can set a new password.' ?></p><?= ($smsEnabled ?? true) ? AuthHtml::forgotTabs() : '' ?><form class="neurva_form" novalidate id="pf-panel-email" role="tabpanel" aria-labelledby="pf-mode-email" data-pf="auth-form" data-pf-panel="email"><?= AuthHtml::textField('auth-email', 'Email address', ['type' => 'email', 'placeholder' => 'you@example.com', 'autocomplete' => 'email', 'inputmode' => 'email']) ?><button type="submit" class="neurva_submit">Send code</button></form><?= ($smsEnabled ?? true) ? AuthHtml::forgotMobileForm($query) : '' ?><p class="neurva_switch"><a href="/login<?= View::e($query) ?>">Back to sign in</a></p>
