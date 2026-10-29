<?php
declare(strict_types=1);

use Pratyangi\Views\ShopInfo;
use Pratyangi\Views\View;
use Pratyangi\Web\CatalogLinks;

// The footer of every page. The company details come from the SHOP_* settings (ShopInfo); an empty one is not printed. Links go only to pages that exist.
$socialIcons = ['facebook' => ['Facebook', 'facebook.svg', 18, 18], 'youtube' => ['YouTube', 'youtube.svg', 20, 14], 'instagram' => ['Instagram', 'instagram.svg', 18, 18], 'whatsapp' => ['WhatsApp', 'whatsapp.svg', 18, 18]];
$social = '';
foreach (ShopInfo::social() as $network => $url) {
    [$label, $file, $w, $h] = $socialIcons[$network];
    $social .= '<a href="' . View::e($url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . $label . '"><img src="/assets/images/figma/footer/' . $file . '" alt="" width="' . $w . '" height="' . $h . '"/></a>';
}
// a collection with no products of its own goes to the search for its name; when that finds nothing the link is left out
$collections = [];
foreach ([['Kanchipuram Pattu', 'Kanchi Pattu Saree'], ['Banarasi', 'Banarasi Saree'], ['Uppada', 'Uppada Pattu Saree'], ['Bridal Sarees', null], ['New Arrivals', '']] as [$name, $fabric]) {
    $href = CatalogLinks::collectionLink($name, $fabric);
    if ($href !== null) {
        $collections[] = [$name, $href];
    }
}
foreach (CatalogLinks::collections() as $slug => $name) {
    if (!in_array($name, array_column($collections, 0), true)) {
        $collections[] = [View::e($name), '/collection/' . $slug];
    }
}
$support = [['Contact us', '/contact'], ['Shipping Policy', '/shipping-policy'], ['Refund Policy', '/refund-policy']];
$legal = [['Privacy Policy', '/privacy'], ['Terms &amp; Conditions', '/terms'], ['Cookie Policy', '/cookie-policy']];
$links = static function (array $rows): string {
    $out = '';
    foreach ($rows as [$label, $href]) {
        $out .= '<a href="' . $href . '">' . $label . '</a>';
    }
    return $out;
};
$contact = '';
if (ShopInfo::address() !== '') {
    $contact .= '<span>' . nl2br(View::e(ShopInfo::address()), false) . '</span>';
}
if (ShopInfo::cin() !== '') {
    $contact .= '<span>CIN: ' . View::e(ShopInfo::cin()) . '</span>';
}
if (ShopInfo::gstin() !== '') {
    $contact .= '<span>GSTIN: ' . View::e(ShopInfo::gstin()) . '</span>';
}
if (ShopInfo::phone() !== '') {
    $contact .= (ShopInfo::phoneLink() !== '' ? '<a href="' . View::e(ShopInfo::phoneLink()) . '">' . View::e(ShopInfo::phone()) . '</a>' : '<span>' . View::e(ShopInfo::phone()) . '</span>');
}
if (ShopInfo::whatsappLink() !== '') {
    $contact .= '<a href="' . View::e(ShopInfo::whatsappLink()) . '" target="_blank" rel="noopener noreferrer">WhatsApp</a>';
}
if (ShopInfo::email() !== '') {
    $contact .= '<a href="mailto:' . View::e(ShopInfo::email()) . '">' . View::e(ShopInfo::email()) . '</a>';
}
if (ShopInfo::hours() !== '') {
    $contact .= '<span>' . View::e(ShopInfo::hours()) . '</span>';
}
?><footer class="f_viWW_footer"><div class="f_viWW_footerMain"><div class="f_viWW_footerBrand"><div class="f_viWW_footerLogo"><img alt="" loading="lazy" width="58" height="58" decoding="async" style="color:transparent" src="<?= View::e(ShopInfo::logoPath()) ?>"/><span class="f_viWW_footerLogoText"><strong><?= View::e(strtoupper(ShopInfo::brandName())) ?></strong><small>TRADITION MEETS ELEGANCE</small></span></div><p>“Tradition Woven with Elegance” —<br/>handcrafted silk sarees since 1985.</p><?php if (ShopInfo::brandName() !== ShopInfo::legalName()) { ?><p class="f_viWW_brandOf"><?= View::e(ShopInfo::brandLine()) ?></p><?php } ?><?php if ($social !== '') { ?><div class="f_viWW_footerSocial" aria-label="Social media"><?= $social ?></div><?php } ?></div><div class="f_viWW_footerColumn"><h3>COLLECTIONS</h3><?= $links($collections) ?></div><div class="f_viWW_footerColumn"><h3>SUPPORT</h3><?= $links($support) ?></div><div class="f_viWW_footerColumn"><h3>LEGAL</h3><?= $links($legal) ?><button type="button" class="pf_linkBtn pf_cookieSettings" data-pf="cookie-settings">Cookie settings</button></div><?php if ($contact !== '') { ?><div class="f_viWW_footerColumn" data-pf="company"><h3><?= View::e(strtoupper(ShopInfo::legalName())) ?></h3><?= $contact ?></div><?php } ?></div><div class="f_viWW_paymentRow"><div class="f_viWW_paymentMethods"><span>Secure Payments:</span><b>Visa</b><b>Mastercard</b><b>UPI</b><b>GPay</b><b>PhonePe</b></div></div><div class="f_viWW_copyright">© 2026 <?= View::e(ShopInfo::legalName()) ?>. All rights reserved. Made with <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-heart" aria-hidden="true"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"></path></svg> in India.</div></footer><?php if (ShopInfo::whatsappLink() !== '') { ?><a class="pf_wa" href="<?= View::e(ShopInfo::whatsappLink() . '?text=' . rawurlencode('Hi ' . ShopInfo::brandName())) ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat with us on WhatsApp"><img src="/assets/images/figma/home/whatsapp.svg" alt="" width="28" height="28"/></a><?php } ?>
