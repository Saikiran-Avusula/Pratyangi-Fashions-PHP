<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\View;

/** @var bool $fromCheckout ?next=/checkout was given */
/** @var string $query "" or "?next=/checkout" */
// SignupView.tsx
$smsEnabled = $smsEnabled ?? true;
$lead = $fromCheckout ? 'Create an account to continue to checkout. Your bag is saved.' : 'Join Pratyangi Fashions for faster checkout, order tracking and AI try-on.';
?><h1 class="neurva_title">Create your account</h1><p class="neurva_lead"><?= View::e($lead) ?></p><?= ($googleClientId ?? null) !== null ? AuthHtml::google($googleClientId, true) : '' ?><?= $smsEnabled ? AuthHtml::modeTabs() : '' ?><form class="neurva_form" novalidate id="pf-panel-email" role="tabpanel" aria-labelledby="pf-mode-email" data-pf="auth-form" data-pf-panel="email"><?= AuthHtml::textField('auth-name', 'Full name', ['placeholder' => 'Gaurav Kumar', 'autocomplete' => 'name']) ?><?= AuthHtml::textField('auth-email', 'Email address', ['type' => 'email', 'placeholder' => 'you@example.com', 'autocomplete' => 'email', 'inputmode' => 'email']) ?><div><?= AuthHtml::passwordField('auth-password', 'Password', 'Create a password', 'new-password') ?><?= AuthHtml::passwordStrength() ?></div><?= AuthHtml::passwordField('auth-confirm', 'Confirm password', 'Re-enter your password', 'new-password') ?><div data-pf="terms"><label class="neurva_check"><input type="checkbox" id="auth-terms"/><span class="neurva_box" aria-hidden="true"></span><span>I agree to the <span class="neurva_strong">Terms &amp; Conditions</span> and <span class="neurva_strong">Privacy Policy</span>.</span></label></div><button type="submit" class="neurva_submit">Create Account</button></form><?= $smsEnabled ? AuthHtml::otpForm(true) : '' ?><p class="neurva_switch">Already have an account? <a href="/login<?= View::e($query) ?>">Sign in</a></p>
