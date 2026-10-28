<?php
declare(strict_types=1);

namespace Pratyangi\Address;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;

final class AddressController
{
    private const NOT_FOUND_MSG = 'We could not find that address. It may have been deleted.';

    public function __construct(private AddressRepo $repo)
    {
    }

    private function customerId(Context $context): int
    {
        $row = $context->customer();
        if ($row === null) {
            throw ApiException::unauthorized();
        }
        return (int) $row['id'];
    }

    private function parseAddressId(string $id): int
    {
        if (preg_match('/^\d{1,9}$/D', $id) !== 1) {
            throw ApiException::notFound(self::NOT_FOUND_MSG);
        }
        return (int) $id;
    }

    /**
     * GET /api/v1/addresses
     */
    public function list(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $addresses = $this->repo->listForCustomer($customerId);

        $dtos = array_map(static fn (Address $a): array => $a->toDto(), $addresses);
        return Response::json(['addresses' => $dtos]);
    }

    /**
     * POST /api/v1/addresses
     */
    public function create(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $body = $request->jsonBody();

        $validation = AddressValidator::validate($body);
        if ($validation['errors'] !== []) {
            throw ApiException::validation($validation['errors']);
        }

        try {
            $address = $this->repo->create($customerId, $validation['data']);
        } catch (AddressLimitException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return Response::json(['address' => $address->toDto()], 201);
    }

    /**
     * PUT /api/v1/addresses/{id}
     */
    public function update(Request $request, Context $context, string $id): Response
    {
        $customerId = $this->customerId($context);
        $addressId = $this->parseAddressId($id);
        $body = $request->jsonBody();

        $validation = AddressValidator::validate($body);
        if ($validation['errors'] !== []) {
            throw ApiException::validation($validation['errors']);
        }

        $address = $this->repo->update($customerId, $addressId, $validation['data']);
        if ($address === null) {
            throw ApiException::notFound(self::NOT_FOUND_MSG);
        }

        return Response::json(['address' => $address->toDto()]);
    }

    /**
     * DELETE /api/v1/addresses/{id}
     */
    public function delete(Request $request, Context $context, string $id): Response
    {
        $customerId = $this->customerId($context);
        $addressId = $this->parseAddressId($id);

        $deleted = $this->repo->delete($customerId, $addressId);
        if (!$deleted) {
            throw ApiException::notFound(self::NOT_FOUND_MSG);
        }

        return Response::noContent();
    }

    /**
     * POST /api/v1/addresses/{id}/default
     */
    public function setDefault(Request $request, Context $context, string $id): Response
    {
        $customerId = $this->customerId($context);
        $addressId = $this->parseAddressId($id);

        $addresses = $this->repo->setDefault($customerId, $addressId);
        if ($addresses === null) {
            throw ApiException::notFound(self::NOT_FOUND_MSG);
        }

        $dtos = array_map(static fn (Address $a): array => $a->toDto(), $addresses);
        return Response::json(['addresses' => $dtos]);
    }
}
