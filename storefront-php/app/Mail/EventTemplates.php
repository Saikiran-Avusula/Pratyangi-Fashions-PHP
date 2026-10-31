<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

use Pratyangi\Views\ShopInfo;

/**
 * The wording of the account and order emails. Each method returns subject + text + html; the look is MailTemplates::layout() (the one layout).
 * Order data is a plain array so the caller decides where it comes from:
 *   orderNo, name, and per email: items [[name, qty, unitPaise]], itemsTotalPaise, taxPaise, totalPaise, address (confirmed);
 *   amountPaise, method, invoiceCode (receipt); courier, awb, trackingUrl (shipped / out for delivery); refundPaise (cancelled, refund); note (return).
 */
final class EventTemplates
{
    /** @return array{subject:string,text:string,html:string} */
    public static function welcome(string $name): array
    {
        $brand = ShopInfo::brandName();
        return self::make("Welcome to {$brand}", 'Welcome', [
            "Thank you for creating your {$brand} account. Handcrafted silk sarees are waiting for you.",
            'You can save addresses, check out faster and see your orders in one place.',
        ], ['label' => 'Start shopping', 'url' => ShopInfo::siteUrl() . '/'], $name);
    }

    /** @return array{subject:string,text:string,html:string} */
    public static function passwordChanged(string $name, string $when): array
    {
        return self::make('Your ' . ShopInfo::brandName() . ' password was changed', 'Password changed', [
            "The password of your account was changed on {$when}.",
            self::notYou(),
        ], null, $name);
    }

    /** To the old address (role "old") and to the new one (role "new"). @return array{subject:string,text:string,html:string} */
    public static function emailChanged(string $name, string $role, string $oldEmail, string $newEmail): array
    {
        $lead = $role === 'old'
            ? "The email address of your account was changed from {$oldEmail} to {$newEmail}. We will no longer write to this address."
            : "{$newEmail} is now the email address of your account (it was {$oldEmail}).";
        return self::make('Your ' . ShopInfo::brandName() . ' email address was changed', 'Email address changed', [$lead, self::notYou()], null, $name);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function orderConfirmed(array $o): array
    {
        $rows = [['Item', 'Qty', 'Price']];
        foreach ($o['items'] as [$item, $qty, $unit]) {
            $rows[] = [(string) $item, 'x ' . $qty, self::inr((int) $unit * (int) $qty)];
        }
        return self::order($o, 'is confirmed', 'Order confirmed', [
            'Thank you! We have received your order ' . $o['orderNo'] . ' and are getting it ready.',
            ['rows' => $rows, 'head' => true],
            ['rows' => [['Items', self::inr($o['itemsTotalPaise'])], ['Tax included', self::inr($o['taxPaise'])], ['Total', self::inr($o['totalPaise'])]], 'strong' => true],
            'Delivering to: ' . $o['address'],
        ], self::orderButton());
    }

    /** The link is /i/<code>: the code is random (12+ characters) and made by the caller. @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function paymentReceipt(array $o): array
    {
        return self::order($o, 'payment received', 'Payment received', [
            'We received your payment for order ' . $o['orderNo'] . '.',
            ['rows' => [['Amount paid', self::inr($o['amountPaise'])], ['Payment method', $o['method']]]],
            'This is not a GST tax invoice. The tax invoice is issued at dispatch.',
        ], ['label' => 'View receipt', 'url' => ShopInfo::siteUrl() . '/account/orders'], 'Payment received for your ' . ShopInfo::brandName() . ' order ' . $o['orderNo']);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function paymentFailed(array $o): array
    {
        return self::order($o, 'payment failed', 'Payment failed', [
            'Your payment for order ' . $o['orderNo'] . ' did not go through. If money was taken from your account, your bank returns it within a few days.',
            'You can try again from your cart.',
        ], ['label' => 'Back to your cart', 'url' => ShopInfo::siteUrl() . '/bag'], 'Payment failed for your ' . ShopInfo::brandName() . ' order ' . $o['orderNo']);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function shipped(array $o): array
    {
        return self::order($o, 'has shipped', 'Your order has shipped', [
            'Good news, order ' . $o['orderNo'] . ' is on its way.',
            ['rows' => [['Courier', $o['courier']], ['AWB number', $o['awb']]]],
        ], ['label' => 'Track your order', 'url' => $o['trackingUrl']]);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function outForDelivery(array $o): array
    {
        return self::order($o, 'is out for delivery', 'Out for delivery', [
            'Order ' . $o['orderNo'] . ' is out for delivery today. Please keep your phone with you.',
            ['rows' => [['Courier', $o['courier']], ['AWB number', $o['awb']]]],
        ], ['label' => 'Track your order', 'url' => $o['trackingUrl']]);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function delivered(array $o): array
    {
        return self::order($o, 'was delivered', 'Delivered', [
            'Order ' . $o['orderNo'] . ' was delivered. We hope you love it.',
            'If anything is not right, write to us and we will help.',
        ], self::orderButton());
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function cancelled(array $o): array
    {
        $p = ['Order ' . $o['orderNo'] . ' was cancelled.'];
        if (($o['refundPaise'] ?? 0) > 0) {
            $p[] = 'Your refund of ' . self::inr((int) $o['refundPaise']) . ' has been started. It reaches your original payment method in 5 to 7 working days.';
        }
        return self::order($o, 'was cancelled', 'Order cancelled', $p, null);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function refundProcessed(array $o): array
    {
        return self::order($o, 'refund processed', 'Refund processed', [
            'We have refunded ' . self::inr((int) $o['refundPaise']) . ' for order ' . $o['orderNo'] . ' to your original payment method.',
            'Your bank may take 5 to 7 working days to show it.',
        ], null, 'Refund processed for your ' . ShopInfo::brandName() . ' order ' . $o['orderNo']);
    }

    /** @param array<string,mixed> $o @return array{subject:string,text:string,html:string} */
    public static function returnUpdate(array $o): array
    {
        return self::order($o, 'return update', 'Return update', [
            'Here is the latest on the return for order ' . $o['orderNo'] . ':',
            $o['note'],
        ], null, 'Update on your return for ' . ShopInfo::brandName() . ' order ' . $o['orderNo']);
    }

    /** 199900 -> "₹1,999": the shared price format (View::paise). */
    public static function inr(int $paise): string
    {
        return \Pratyangi\Views\View::paise($paise);
    }

    private static function notYou(): string
    {
        return ShopInfo::email() === '' ? 'Not you? Please contact us at once.' : 'Not you? Contact ' . ShopInfo::email() . '.';
    }

    /** @return array{label:string,url:string} */
    private static function orderButton(): array
    {
        return ['label' => 'View your order', 'url' => ShopInfo::siteUrl() . '/account/orders'];
    }

    /** @param array<string,mixed> $o @param list<mixed> $paragraphs @param array{label:string,url:string}|null $button @return array{subject:string,text:string,html:string} */
    private static function order(array $o, string $what, string $title, array $paragraphs, ?array $button, ?string $subject = null): array
    {
        return self::make($subject ?? 'Your ' . ShopInfo::brandName() . ' order ' . $o['orderNo'] . ' ' . $what, $title, $paragraphs, $button, (string) $o['name']);
    }

    /** @param list<mixed> $paragraphs @param array{label:string,url:string}|null $button @return array{subject:string,text:string,html:string} */
    private static function make(string $subject, string $title, array $paragraphs, ?array $button, ?string $name): array
    {
        return ['subject' => $subject] + MailTemplates::layout($title, $paragraphs, $button, $name);
    }
}
