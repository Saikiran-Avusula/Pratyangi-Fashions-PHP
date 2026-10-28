<?php
declare(strict_types=1);

namespace Pratyangi\Orders;

/** The generated order number was already used (about one in a trillion); the caller makes a new one. */
final class OrderNumberTakenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('order number already used');
    }
}
