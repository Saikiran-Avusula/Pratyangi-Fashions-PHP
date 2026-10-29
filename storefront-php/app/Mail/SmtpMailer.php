<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

use Pratyangi\Views\ShopInfo;

/**
 * SMTP through PHPMailer. The library is NOT part of this project's code: its files live in app/Lib/src/
 * (PHPMailer.php, SMTP.php, Exception.php). Without them send() fails with a clear message and the routes degrade
 * (signup reports verificationEmailSent=false).
 */
final class SmtpMailer implements Mailer
{
    private const LIB_DIR = __DIR__ . '/../Lib/src/';

    public function __construct(
        private string $host,
        private int $port,
        private string $secure, // tls (STARTTLS) | ssl (SMTPS) | none
        private ?string $user,
        private ?string $password,
        private string $from,
        private int $timeoutSeconds = 10,
        private ?\Closure $debugSink = null, // tools/send_test.php -debug: receives each line of the SMTP conversation (the caller masks the credentials)
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $this->loadLibrary();
        $class = '\\PHPMailer\\PHPMailer\\PHPMailer';
        try {
            /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
            $mail = new $class(true);
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->Timeout = $this->timeoutSeconds;
            $mail->SMTPDebug = $this->debugSink === null ? 0 : 2;
            if ($this->debugSink !== null) {
                $sink = $this->debugSink;
                $mail->Debugoutput = static function (string $line, int $level) use ($sink): void {
                    $sink($line);
                };
            }
            $mail->CharSet = 'UTF-8';
            if ($this->secure === 'ssl') {
                $mail->SMTPSecure = 'ssl'; // implicit TLS (PHPMailer::ENCRYPTION_SMTPS)
            } elseif ($this->secure === 'tls') {
                $mail->SMTPSecure = 'tls'; // STARTTLS (PHPMailer::ENCRYPTION_STARTTLS)
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            if ($this->user !== null && $this->user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->user;
                $mail->Password = $this->password ?? '';
            }
            [$fromName, $fromAddress] = self::splitFrom($this->from);
            $mail->setFrom($fromAddress, $fromName);
            if (ShopInfo::email() !== '') {
                $mail->addReplyTo(ShopInfo::email());
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            if ($html !== null) {
                // the pictures travel inside the message (cid:), so they show even from localhost or behind a password
                [$html, $images] = self::inlineImages($html, ShopInfo::siteUrl(), dirname(__DIR__, 2) . '/public');
                foreach ($images as $cid => $file) {
                    $mail->addEmbeddedImage($file, $cid, basename($file));
                }
                $mail->isHTML(true);
                $mail->Body = $html;
                $mail->AltBody = $text;
            } else {
                $mail->isHTML(false);
                $mail->Body = $text;
            }
            $mail->send();
        } catch (\Throwable $e) {
            // never include the library's text: it can echo server replies
            throw new MailException('The SMTP server did not accept the message (' . get_class($e) . ').');
        }
    }

    /**
     * Swaps each <img src="SITE/assets/images/..."> for a cid: address when that file exists under public/ (a path that climbs out is ignored).
     * @return array{0:string,1:array<string,string>} the new html and cid => file
     */
    public static function inlineImages(string $html, string $siteUrl, string $publicDir): array
    {
        $files = [];
        if ($siteUrl === '') {
            return [$html, $files];
        }
        $html = preg_replace_callback('~(<img\b[^>]*?\ssrc=")' . preg_quote($siteUrl, '~') . '(/assets/images/[A-Za-z0-9_./-]+)(")~', static function (array $m) use (&$files, $publicDir): string {
            $file = $publicDir . $m[2];
            if (str_contains($m[2], '..') || !is_file($file)) {
                return $m[0];
            }
            $cid = 'img' . substr(md5($m[2]), 0, 12) . '@pratyangi';
            $files[$cid] = $file;
            return $m[1] . 'cid:' . $cid . $m[3];
        }, $html) ?? $html;
        return [$html, $files];
    }

    /** "Name <address>" or a bare address. @return array{0:string,1:string} */
    public static function splitFrom(string $from): array
    {
        if (preg_match('/^\s*(.*?)\s*<([^<>]+)>\s*$/', $from, $m) === 1) {
            return [trim($m[1], " \t\"'"), trim($m[2])];
        }
        return ['', trim($from)];
    }

    private function loadLibrary(): void
    {
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
            $path = self::LIB_DIR . $file;
            if (!is_file($path)) {
                throw new MailException('PHPMailer is not installed: put PHPMailer.php, SMTP.php and Exception.php in app/Lib/src/ (MAIL_MODE=smtp needs it).');
            }
            require_once $path;
        }
    }
}
