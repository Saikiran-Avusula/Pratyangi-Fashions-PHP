<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

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
            $mail->addAddress($to);
            $mail->Subject = $subject;
            if ($html !== null) {
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
