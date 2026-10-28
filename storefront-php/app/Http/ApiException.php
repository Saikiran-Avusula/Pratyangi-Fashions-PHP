<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** A failure the customer is told about. The error body is built in body() and nowhere else. */
final class ApiException extends \RuntimeException
{
    public const GENERIC_500 = 'Something went wrong on our side. Please try again in a moment.';

    /**
     * @param array<string,string>|null $errors one message per field
     * @param list<string>|null $skus
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $field = null,
        public readonly ?array $errors = null,
        public readonly ?string $errorCode = null,
        public readonly ?array $skus = null,
        public readonly ?bool $retrySafe = null,
    ) {
        parent::__construct($message);
    }

    /** @return array<string,mixed> key order: message, field, errors, code, skus, retrySafe */
    public function body(): array
    {
        $body = ['message' => $this->getMessage()];
        if ($this->field !== null) {
            $body['field'] = $this->field;
        }
        if ($this->errors !== null) {
            $body['errors'] = $this->errors;
        }
        if ($this->errorCode !== null) {
            $body['code'] = $this->errorCode;
        }
        if ($this->skus !== null && $this->skus !== []) {
            $body['skus'] = $this->skus;
        }
        if ($this->retrySafe === true) {
            $body['retrySafe'] = true;
        }
        return $body;
    }

    public static function badRequest(string $message, ?string $field = null): self
    {
        return new self(400, $message, $field);
    }

    public static function unauthorized(string $message = 'Please sign in to continue.'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = 'That page does not exist.'): self
    {
        return new self(404, $message);
    }

    public static function conflict(string $message, ?string $field = null): self
    {
        return new self(409, $message, $field);
    }

    public static function unavailable(string $message): self
    {
        return new self(503, $message);
    }

    /** @param array<string,string> $errors */
    public static function validation(array $errors): self
    {
        $field = (string) array_key_first($errors);
        return new self(422, $errors[$field] ?? 'Please check the details and try again.', $field, $errors);
    }
}
