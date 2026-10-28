<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\View;

/** @var string $query "" or "?next=/checkout" */
// ForgotView.tsx, second and last step. auth.js clones these and fills the [data-pf-slot] places with text.
?><template data-pf-tpl="reset"><span class="neurva_badge" aria-hidden="true"><?= AuthHtml::icon('key-round', 26) ?></span><h1 class="neurva_title">Set a new password</h1><p class="neurva_lead">If an account matches, we sent a 6-digit code to <span data-pf-slot="target"></span>. <button type="button" class="neurva_inlineLink" data-pf="change">Change</button></p><form class="neurva_form" novalidate data-pf="reset-form"><div><?= AuthHtml::otpField('auth-code', 'Code') ?><p class="neurva_resend"><button type="button" class="neurva_inlineLink" data-pf="resend">Resend code</button></p></div><div><?= AuthHtml::passwordField('auth-password', 'New password', 'Create a new password', 'new-password') ?><?= AuthHtml::passwordStrength() ?></div><?= AuthHtml::passwordField('auth-confirm', 'Confirm new password', 'Re-enter your new password', 'new-password') ?><button type="submit" class="neurva_submit">Reset Password</button></form></template><template data-pf-tpl="done"><div class="neurva_success" role="status"><?= AuthHtml::icon('circle-check', 48) ?><h2>Password updated</h2><p>Your password is changed and you are signed out everywhere. Taking you to the home page…</p><a class="neurva_submit" href="/login<?= View::e($query) ?>">Sign In</a></div></template>
