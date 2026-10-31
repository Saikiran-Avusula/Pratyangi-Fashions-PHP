<?php
declare(strict_types=1);

// TODO: Re-verify every text against the STPL DLT approval report before SMS_ENABLED=true.

namespace Pratyangi\Sms;

/**
 * The DLT-submitted SMS templates (SMS2): the FULL final text of each, signature included, exactly as submitted ("\n" is a real newline).
 * Each template has its DLT template ID; an empty ID means "not approved yet": the gateway refuses to send it and never calls the provider.
 * Variables: {amount} whole rupees (no commas, no ₹), {datetime} "dd-mm-YYYY hh:mm AM/PM" IST, {date} "dd-mm-YYYY", {phone} 10 digits;
 * every value at most 30 characters. render() refuses anything else.
 */
final class SmsTemplates
{
    public const TEXTS = [
        'PF_OTP_V2' => "{v} is the OTP for your PRATYANGI FASHIONS account. Valid for 10 minutes. Do not share it with anyone.\n-PRATYANGI FASHIONS",
        'PF_WELCOME' => 'Welcome to PRATYANGI FASHIONS, {name}! Your account is ready. Shop handlooms at https://pratyangi.com -PRATYANGI FASHIONS',
        'PF_PWD_CHANGED' => 'Your PRATYANGI FASHIONS password was changed on {datetime}. If this was not you, contact us at {phone}. -PRATYANGI FASHIONS',
        'PF_ORDER_PLACED' => 'Thank you! Your PRATYANGI FASHIONS order {order} for Rs.{amount} is confirmed. Track: https://pratyangi.com/orders?{order} -PRATYANGI FASHIONS',
        'PF_PAY_FAILED' => 'Payment for your PRATYANGI FASHIONS order {order} of Rs.{amount} failed. Any amount debited will be refunded in 5-7 days. -PRATYANGI FASHIONS',
        'PF_SHIPPED' => 'Your PRATYANGI FASHIONS order {order} is shipped via {courier}, AWB {awb}. Track: https://pratyangi.com/orders?{order} -PRATYANGI FASHIONS',
        'PF_OUT_DELIVERY' => 'Your PRATYANGI FASHIONS order {order} is out for delivery today. Please keep Rs.{amount} ready if it is Cash on Delivery. -PRATYANGI FASHIONS',
        'PF_DELIVERED' => "Your PRATYANGI FASHIONS order {order} has been delivered. Thank you for shopping with us!\n-PRATYANGI FASHIONS",
        'PF_CANCEL_REFUND' => "Your PRATYANGI FASHIONS order {order} has been cancelled. Refund of Rs.{amount} will reach your account in 5-7 days.\n-PRATYANGI FASHIONS",
        'PF_CANCEL_NOPAY' => "Your PRATYANGI FASHIONS order {order} has been cancelled. No payment was taken.\n-PRATYANGI FASHIONS",
        'PF_REFUND' => "Refund of Rs.{amount} for your PRATYANGI FASHIONS order {order} has been processed. It will reach your account in 5-7 working days.\n-PRATYANGI FASHIONS",
        'PF_RETURN_RECEIVED' => "We have received return request {ret} for your PRATYANGI FASHIONS order {order}. We will update you within 48 hours.\n-PRATYANGI FASHIONS",
        'PF_RETURN_PICKUP' => "Return request {ret} for your PRATYANGI FASHIONS order {order} is approved. Pickup is scheduled on {date}.\n-PRATYANGI FASHIONS",
        'PF_RETURN_REJECTED' => "Return request {ret} for your PRATYANGI FASHIONS order {order} could not be approved. Please call {phone} for help.\n-PRATYANGI FASHIONS",
        'PF_COD_CONFIRM' => "Please confirm your PRATYANGI FASHIONS COD order {order} for Rs.{amount}. Reply or call {phone} to confirm.\n-PRATYANGI FASHIONS",
        'PF_INVOICE' => "Payment of Rs.{amount} received for your PRATYANGI FASHIONS order {order}. View your invoice: https://pratyangi.com/i?{code}\n-PRATYANGI FASHIONS",
    ];

    /** DLT template IDs, filled in once STPL approves each template. Empty = never sent. */
    public const IDS = [
        'PF_OTP_V2' => '', 'PF_WELCOME' => '', 'PF_PWD_CHANGED' => '', 'PF_ORDER_PLACED' => '', 'PF_PAY_FAILED' => '', 'PF_SHIPPED' => '',
        'PF_OUT_DELIVERY' => '', 'PF_DELIVERED' => '', 'PF_CANCEL_REFUND' => '', 'PF_CANCEL_NOPAY' => '', 'PF_REFUND' => '',
        'PF_RETURN_RECEIVED' => '', 'PF_RETURN_PICKUP' => '', 'PF_RETURN_REJECTED' => '', 'PF_COD_CONFIRM' => '', 'PF_INVOICE' => '',
    ];

    public const MAX_VAR = 30;

    /** The value rules per variable name; the others only have the 30-character limit (and no newline). */
    private const FORMATS = [
        'amount' => '/^\d+$/',
        'datetime' => '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2} (AM|PM)$/',
        'date' => '/^\d{2}-\d{2}-\d{4}$/',
        'phone' => '/^\d{10}$/',
    ];

    /**
     * The template's final text with the values in place.
     * @param array<string,string|int> $vars name => value (formatted with the helpers below)
     * @throws SmsException for an unknown template, a missing value, a value over 30 characters or in the wrong format (the message names the variable only)
     */
    public static function render(string $key, array $vars): string
    {
        if (!isset(self::TEXTS[$key])) {
            throw new SmsException('Unknown SMS template ' . $key . '.');
        }
        return (string) preg_replace_callback('/\{([a-z]+)\}/', static function (array $m) use ($key, $vars): string {
            if (!array_key_exists($m[1], $vars)) {
                throw new SmsException('SMS template ' . $key . ': no value for {' . $m[1] . '}.');
            }
            $value = (string) $vars[$m[1]];
            if (mb_strlen($value) > self::MAX_VAR || $value === '' || preg_match('/[\r\n]/', $value) === 1) {
                throw new SmsException('SMS template ' . $key . ': {' . $m[1] . '} is empty, longer than ' . self::MAX_VAR . ' characters or has a line break.');
            }
            if (isset(self::FORMATS[$m[1]]) && preg_match(self::FORMATS[$m[1]], $value) !== 1) {
                throw new SmsException('SMS template ' . $key . ': {' . $m[1] . '} is not in the agreed format.');
            }
            return $value;
        }, self::TEXTS[$key]);
    }

    /** 189999 paise -> "1900": whole rupees (rounded), no commas, no ₹. */
    public static function amount(int $paise): string
    {
        return (string) (int) round($paise / 100);
    }

    /** "31-10-2026 05:45 PM" in India time. */
    public static function datetime(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('d-m-Y h:i A');
    }

    /** "31-10-2026" in India time. */
    public static function date(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('d-m-Y');
    }

    /** "+91 99892 33328" -> "9989233328" (the last 10 digits). */
    public static function phone(string $number): string
    {
        return substr((string) preg_replace('/\D/', '', $number), -10);
    }
}
