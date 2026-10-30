<?php
declare(strict_types=1);

namespace Pratyangi\Views;

/**
 * HTML pieces of the sign-in pages, with the class names and markup of the Node components
 * (components/auth/Fields.tsx, AuthShell.tsx, useAuthFlow.tsx). The class prefix `neurva_` is the compiled CSS module name of Auth.module.css.
 * Every dynamic value goes through View::e; icon paths are fixed constants (lucide-react).
 */
final class AuthHtml
{
    /** lucide icon name => inner markup (the paths copied from lucide-react, as the Node build draws them) */
    private const ICONS = [
        'mail' => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"></path><rect x="2" y="4" width="20" height="16" rx="2"></rect>',
        'smartphone' => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"></rect><path d="M12 18h.01"></path>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path><circle cx="12" cy="12" r="3"></circle>',
        'eye-off' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"></path><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"></path><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"></path><path d="m2 2 20 20"></path>',
        'circle-check' => '<circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path>',
        'circle-alert' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" x2="12" y1="8" y2="12"></line><line x1="12" x2="12.01" y1="16" y2="16"></line>',
        'mail-check' => '<path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h8"></path><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path><path d="m16 19 2 2 4-4"></path>',
        'key-round' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"></path><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"></circle>',
        'package' => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path>',
        'map-pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"></path><circle cx="12" cy="10" r="3"></circle>',
        'sparkles' => '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path><circle cx="4" cy="20" r="2"></circle>',
        'log-out' => '<path d="m16 17 5-5-5-5"></path><path d="M21 12H9"></path><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>',
        'heart' =>'<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"></path>',
    ];

    /** The reasons shown beside the form (AuthShell perks). */
    private const PERKS = [
        ['package', 'Track every order from your account'],
        ['map-pin', 'Save addresses for a faster checkout'],
        ['heart', 'Keep a wishlist of your favourites'],
    ];

    /** A lucide icon as inline SVG ($extra = fixed attribute text such as ' data-icon="email"'). */
    public static function icon(string $name, int $size, string $extra = ''): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-' . $name . '" aria-hidden="true"' . $extra . '>' . self::ICONS[$name] . '</svg>';
    }

    /** The brand panel beside the card (AuthShell's aside). */
    public static function brandPanel(): string
    {
        $items = '';
        foreach (self::PERKS as [$icon, $text]) {
            $items .= '<li>' . self::icon($icon, 18) . View::e($text) . '</li>';
        }
        return '<aside class="neurva_brand" aria-label="Why create an account" data-pf="auth-brand"><img alt="" decoding="async" class="neurva_brandPhoto" style="position:absolute;height:100%;width:100%;left:0;top:0;right:0;bottom:0;color:transparent" src="/assets/images/figma/home/join-background.webp"/><div class="neurva_brandShade" aria-hidden="true"></div><div class="neurva_brandContent"><img alt="" loading="lazy" width="64" height="64" decoding="async" style="color:transparent" src="/assets/images/reference/brand-emblem.webp"/><p class="neurva_brandName">PRATYANGI FASHIONS</p><h2>Where Tradition Meets Elegance</h2><ul>' . $items . '</ul></div></aside>';
    }

    /**
     * A text input with its label (TextField).
     * @param array<string,string> $attrs fixed input attributes (name => value), escaped here
     * @param string $labelAside raw, already-escaped HTML placed at the right of the label
     * @param string $icon raw icon HTML, or ''
     */
    public static function textField(string $id, string $label, array $attrs, string $labelAside = '', string $icon = '', bool $reserveMessage = false): string
    {
        $input = '<input id="' . View::e($id) . '" aria-required="true"';
        foreach ($attrs as $name => $value) {
            $input .= ' ' . $name . '="' . View::e($value) . '"';
        }
        $input .= '/>';
        $iconHtml = $icon !== '' ? '<span class="neurva_icon" aria-hidden="true" data-pf="field-icon">' . $icon . '</span>' : '';
        return '<div class="neurva_field"><div class="neurva_labelRow"><label for="' . View::e($id) . '" class="neurva_required">' . View::e($label) . '</label>' . $labelAside . '</div><div class="neurva_control">' . $iconHtml . $input . '</div>' . ($reserveMessage ? '<div class="neurva_messageSlot"></div>' : '') . '</div>';
    }

    /** A password input with the show/hide button (PasswordField). */
    public static function passwordField(string $id, string $label, string $placeholder, string $autocomplete, string $labelAside = ''): string
    {
        $lower = strtolower($label);
        return '<div class="neurva_field"><div class="neurva_labelRow"><label for="' . View::e($id) . '" class="neurva_required">' . View::e($label) . '</label>' . $labelAside . '</div><div class="neurva_control"><input id="' . View::e($id) . '" type="password" aria-required="true" placeholder="' . View::e($placeholder) . '" autocomplete="' . View::e($autocomplete) . '"/><button type="button" class="neurva_reveal" aria-pressed="false" aria-label="Show ' . View::e($lower) . '" data-pf="reveal" data-pf-label="' . View::e($lower) . '">' . self::icon('eye', 18) . '</button></div></div>';
    }

    /** The four bars and the rules under a new-password input (PasswordStrength, empty state). */
    public static function passwordStrength(): string
    {
        $rules = '';
        foreach (['At least 8 characters', 'A letter', 'A number'] as $label) {
            $rules .= '<li><span aria-hidden="true">○</span> ' . View::e($label) . '</li>';
        }
        return '<div class="neurva_strength" data-score="0" data-pf="strength"><div class="neurva_bars" aria-hidden="true"><span></span><span></span><span></span><span></span></div><p class="neurva_strengthLabel" role="status" aria-live="polite">Use at least 8 characters with a letter and a number.</p><ul class="neurva_rules" aria-label="Password rules">' . $rules . '</ul></div>';
    }

    /** The six-box code input (OtpField). */
    public static function otpField(string $id, string $label): string
    {
        $boxes = str_repeat('<span aria-hidden="true" class="neurva_otpBox"></span>', 6);
        return '<div class="neurva_field"><label for="' . View::e($id) . '" class="neurva_required">' . View::e($label) . '</label><div class="neurva_otp" data-pf="otp"><input id="' . View::e($id) . '" class="neurva_otpInput" inputmode="numeric" autocomplete="one-time-code" maxlength="6" aria-required="true"/>' . $boxes . '</div></div>';
    }

    /** The "Continue with Google" block above the forms and tabs (auth.js loads Google's script and draws its button into [data-pf=google-button]). Only built when GOOGLE_CLIENT_ID is set. */
    public static function google(string $clientId, bool $signup): string
    {
        return '<div class="pf_google" data-pf="google" data-client-id="' . View::e($clientId) . '"' . (\Pratyangi\Views\ShopInfo::googleScriptPath() !== null ? ' data-script-src="' . View::e(\Pratyangi\Views\ShopInfo::googleScriptPath()) . '"' : '') . '><div class="pf_googleButton" data-pf="google-button"></div>'
            . '<p class="neurva_error" role="alert" data-pf="google-error" hidden></p>'
            . ($signup ? '<p class="pf_googleNote">By continuing with Google you agree to our <a href="/terms">Terms &amp; Conditions</a> and <a href="/privacy">Privacy Policy</a>.</p>' : '') . '<p class="pf_googleOr"><span>or</span></p></div>';
    }

    /** The Email | Mobile OTP switch above the forms of the sign-in and sign-up pages (PHP-only; auth-otp.js shows the matching [data-pf-panel]). */
    public static function modeTabs(): string
    {
        return '<div class="pf_modes" role="tablist" aria-label="Sign-in method" data-pf="modes"><button type="button" role="tab" class="pf_mode" id="pf-mode-email" aria-selected="true" aria-controls="pf-panel-email" data-pf-mode="email">Email</button><button type="button" role="tab" class="pf_mode" id="pf-mode-otp" aria-selected="false" aria-controls="pf-panel-otp" tabindex="-1" data-pf-mode="otp">Mobile OTP</button></div>';
    }

    /**
     * The mobile code form, the same on the sign-in and the sign-up page: one flow, a number then a code. Step one: the number. Step two,
     * shown by auth-otp.js after Send OTP: the six-box code, Resend with its countdown and Change number. A number without an account gets
     * one when the code is right; no name or email is asked. The sign-up page also asks for the terms.
     * @param bool $signup the sign-up page: the terms are asked up front
     */
    public static function otpForm(bool $signup): string
    {
        $mobile = '<div class="neurva_field"><div class="neurva_labelRow"><label for="otp-mobile" class="neurva_required">Mobile number</label></div><div class="neurva_control"><span class="neurva_prefix" aria-hidden="true">+91</span><input id="otp-mobile" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="98765 43210" maxlength="14" aria-required="true"/></div><div class="neurva_messageSlot"></div></div>';
        $terms = '<div data-pf="otp-terms"><label class="neurva_check"><input type="checkbox" id="otp-terms"/><span class="neurva_box" aria-hidden="true"></span><span>I agree to the <span class="neurva_strong">Terms &amp; Conditions</span> and <span class="neurva_strong">Privacy Policy</span>.</span></label></div>';
        $code = '<div data-pf="otp-code-wrap" hidden><p class="neurva_hint" data-pf="otp-sent" role="status"></p>' . self::otpField('otp-code', 'Code')
            . '<p class="neurva_resend"><button type="button" class="neurva_inlineLink" data-pf="otp-resend">Resend code</button> <button type="button" class="neurva_inlineLink" data-pf="otp-change">Change number</button></p></div>';
        return '<form class="neurva_form" novalidate hidden id="pf-panel-otp" role="tabpanel" aria-labelledby="pf-mode-otp" data-pf="otp-form" data-pf-panel="otp" data-pf-signup="' . ($signup ? '1' : '0') . '">'
            . $mobile . $code . ($signup ? $terms . '<div data-pf="otp-offers"><label class="neurva_check"><input type="checkbox" id="otp-offers"/><span class="neurva_box" aria-hidden="true"></span><span>Send me offers and new arrivals by SMS, WhatsApp and email.</span></label></div>' : '')
            . '<button type="submit" class="neurva_submit" data-pf="otp-submit">Send OTP</button></form>';
    }

    /**
     * The step after an email sign-up: the customer is signed in and a 6-digit code was emailed. auth.js clones this, fills the
     * [data-pf-slot] places with text and calls POST /auth/email/verify {code} (or /auth/email/resend for another code).
     */
    public static function emailCodeTemplate(): string
    {
        return '<template data-pf-tpl="verify-code"><span class="neurva_badge" aria-hidden="true">' . self::icon('mail-check', 26) . '</span><h1 class="neurva_title">Verify your email</h1><p class="neurva_lead" data-pf-slot="lead"></p>'
            . '<form class="neurva_form" novalidate data-pf="verify-form"><div>' . self::otpField('verify-code', 'Code') . '<p class="neurva_resend"><button type="button" class="neurva_inlineLink" data-pf="verify-resend">Resend code</button></p></div><button type="submit" class="neurva_submit">Verify Email</button></form>'
            . '<p class="neurva_switch"><button type="button" class="neurva_inlineLink" data-pf="verify-skip">Skip for now</button></p></template>';
    }

    /** The Email code | Mobile code switch of the forgot-password page (auth.js shows the matching [data-pf-panel]). */
    public static function forgotTabs(): string
    {
        return '<div class="pf_modes" role="tablist" aria-label="Where to send the code" data-pf="modes"><button type="button" role="tab" class="pf_mode pf_modeOn" id="pf-mode-email" aria-selected="true" aria-controls="pf-panel-email" data-pf-mode="email">Email code</button><button type="button" role="tab" class="pf_mode" id="pf-mode-mobile" aria-selected="false" aria-controls="pf-panel-mobile" tabindex="-1" data-pf-mode="mobile">Mobile code</button></div>';
    }

    /** The forgot-password form for a mobile number, with the note for accounts that have a mobile number and no password. */
    public static function forgotMobileForm(string $query): string
    {
        $mobile = '<div class="neurva_field"><div class="neurva_labelRow"><label for="auth-mobile" class="neurva_required">Mobile number</label></div><div class="neurva_control"><span class="neurva_prefix" aria-hidden="true">+91</span><input id="auth-mobile" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="98765 43210" maxlength="14" aria-required="true"/></div><div class="neurva_messageSlot"></div></div>';
        return '<form class="neurva_form" novalidate hidden id="pf-panel-mobile" role="tabpanel" aria-labelledby="pf-mode-mobile" data-pf="auth-form-mobile" data-pf-panel="mobile">' . $mobile
            . '<p class="neurva_hint">The code goes only to the verified mobile number on your account. Signed up with only a mobile number and no password? <a class="neurva_inlineLink" href="/login' . View::e($query) . '">Sign in with OTP</a> instead.</p>'
            . '<button type="submit" class="neurva_submit">Send code</button></form>';
    }

    /**
     * The four outcomes of the verify-email page (VerifyEmailView.tsx). The text that depends on the answer is left to auth.js
     * ([data-pf-slot]); the fixed text and the links are here.
     * @return array{checking:string,missing:string,verified:string,failed:string}
     */
    public static function verifyStates(bool $signedIn): array
    {
        // after a verification a signed-in visitor goes to the landing page, never to /account by default (PHP-only change, docs/PAGES_PARITY.md)
        $goHref = $signedIn ? '/' : '/login';
        $goText = $signedIn ? 'Continue shopping' : 'Sign In';
        // verification now uses a 6-digit code, sent from the account page; this page only opens the old emailed links
        $action = $signedIn
            ? '<a class="neurva_submit" href="/account">Verify with a code on your account page</a>'
            : '<a class="neurva_submit" href="/login?next=/account">Sign in to get a code</a>';
        return [
            'checking' => '<div class="neurva_success" role="status" aria-live="polite"><span class="neurva_spinner" style="border-color:rgba(183, 112, 1, 0.3);border-top-color:#b77001;width:32px;height:32px" aria-hidden="true"></span><h1 class="neurva_title">Checking your link…</h1></div>',
            'missing' => '<div class="neurva_success">' . self::icon('mail-check', 48, ' style="color:#b77001"') . '<h1 class="neurva_title">Open the link from your email</h1><p>This page confirms your email address. Use the link in the message we emailed you.</p><a class="neurva_submit" href="' . $goHref . '">' . $goText . '</a></div>',
            'verified' => '<div class="neurva_success" role="status">' . self::icon('circle-check', 48) . '<h1 class="neurva_title">Email verified</h1><p data-pf-slot="thanks"></p><a class="neurva_submit" href="' . $goHref . '">' . $goText . '</a></div>',
            'failed' => '<div class="neurva_success">' . self::icon('circle-alert', 48, ' style="color:#a1112f"') . '<h1 class="neurva_title">This link did not work</h1><p role="alert" data-pf-slot="message"></p>' . $action . '</div>',
        ];
    }

    /** The login page's templates: the welcome and the phone icon the email box shows while a mobile number is typed. */
    public static function loginTemplates(): string
    {
        return self::welcomeTemplate() . '<template data-pf-tpl="icon-mobile">' . self::icon('smartphone', 18, ' data-icon="mobile"') . '</template>';
    }

    /** The "Welcome back, Name!" block that replaces the card after a sign-in (Welcome); the text is filled in by auth.js. */
    public static function welcomeTemplate(): string
    {
        return '<template data-pf-tpl="welcome"><div class="neurva_success" role="status">' . self::icon('circle-check', 48) . '<h2 data-pf-slot="heading"></h2><p data-pf-slot="text"></p></div></template>';
    }
}
