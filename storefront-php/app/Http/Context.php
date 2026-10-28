<?php
declare(strict_types=1);

namespace Pratyangi\Http;

/** Per-request state shared by the router, the limiter and the handlers. */
final class Context
{
    /** @var array<string,string> extra response headers (rate limit), added to whatever response goes out, errors included */
    public array $headers = [];
    /** @var array<string,mixed>|null the signed-in customer row */
    private ?array $customer = null;
    private bool $customerLoaded = false;

    /** @param \Closure(Request):?array<string,mixed> $sessionLookup */
    public function __construct(
        public readonly Request $request,
        public readonly string $requestId,
        public readonly int $trustProxy,
        private \Closure $sessionLookup,
    ) {
    }

    /** The signed-in customer's database row, or null. Looked up once, only when a route asks. */
    public function customer(): ?array
    {
        if (!$this->customerLoaded) {
            $this->customerLoaded = true;
            $this->customer = ($this->sessionLookup)($this->request);
        }
        return $this->customer;
    }

    /**
     * Runs a second limit or send that must not replace the route's own RateLimit-* headers: it gets a copy with its own headers.
     * When it refuses (throws), its headers describe the limit that refused, so they are added to this context before the error goes on.
     * @param \Closure(Context):mixed $work
     */
    public function secondary(\Closure $work): mixed
    {
        $scratch = clone $this;
        $scratch->headers = [];
        try {
            return $work($scratch);
        } catch (ApiException $e) {
            $this->headers = $scratch->headers + $this->headers;
            throw $e;
        }
    }

    public function clientIp(): string
    {
        return $this->request->clientIp($this->trustProxy);
    }
}
