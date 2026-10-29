<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;

/** PHP-only: GET and PUT /api/v1/consent, the signed-in customer's offers-and-new-arrivals choice per channel (docs/API_CONTRACT.md 11.7). */
final class ConsentController
{
    public function __construct(private ConsentRepo $repo)
    {
    }

    public function show(Request $request, Context $context): Response
    {
        return Response::json(['consent' => $this->repo->current($this->customerId($context))]);
    }

    /** Body: any of sms, whatsapp, email as true/false, and source (signup | address | checkout | account; default account). */
    public function update(Request $request, Context $context): Response
    {
        $customerId = $this->customerId($context);
        $body = $request->jsonBody();
        $source = $body['source'] ?? 'account';
        if (!is_string($source) || !in_array($source, ['signup', 'address', 'checkout', 'account'], true)) {
            throw ApiException::validation(['source' => 'Unknown page.']);
        }
        $changes = [];
        $errors = [];
        foreach (ConsentRepo::CHANNELS as $channel) {
            if (!array_key_exists($channel, $body)) {
                continue;
            }
            if (!is_bool($body[$channel])) {
                $errors[$channel] = 'Must be true or false.';
            } else {
                $changes[$channel] = $body[$channel];
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return Response::json(['consent' => $this->repo->set($customerId, $changes, $source, $context->clientIp())]);
    }

    private function customerId(Context $context): int
    {
        $row = $context->customer();
        if ($row === null) {
            throw ApiException::unauthorized();
        }
        return (int) $row['id'];
    }
}
