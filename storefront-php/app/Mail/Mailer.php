<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

/** Sends one email. Implementations: SmtpMailer (real), LogMailer (development/test file), NullMailer (no mailer configured). */
interface Mailer
{
    /** False when nothing can be sent (routes then answer 503 / verificationEmailSent=false instead of pretending). */
    public function isAvailable(): bool;

    /** @throws MailException when the message could not be handed over */
    public function send(string $to, string $subject, string $text, ?string $html = null): void;
}
