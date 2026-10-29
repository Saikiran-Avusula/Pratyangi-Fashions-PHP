<?php
declare(strict_types=1);

namespace Pratyangi\Sms;

/** A gateway that can also text a customer a masked hint of the email on their account ("Forgot email or mobile?"). Optional: a gateway without it sends no hint. */
interface SmsHintGateway
{
    /**
     * @param string $mobile +91XXXXXXXXXX
     * @param string $maskedEmail already masked (j*****in@gmail.com)
     * @throws SmsException when the SMS could not be handed to the provider; the message never holds the number or a key
     */
    public function sendEmailHint(string $mobile, string $maskedEmail): void;
}
