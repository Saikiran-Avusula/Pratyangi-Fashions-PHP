<?php
declare(strict_types=1);

namespace Pratyangi\Sms;

use Pratyangi\Auth\Mobile;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Development and test only (Config refuses SMS_MODE=log in production): appends the code to a file so a developer can read it.
 * The file holds live codes, so it lives in the git-ignored storage folder and is never deployed. The number is masked.
 */
final class LogSmsGateway implements SmsGateway
{
    public function __construct(private string $path, private Clock $clock = new Clock())
    {
    }

    public function sendOtp(string $mobile, string $code): void
    {
        $dir = dirname($this->path);
        // @: a failure is reported as an SmsException (the PHP warning text would name the path)
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new SmsException('The SMS log folder could not be created.');
        }
        $line = Logger::formatTime($this->clock->now()) . ' to=' . Mobile::mask($mobile) . ' otp=' . $code . "\n";
        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new SmsException('The SMS log could not be written.');
        }
    }
}
