<?php
declare(strict_types=1);

use Pratyangi\Views\AuthHtml;
use Pratyangi\Views\ShopInfo;
use Pratyangi\Views\View;

/** @var bool $smsEnabled the mobile form is shown only while SMS_ENABLED=true */
/** @var string|null $googleClientId */
// "Forgot email or mobile?": ways to find the account. The contact block is built from SHOP_WHATSAPP, SHOP_SUPPORT_EMAIL and SHOP_HOURS; empty ones are left out.
$whatsapp = ShopInfo::whatsappLink();
$email = ShopInfo::email();
$hours = ShopInfo::hours();
$mobileField = '<div class="neurva_field"><div class="neurva_labelRow"><label for="help-mobile" class="neurva_required">Enter your mobile number</label></div><div class="neurva_control"><span class="neurva_prefix" aria-hidden="true">+91</span><input id="help-mobile" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="98765 43210" maxlength="14" aria-required="true"/></div><div class="neurva_messageSlot"></div></div>';
?><h1 class="neurva_title">Forgot email or mobile?</h1><p class="neurva_lead">A few ways to find the account you used.</p><div class="pf_help"><ol class="pf_helpList"><li><strong>Try Sign in with Google</strong><?= ($googleClientId ?? null) !== null ? AuthHtml::google($googleClientId, false) : '' ?></li><li>Search your inbox for “Welcome to Pratyangi Fashions” to find the email you used.</li><li>Signed in with email? Your mobile number is in <a class="neurva_inlineLink" href="/account">My account</a>.</li></ol><?php if ($smsEnabled ?? false) { ?><form class="neurva_form" novalidate data-pf="help-form"><?= $mobileField ?><button type="submit" class="neurva_submit">Text me a hint</button><p class="neurva_notice" role="status" data-pf="help-result" hidden></p></form><?php } ?><div class="pf_helpContact"><h2 class="pf_helpHeading">Still stuck? Contact us</h2><?php if ($whatsapp !== '') { ?><a class="neurva_submit" href="<?= View::e($whatsapp) ?>" target="_blank" rel="noopener">Chat on WhatsApp</a><?php } ?><?php if ($email !== '') { ?><p><a class="neurva_inlineLink" href="mailto:<?= View::e($email) ?>"><?= View::e($email) ?></a></p><?php } ?><?php if ($hours !== '') { ?><p class="neurva_hint"><?= View::e($hours) ?></p><?php } ?></div></div><p class="neurva_switch"><a href="/login">Back to sign in</a></p>
