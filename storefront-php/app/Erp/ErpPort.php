<?php
declare(strict_types=1);

namespace Pratyangi\Erp;

/** What the website asks of the ERP in phase 1: the product list and stock. Implementations: ErpClient (http), MockErp (fixture), NotConnectedErp (off). */
interface ErpPort
{
    /**
     * Every product the ERP lists (the ERP only lists active ones). Rows that cannot be used are skipped and counted.
     * @param float|null $deadline unix time after which the call must fail with a timeout (the whole refresh is cut off then)
     * @return array{products:list<ErpProduct>,skipped:int}
     * @throws ErpException|ErpNotConfiguredException
     */
    public function listProducts(?float $deadline = null): array;

    /**
     * Pieces that can be promised right now, per SKU. SKUs the ERP does not mention are absent from the result.
     * @param list<string> $skus
     * @return array<string,int>
     * @throws ErpException|ErpNotConfiguredException
     */
    public function getAvailability(array $skus, ?float $deadline = null): array;

    /**
     * Creates (or finds, for a repeated order number) the ERP order and reserves the stock.
     * @param array{orderNo:string,customerName:string,customerMobile:string,shippingAddress:string,items:list<array{sku:string,quantity:int}>,orderAmountPaise:int,paymentStatus:string} $order
     * @return array{erpOrderId:int,erpOrderNo:string,status:string,duplicate:bool}
     * @throws ErpOrderRejectedException the ERP said no
     * @throws ErpException the request may or may not have reached the ERP (outcome unknown)
     * @throws ErpNotConfiguredException
     */
    public function createOrder(array $order): array;
}
