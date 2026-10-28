<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Development and test only (Config refuses MAIL_MODE=log in production): appends each message to a file so a developer can
 * read the verification link or reset code. The file holds links and codes, so it is git-ignored and never deployed.
 */
final class LogMailer implements Mailer
{
    public function __construct(private string $path, private Clock $clock = new Clock())
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new MailException('The mail log folder could not be created.');
        }
        $block = '==== ' . Logger::formatTime($this->clock->now()) . " ====\nTo: " . $to . "\nSubject: " . $subject . "\n\n" . $text . "\n\n";
        if (file_put_contents($this->path, $block, FILE_APPEND | LOCK_EX) === false) {
            throw new MailException('The mail log could not be written.');
        }
    }
}
