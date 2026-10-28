<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

/** The UNIQUE key on shop_customers.mobile refused an insert (two first-time sign-ins with the same number at once). */
final class DuplicateMobileException extends \RuntimeException
{
}
