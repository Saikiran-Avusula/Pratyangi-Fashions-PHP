<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

/** No mailer is configured. Nothing is pretended: callers see isAvailable() === false. */
final class NullMailer implements Mailer
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        throw new MailException('No mailer is configured.');
    }
}
