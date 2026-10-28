<?php
declare(strict_types=1);

namespace Pratyangi\Catalog;

use Pratyangi\Auth\Validator;
use Pratyangi\Http\Context;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;

/** GET /catalog/products (public, read-only). */
final class CatalogController
{
    public function __construct(private CatalogService $service)
    {
    }

    public function products(Request $request, Context $context): Response
    {
        $result = $this->service->list(Validator::catalogQuery($request->query));
        // a browser may keep the answer only for what is left of our own cache time; old or uncached data is never stored
        return Response::json($result['page'])->setHeader('Cache-Control', $result['maxAge'] > 0 ? 'public, max-age=' . $result['maxAge'] : 'no-store');
    }
}
