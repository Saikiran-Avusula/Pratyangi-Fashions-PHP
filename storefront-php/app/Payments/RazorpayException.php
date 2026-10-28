<?php
declare(strict_types=1);

namespace Pratyangi\Payments;

/** Razorpay could not be used. The message is fixed text: never a key, and never Razorpay's own words. */
final class RazorpayException extends \RuntimeException
{
    /** @param 'unreachable'|'timeout'|'auth'|'rejected'|'bad-response' $kind */
    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
