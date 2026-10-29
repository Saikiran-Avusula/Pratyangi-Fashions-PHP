<?php
declare(strict_types=1);

namespace Pratyangi\Auth;

use Pratyangi\Http\ApiException;
use Pratyangi\Http\Context;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;

/** PHP-only (needs SMS_ENABLED=true): POST /api/v1/numbers/otp/request and /verify, a code to a delivery number. Body: {phone} and {phone, code}. */
final class NumberController
{
    public function __construct(private NumberVerification $numbers)
    {
    }

    public function request(Request $request, Context $context): Response
    {
        $input = Validator::otpRequest(self::asMobile($request->jsonBody()));
        return Response::json($this->numbers->request($this->customerId($context), $input['mobile'], $context->clientIp(), $context));
    }

    public function verify(Request $request, Context $context): Response
    {
        $input = Validator::mobileCode(self::asMobile($request->jsonBody()));
        $this->numbers->verify($this->customerId($context), $input['mobile'], $input['code']);
        return Response::json(['verified' => true]);
    }

    /** The field is called phone in the address book and mobile in the older code routes. */
    private static function asMobile(array $body): array
    {
        if (isset($body['phone']) && !isset($body['mobile'])) {
            $body['mobile'] = $body['phone'];
            unset($body['phone']);
        }
        return $body;
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
