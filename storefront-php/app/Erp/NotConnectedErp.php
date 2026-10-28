<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/** ERP_MODE=off: every call fails loudly instead of returning invented data. */
final class NotConnectedErp implements ErpPort
{
    public function listProducts(?float $deadline = null): array
    {
        throw new ErpNotConfiguredException('catalogue');
    }

    public function getAvailability(array $skus, ?float $deadline = null): array
    {
        throw new ErpNotConfiguredException('stock');
    }

    public function createOrder(array $order): array
    {
        throw new ErpNotConfiguredException("order");
    }
}
