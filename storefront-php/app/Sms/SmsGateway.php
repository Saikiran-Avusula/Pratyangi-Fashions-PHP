<?php
declare(strict_types=1);

namespace Pratyangi\Sms;

/** Sends the one-time code to a customer's phone. Two modes: log (development and test) and http (the SMS provider). */
interface SmsGateway
{
    /**
     * @param string $mobile +91XXXXXXXXXX
     * @param string $code the six digits
     * @throws SmsException when the code could not be handed to the provider; the message never holds the code, the number or a key
     */
    public function sendOtp(string $mobile, string $code): void;
}
