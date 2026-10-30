<?php
declare(strict_types=1);

namespace Pratyangi\Views;

use Pratyangi\Config;

/**
 * The shop's company details for the footer, the policy pages and the emails. They come from the settings (SHOP_*) and nowhere else;
 * an empty one is not shown. App sets it once per process; a page or mail that runs before that sees the defaults (the brand name only).
 */
final class ShopInfo
{
    /** Looked up in this order under public/assets/images/brand/; the existing emblem is the fallback. */
    private const LOGO_FILES = ['logo.svg', 'logo.webp', 'logo.png'];
    private const FALLBACK_LOGO = '/assets/images/reference/brand-emblem.webp';

    private static ?Config $config = null;
    private static ?string $brandDir = null;

    /** @param string|null $brandDir the folder of the logo files (tests pass a temporary one) */
    public static function init(Config $config, ?string $brandDir = null): void
    {
        self::$config = $config;
        self::$brandDir = $brandDir;
    }

    /** The footer shows the payment-method row only while PAYMENTS_ENABLED=true. */
    public static function paymentsEnabled(): bool
    {
        return self::$config?->paymentsEnabled ?? false;
    }

    private static function brandDir(): string
    {
        return rtrim(self::$brandDir ?? dirname(__DIR__, 2) . '/public/assets/images/brand', '/') . '/';
    }

    /** The registered company name, or the brand name when SHOP_LEGAL_NAME is empty. */
    public static function legalName(): string
    {
        return self::$config?->shopLegalName ?? 'Pratyangi Fashions';
    }

    public static function brandName(): string
    {
        return self::$config?->shopName ?? 'Pratyangi Fashions';
    }

    /** "Pratyangi Fashions (a unit of Vistaraverse E-Cart Private Limited)"; just the brand name when it is the company itself. */
    public static function brandLine(): string
    {
        return self::brandName() === self::legalName() ? self::brandName() : self::brandName() . ' (a unit of ' . self::legalName() . ')';
    }

    public static function seReg(): string
    {
        return self::$config?->shopSeReg ?? '';
    }

    public static function address(): string
    {
        return self::$config?->shopAddress ?? '';
    }

    public static function gstin(): string
    {
        return self::$config?->shopGstin ?? '';
    }

    public static function phone(): string
    {
        return self::$config?->shopPhone ?? '';
    }

    public static function email(): string
    {
        return self::$config?->shopSupportEmail ?? '';
    }

    public static function hours(): string
    {
        return self::$config?->shopHours ?? '';
    }

    public static function cin(): string
    {
        return self::$config?->shopCin ?? '';
    }

    /** https://wa.me/<digits>, or '' when SHOP_WHATSAPP is empty. */
    public static function whatsappLink(): string
    {
        $digits = self::$config?->shopWhatsapp ?? '';
        return $digits === '' ? '' : 'https://wa.me/' . $digits;
    }

    /** @return array{name:string,email:string,phone:string} the Grievance Officer; every part may be empty */
    public static function grievance(): array
    {
        return ['name' => self::$config?->shopGrievanceName ?? '', 'email' => self::$config?->shopGrievanceEmail ?? '', 'phone' => self::$config?->shopGrievancePhone ?? ''];
    }

    /** @return array<string,string> network => https address, only the ones that are set */
    public static function social(): array
    {
        return self::$config?->shopSocial ?? [];
    }

    /** The site-relative address of the logo: a file in public/assets/images/brand/ when there is one, else the existing emblem. */
    public static function logoPath(): string
    {
        foreach (self::LOGO_FILES as $file) {
            if (is_file(self::brandDir() . $file)) {
                return '/assets/images/brand/' . $file;
            }
        }
        return self::FALLBACK_LOGO;
    }

    /** The site's own address (WEB_ORIGIN), no trailing slash: emails need absolute links. */
    public static function siteUrl(): string
    {
        return rtrim(self::$config?->webUrl ?? '', '/');
    }

    /**
     * The email header picture: the navbar logo (emblem, name, tagline) rendered once at 2x as brand/email-logo.png, shown at half its size.
     * Null when the file is not there (the layout then falls back to the emblem and the name as text).
     * @return array{url:string,width:int,height:int}|null
     */
    public static function emailLogo(): ?array
    {
        $size = is_file(self::brandDir() . 'email-logo.png') ? getimagesize(self::brandDir() . 'email-logo.png') : false;
        return $size === false ? null : ['url' => self::siteUrl() . '/assets/images/brand/email-logo.png', 'width' => intdiv($size[0], 2), 'height' => intdiv($size[1], 2)];
    }

    /** The logo of the emails as a full address (an email has no site to be relative to): always brand/logo.png, else the emblem. */
    public static function mailLogoUrl(): string
    {
        $png = is_file(self::brandDir() . 'logo.png') ? '/assets/images/brand/logo.png' : self::FALLBACK_LOGO;
        return self::siteUrl() . $png;
    }

    /** "Free Shipping on Orders Above ₹1,999" (Indian digit grouping), or '' when SHOP_FREE_SHIPPING_ABOVE is empty. */
    /** The top-bar messages, one shown at a time. A shipping promise appears only when SHOP_FREE_SHIPPING_ABOVE is set. @return list<string> */
    public static function announcements(): array
    {
        $list = self::$config?->shopAnnouncements ?? ['Authentic Handlooms'];
        $free = self::freeShippingText();
        return $free === '' ? $list : array_merge([$free], $list);
    }

    public static function freeShippingText(): string
    {
        $rupees = self::$config?->shopFreeShippingAbove;
        if ($rupees === null) {
            return '';
        }
        $digits = (string) $rupees;
        $grouped = strlen($digits) > 3 ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($digits, 0, -3)) . ',' . substr($digits, -3) : $digits;
        return 'Free Shipping on Orders Above ₹' . $grouped;
    }

    /** The "tel:" form of the phone number (digits and a leading +), or '' when it has none. */
    public static function phoneLink(): string
    {
        $digits = preg_replace('/[^0-9+]/', '', self::phone()) ?? '';
        return $digits === '' ? '' : 'tel:' . $digits;
    }
}
