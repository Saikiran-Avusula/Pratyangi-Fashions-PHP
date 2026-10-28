<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/**
 * The ERP could not be used: unreachable, too slow, refused our key, switched off, or answered in a format we do not understand.
 * The message is safe to log: it never holds the API key or the ERP's raw answer. It is not written for customers.
 */
final class ErpException extends \RuntimeException
{
    /** @param 'unreachable'|'timeout'|'auth'|'disabled'|'unavailable'|'bad-response' $kind */
    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
