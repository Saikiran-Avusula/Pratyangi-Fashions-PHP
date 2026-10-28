<?php
declare(strict_types=1);

// No database. Run: php tests/smtp_mail_test.php
// SmtpMailer against a local fake SMTP server (plain text, no TLS: the SSL 465 handshake needs a real certificate, so it is not covered),
// the failure path, and the stage C setting names (aliases, log defaults, SSL 465 default).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Mail\MailException;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Mail\SmtpMailer;

$t = new TestCase('smtp_mail_test');
$dir = Helpers::tempDir('pf_fakesmtp_');
$freePort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
};
$port = $freePort();
$proc = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp/server.php', (string) $port, $dir], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']], $pipes);
register_shutdown_function(static function () use ($proc, $dir): void {
    proc_terminate($proc);
    proc_close($proc);
    Helpers::rmTree($dir);
});
for ($i = 0; $i < 100 && !is_file($dir . '/ready'); $i++) {
    usleep(50000);
}
$messages = static function () use ($dir): array {
    $f = $dir . '/messages.log';
    return is_file($f) ? array_map(static fn (string $l): array => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
};

$t->test('a code mail is delivered with the login, sender, recipient, subject and the code', function () use ($t, $port, $messages): void {
    $mail = MailTemplates::code('email_verify', '482913', 10);
    (new SmtpMailer('127.0.0.1', $port, 'none', 'shop@example.test', 'pw', 'Pratyangi Fashions <no-reply@example.test>', 5))->send('asha@example.test', $mail['subject'], $mail['text']);
    $got = $messages();
    $t->same(1, count($got));
    $t->same('shop@example.test', $got[0]['user']);
    $t->contains('no-reply@example.test', $got[0]['from']);
    $t->contains('asha@example.test', $got[0]['to'][0]);
    $t->contains('482913', $got[0]['data']);
    $t->contains('Subject: ' . $mail['subject'], $got[0]['data']);
});

$t->test('the server refusing the recipient, or not being there, is a MailException with none of the server words', function () use ($t, $port, $dir, $freePort): void {
    file_put_contents($dir . '/reject.flag', '1');
    $e = $t->throws(MailException::class, static fn () => (new SmtpMailer('127.0.0.1', $port, 'none', 'u', 'pw', 'a@example.test', 5))->send('x@example.test', 's', 't'));
    $t->ok(!str_contains($e->getMessage(), '550') && !str_contains($e->getMessage(), 'no such user'));
    unlink($dir . '/reject.flag');
    $closed = $freePort();
    $t->throws(MailException::class, static fn () => (new SmtpMailer('127.0.0.1', $closed, 'none', null, null, 'a@example.test', 2))->send('x@example.test', 's', 't'));
});

$t->test('settings: the stage C names work, log is the default outside production, SSL 465 is the default port', function () use ($t): void {
    $c = Helpers::config(['MAIL_MODE' => '', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_USER' => 'u', 'MAIL_PASS' => 'p']);
    $t->same(['log', 'smtp.example.test', 465, 'ssl', 'u', 'p'], [$c->mailMode, $c->smtpHost, $c->smtpPort, $c->smtpSecure, $c->smtpUser, $c->smtpPassword()]);
    $c = Helpers::config(['MAIL_MODE' => 'smtp', 'MAIL_PORT' => '587', 'SMTP_HOST' => 'a.example.test', 'MAIL_HOST' => 'ignored.example.test']);
    $t->same(['a.example.test', 587, 'tls'], [$c->smtpHost, $c->smtpPort, $c->smtpSecure], 'the original name wins; 587 means STARTTLS');
    $c = Helpers::config(['SMS_MODE' => 'http', 'SMS_API_URL' => 'http://127.0.0.1:9/send', 'SMS_USERNAME' => 'u', 'SMS_API_KEY' => 'k', 'SMS_SENDER_ID' => 'PRATYA', 'SMS_TEMPLATE_ID' => '1707000000000000001']);
    $t->same(['k', '1707000000000000001'], [$c->smsApiKey(), $c->smsOtpTemplateId]);
    $t->same('log', Helpers::config()->smsMode, 'SMS_MODE defaults to log outside production');
    $t->same(false, Helpers::config(['SMS_ENABLED' => ''])->smsEnabled, 'SMS_ENABLED defaults to false');
    try {
        new \Pratyangi\Config(['APP_ENV' => 'production', 'DB_NAME' => 'x', 'DB_USER' => 'x']);
        $message = '';
    } catch (\Pratyangi\ConfigException $e) {
        $message = $e->getMessage();
    }
    $t->ok(!str_contains($message, 'SMS_'), 'production with SMS_ENABLED=false needs no SMS settings');
    $t->contains('SMS_API_URL', (function () { try { new \Pratyangi\Config(['APP_ENV' => 'production', 'DB_NAME' => 'x', 'DB_USER' => 'x', 'SMS_ENABLED' => 'true']); } catch (\Pratyangi\ConfigException $e) { return $e->getMessage(); } return ''; })(), 'with SMS_ENABLED=true they are required');
});

exit($t->finish());
