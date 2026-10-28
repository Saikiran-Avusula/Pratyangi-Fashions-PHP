<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\View;

/** @var bool $fromCheckout ?next=/checkout was given */
/** @var string $query "" or "?next=/checkout", appended to the links between the sign-in pages */
// LoginView.tsx
$smsEnabled = $smsEnabled ?? true;
$lead = $fromCheckout ? 'Please sign in to continue to checkout. Your bag is saved.' : 'Sign in to track your orders and save addresses.';
$forgot = '<a class="neurva_inlineLink" href="/forgot-password' . View::e($query) . '">Forgot password?</a>';
?><h1 class="neurva_title">Welcome back</h1><p class="neurva_lead"><?= View::e($lead) ?></p><?= ($googleClientId ?? null) !== null ? AuthHtml::google($googleClientId, false) : '' ?><?= $smsEnabled ? AuthHtml::modeTabs() : '' ?><form class="neurva_form" novalidate id="pf-panel-email" role="tabpanel" aria-labelledby="pf-mode-email" data-pf="auth-form" data-pf-panel="email"><?= AuthHtml::textField('auth-identifier', 'Email or mobile number', [
    'type' => 'text', 'placeholder' => 'you@example.com', 'autocomplete' => 'username', 'inputmode' => 'email',
    'autocapitalize' => 'none', 'spellcheck' => 'false', 'data-kind' => 'empty',
], '', AuthHtml::icon('mail', 18, ' data-icon="email"'), true) ?><?= AuthHtml::passwordField('auth-password', 'Password', 'Your password', 'current-password', $forgot) ?><button type="submit" class="neurva_submit">Sign In</button></form><?= $smsEnabled ? AuthHtml::otpForm(false) : '' ?><p class="neurva_switch">New to Pratyangi Fashions? <a href="/signup<?= View::e($query) ?>">Create an account</a></p>
