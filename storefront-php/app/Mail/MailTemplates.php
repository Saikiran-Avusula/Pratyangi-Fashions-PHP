<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

use Pratyangi\Views\ShopInfo;

/**
 * The wording of the account emails and the ONE branded layout they all use (layout()). An email passes a title, its paragraphs and an
 * optional button; the layout adds the logo, the greeting and the footer, and makes the plain-text version from the same pieces.
 * Table-based with inline CSS (Gmail, Outlook and phones); every image has an absolute address and an alt text.
 */
final class MailTemplates
{
    private const BURGUNDY = '#800020';
    private const CREAM = '#fbf3e4';
    private const GOLD = '#b77001';
    private const BAND = '#f1e6cc';
    private const INK = '#3b2b27';
    private const FONT = 'Arial,Helvetica,sans-serif';
    private const ICONS = ['facebook' => ['Facebook', 18, 18], 'youtube' => ['YouTube', 20, 14], 'instagram' => ['Instagram', 18, 18], 'whatsapp' => ['WhatsApp', 18, 18]];

    /**
     * The 6-digit code email, by purpose (email_verify | reset). The reset wording is the Node API's.
     * @return array{subject:string,text:string,html:string}
     */
    public static function code(string $purpose, string $code, int $minutes, ?string $name = null): array
    {
        if ($purpose === 'reset') {
            $subject = 'Your Pratyangi Fashions password reset code';
            $title = 'Reset your password';
            $lead = 'Use this code to set a new password.';
            $note = 'If you did not ask to reset your password, you can ignore this email.';
        } else {
            $subject = 'Your Pratyangi Fashions email verification code';
            $title = 'Confirm your email';
            $lead = 'Enter this code on the ' . ShopInfo::brandName() . ' website to confirm your email address.';
            $note = 'If you did not ask for this, you can ignore this email.';
        }
        return ['subject' => $subject] + self::layout($title, [$lead, ['code' => $code, 'note' => "This code works for {$minutes} minutes."], $note], null, $name);
    }

    /**
     * @param list<string|array{code:string,note:string}> $paragraphs plain text (escaped here); an array is the code box
     * @param array{label:string,url:string}|null $button
     * @return array{text:string,html:string}
     */
    public static function layout(string $title, array $paragraphs, ?array $button = null, ?string $name = null): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $first = $name === null ? '' : explode(' ', trim($name))[0];
        $greeting = 'Hi ' . ($first !== '' ? $first : 'there') . ',';
        $site = ShopInfo::siteUrl();
        $host = preg_replace('/^www\./', '', (string) parse_url($site, PHP_URL_HOST));
        $because = 'You received this because you have an account at ' . ($host !== '' ? $host : 'pratyangi.com') . '.';
        $p = 'margin:0 0 16px;font-family:' . self::FONT . ';color:' . self::INK . ';font-size:16px;line-height:1.5;';

        $body = '<p style="' . $p . '">' . $e($greeting) . '</p>';
        $text = [$title, '', $greeting, ''];
        foreach ($paragraphs as $para) {
            if (is_array($para)) {
                $body .= '<p style="margin:20px 0;text-align:center;"><span style="display:inline-block;padding:12px 24px;background:#ffffff;border:1px solid #eadfc8;border-radius:6px;font-family:' . self::FONT . ';color:' . self::BURGUNDY . ';font-size:30px;font-weight:bold;letter-spacing:8px;">' . $e($para['code']) . '</span></p>'
                    . '<p style="' . $p . 'font-size:14px;text-align:center;">' . $e($para['note']) . '</p>';
                $text[] = "Your code is {$para['code']}. {$para['note']}";
            } else {
                $body .= '<p style="' . $p . '">' . $e($para) . '</p>';
                $text[] = $para;
            }
            $text[] = '';
        }
        if ($button !== null) {
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:24px auto;"><tr><td align="center" bgcolor="' . self::BURGUNDY . '" style="border-radius:6px;"><a href="' . $e($button['url']) . '" style="display:inline-block;padding:14px 32px;font-family:' . self::FONT . ';font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;">' . $e($button['label']) . '</a></td></tr></table>';
            $text[] = $button['label'] . ': ' . $button['url'];
            $text[] = '';
        }

        $img = static fn (string $src, string $alt, int $w, int $h, string $style = ''): string => '<img src="' . $e($src) . '" alt="' . $e($alt) . '" width="' . $w . '" height="' . $h . '" style="display:block;border:0;' . $style . '">';
        $logo = ShopInfo::mailLogoUrl();
        $icons = '';
        foreach (ShopInfo::social() as $network => $url) {
            [$label, $w, $h] = self::ICONS[$network];
            $icons .= '<td style="padding:0 8px;"><a href="' . $e($url) . '">' . $img($site . '/assets/images/brand/mail/' . $network . '.png', $label, $w, $h) . '</a></td>';
        }
        $company = '<strong>' . $e(ShopInfo::legalName()) . '</strong>';
        if (ShopInfo::address() !== '') {
            $company .= '<br>' . nl2br($e(ShopInfo::address()), false);
        }
        $support = ShopInfo::email() === '' ? '' : '<p style="margin:0 0 12px;"><a href="mailto:' . $e(ShopInfo::email()) . '" style="color:' . self::BURGUNDY . ';">' . $e(ShopInfo::email()) . '</a></p>';
        // one picture, linked to the shop: the navbar logo as it looks on the site (fallback: the emblem and the name as text)
        $mark = ShopInfo::emailLogo();
        $head = $mark !== null
            ? '<a href="' . $e($site) . '">' . $img($mark['url'], ShopInfo::brandName() . ' - Tradition Meets Elegance', $mark['width'], $mark['height'], 'margin:0 auto;max-width:100%;height:auto;') . '</a>'
            : '<a href="' . $e($site) . '" style="font-family:' . self::FONT . ';color:' . self::BURGUNDY . ';font-size:18px;font-weight:bold;letter-spacing:2px;text-decoration:none;">' . $img($logo, ShopInfo::brandName() . ' logo', 56, 56, 'margin:0 auto 8px;') . $e(strtoupper(ShopInfo::brandName())) . '</a>';
        $center = '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 12px;">';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::BURGUNDY . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::BURGUNDY . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:' . self::CREAM . ';border-radius:12px;border-collapse:separate;overflow:hidden;">'
            . '<tr><td align="center" style="padding:28px 24px 8px;">' . $head . '</td></tr>'
            . '<tr><td align="center" style="padding:8px 24px 20px;border-bottom:2px solid ' . self::GOLD . ';"><h1 style="margin:0;font-family:' . self::FONT . ';font-size:28px;line-height:1.25;color:' . self::BURGUNDY . ';">' . $e($title) . '</h1></td></tr>'
            . '<tr><td style="padding:28px 24px 12px;">' . $body . '</td></tr>'
            . '<tr><td align="center" style="padding:20px 24px;background:' . self::BAND . ';font-family:' . self::FONT . ';font-size:12px;line-height:1.6;color:#5a5248;">'
            . $center . '<tr><td>' . $img($logo, ShopInfo::brandName() . ' logo', 32, 32) . '</td></tr></table>'
            . $support . ($icons === '' ? '' : $center . '<tr>' . $icons . '</tr></table>')
            . '<p style="margin:0 0 12px;">' . $company . '</p><p style="margin:0;">' . $e($because) . '</p></td></tr>'
            . '</table></td></tr></table></body></html>';

        $lines = array_filter([ShopInfo::legalName(), ShopInfo::address(), ShopInfo::email() !== '' ? 'Support: ' . ShopInfo::email() : '']);
        return ['text' => implode("\n", $text) . "\n--\n" . implode("\n", $lines) . "\n\n" . $because, 'html' => $html];
    }
}
