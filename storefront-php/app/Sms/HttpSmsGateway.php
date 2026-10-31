<?php
declare(strict_types=1);

namespace Pratyangi\Sms;

/**
 * The SMS provider over HTTP. The request is the one the provider's GET API takes (see docs/PHASE1_NOTES.md): the address comes from the
 * SMS_API_URL setting only, the query carries username, apikey, senderid, mobile (91 + 10 digits), message and templateid.
 * The key travels in the query string, so nothing here ever puts the URL, the key, the number or the code in an error text or a log.
 */
final class HttpSmsGateway implements SmsGateway, SmsHintGateway
{
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private string $apiUrl,
        private string $username,
        private string $apiKey,
        private string $senderId,
        private int $timeoutSeconds = 10,
        private ?string $caBundle = null,
        private ?string $hintTemplateId = null, // the approved template of the email hint; none = no hint is sent
        private string $hintTemplateText = 'Your Pratyangi Fashions account email is {#var#}',
        private array $templateIds = SmsTemplates::IDS, // SMS2: the DLT template IDs; tests pass their own
    ) {
    }

    /** SMS2: the code goes out in the DLT template PF_OTP_V2 (its text and ID live in SmsTemplates). */
    public function sendOtp(string $mobile, string $code): void
    {
        $this->send($mobile, 'PF_OTP_V2', ['v' => $code]);
    }

    /**
     * One DLT template with its values. A template without an approved DLT ID is refused here and the provider is never called
     * (the caller logs the SmsException; its message names the template, never the number or a value).
     * @param array<string,string|int> $vars
     */
    public function send(string $mobile, string $key, array $vars): void
    {
        $id = (string) ($this->templateIds[$key] ?? '');
        $text = SmsTemplates::render($key, $vars); // refuses a missing / too long / wrongly formatted value
        if ($id === '') {
            throw new SmsException('SMS template ' . $key . ' has no DLT template ID yet: not sent.');
        }
        $this->submit($mobile, $text, $id);
    }

    public function sendEmailHint(string $mobile, string $maskedEmail): void
    {
        if ($this->hintTemplateId === null || $this->hintTemplateId === '') {
            throw new SmsException('The email hint SMS template is not set up.');
        }
        $this->submit($mobile, str_replace('{#var#}', $maskedEmail, $this->hintTemplateText), $this->hintTemplateId);
    }

    private function submit(string $mobile, string $message, string $templateId): void
    {
        $query = http_build_query([
            'username' => $this->username,
            'apikey' => $this->apiKey,
            'senderid' => $this->senderId,
            'mobile' => '91' . substr($mobile, -10),
            'message' => $message,
            'templateid' => $templateId,
        ]);
        $url = $this->apiUrl . (str_contains($this->apiUrl, '?') ? '&' : '?') . $query;

        $buffer = '';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new SmsException('The SMS request could not be prepared.');
        }
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$buffer): int {
                if (strlen($buffer) + strlen($data) > self::MAX_BODY_BYTES) {
                    return 0; // abort: far larger than any provider answer
                }
                $buffer .= $data;
                return strlen($data);
            },
        ];
        if ($this->caBundle !== null && $this->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        if ($ok === false) {
            throw new SmsException('The SMS provider could not be reached.');
        }
        if ($status < 200 || $status >= 300) {
            throw new SmsException('The SMS provider answered with HTTP ' . $status . '.');
        }
        self::assertAccepted($buffer);
    }

    /** The provider answers 200 even for a refusal: a JSON body with "Reports" is a send, one with "Error" is a refusal, an empty body is a failure. */
    public static function assertAccepted(string $body): void
    {
        $raw = trim($body);
        if ($raw === '') {
            throw new SmsException('The SMS provider sent an empty answer.');
        }
        $json = json_decode(str_replace("'", '"', $raw), true);
        if (is_array($json)) {
            if (isset($json['Reports']) || isset($json['reports'])) {
                return;
            }
            $error = (string) ($json['Error'] ?? $json['error'] ?? $json['ERROR'] ?? '');
            if ($error !== '') {
                throw new SmsException('The SMS provider refused the message.');
            }
        }
        // only a positive signal counts as sent: an unrecognised answer ("Authentication failed", "Insufficient balance") is a failure
        if (preg_match('/\b(invalid|not matched|reject)/i', $raw) === 1 || preg_match('/\b(ok|success|sent|queued|submitted|reports)\b/i', $raw) !== 1) {
            throw new SmsException('The SMS provider did not confirm the message.');
        }
    }
}
