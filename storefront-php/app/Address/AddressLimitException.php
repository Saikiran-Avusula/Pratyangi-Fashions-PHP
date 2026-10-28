<?php
declare(strict_types=1);

namespace Pratyangi\Address;

final class AddressLimitException extends \RuntimeException
{
    public function __construct(string $message = 'You can save up to 10 addresses. Please delete one to add another.')
    {
        parent::__construct($message);
    }
}
