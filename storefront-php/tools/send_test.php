<?php
declare(strict_types=1);

// Sends ONE real SMS and ONE real email with the settings of config.local.php (SMS_MODE=http and MAIL_MODE=smtp are required).
// Run it yourself:  php tools/send_test.php            (asks for the number and the address; with SMS_MODE=log it prints "sms skipped" and asks only for the address)
//                   php tools/send_test.php 98xxxxxxxx you@example.com
//                   add -debug (anywhere) to print the SMTP conversation; the login lines and the password are masked
// Prints only "SMS: sent" / "SMS: failed - <reason>" and the same for the email. The code in the messages is random and never printed;
// reasons are fixed texts that hold no key, number or server reply. Press Enter at a question to skip that one.
require dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\Auth\Mobile;
use Pratyangi\Config;
use Pratyangi\ConfigException;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Mail\SmtpMailer;
use Pratyangi\Sms\HttpSmsGateway;

try {
    $config = Config::load();
} catch (ConfigException $e) {
    echo 'failed - ' . $e->getMessage() . "\n";
    exit(1);
}
$debug = in_array('-debug', $argv, true); // -debug: print the SMTP conversation with the login lines masked
$argv = array_values(array_diff($argv, ['-debug']));
$secrets = array_filter([$config->smtpUser, $config->smtpPassword()], static fn (?string $v): bool => $v !== null && $v !== '');
$maskNext = false;
$sink = $debug ? static function (string $line) use ($secrets, &$maskNext): void {
    $line = rtrim($line);
    if (preg_match('/^(.*CLIENT -> SERVER: )(AUTH (?:PLAIN|LOGIN|CRAM-MD5|XOAUTH2)) \S+$/', $line, $m) === 1) {
        $line = $m[1] . $m[2] . ' ***';
    } elseif ($maskNext && str_contains($line, 'CLIENT -> SERVER: ')) {
        $line = substr($line, 0, (int) strpos($line, 'CLIENT -> SERVER: ') + 18) . '***';
    }
    $maskNext = str_contains($line, 'SERVER -> CLIENT: 334');
    foreach ($secrets as $secret) {
        foreach ([$secret, base64_encode($secret), bin2hex($secret)] as $form) {
            $line = str_replace($form, '***', $line);
        }
    }
    echo $line . "\n";
} : null;
$ask = static function (string $question, int $position): string {
    global $argv;
    if (isset($argv[$position])) {
        return trim($argv[$position]);
    }
    echo $question;
    return trim((string) fgets(STDIN));
};
$code = (string) random_int(100000, 999999);

$smsOff = $config->smsMode !== 'http'; // SMS_MODE=log: no real SMS can be sent, so ask only for the email address
$number = $smsOff ? '' : $ask('Mobile number for ONE real SMS (10 digits, Enter to skip): ', 1);
if ($smsOff) {
    echo "sms skipped\n";
} elseif ($number === '') {
    echo "SMS: skipped\n";
} elseif (($mobile = Mobile::normalise($number)) === null) {
    echo "SMS: failed - not a valid Indian mobile number\n";
} elseif ($config->smsMode !== 'http') {
    echo "SMS: failed - SMS_MODE is not http, nothing real was sent\n";
} else {
    try {
        (new HttpSmsGateway((string) $config->smsApiUrl, (string) $config->smsUsername, (string) $config->smsApiKey(), (string) $config->smsSenderId, (string) $config->smsOtpTemplateId, $config->smsOtpTemplateText))->sendOtp($mobile, $code);
        echo "SMS: sent\n";
    } catch (\Throwable $e) {
        echo 'SMS: failed - ' . $e->getMessage() . "\n";
    }
}

$address = $ask('Email address for ONE real email (Enter to skip): ', $smsOff ? 1 : 2);
if ($address === '') {
    echo "Email: skipped\n";
} elseif (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
    echo "Email: failed - not a valid email address\n";
} elseif ($config->mailMode !== 'smtp' || $config->smtpHost === null) {
    echo "Email: failed - MAIL_MODE is not smtp or MAIL_HOST is missing, nothing real was sent\n";
} else {
    try {
        $mail = MailTemplates::code('email_verify', $code, 10);
        (new SmtpMailer($config->smtpHost, $config->smtpPort, $config->smtpSecure, $config->smtpUser, $config->smtpPassword(), $config->mailFrom, 10, $sink))->send($address, $mail['subject'], $mail['text']);
        echo "Email: sent\n";
    } catch (\Throwable $e) {
        echo 'Email: failed - ' . $e->getMessage() . "\n";
    }
}
