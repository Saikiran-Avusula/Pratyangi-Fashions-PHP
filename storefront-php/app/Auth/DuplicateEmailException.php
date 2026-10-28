<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

/** The UNIQUE key on shop_customers.email refused an insert (two signups with the same address at once). */
final class DuplicateEmailException extends \RuntimeException
{
}
