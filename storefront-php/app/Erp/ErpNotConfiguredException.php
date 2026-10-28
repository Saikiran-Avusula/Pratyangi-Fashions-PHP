<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/** ERP_MODE=off: there is no ERP to ask. The catalogue answers 503 "not available yet". */
final class ErpNotConfiguredException extends \RuntimeException
{
}
