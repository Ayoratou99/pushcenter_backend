<?php

namespace App\Services\AyosPush;

use RuntimeException;
use Throwable;

/**
 * An AyosPush call that did not give the expected answer.
 *
 * $status is the HTTP status AyosPush replied with, 0 when it could not be
 * reached at all.
 */
class AyosPushException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        public readonly ?string $requiredScope = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Worth trying again later: network trouble, throttling, server errors.
     */
    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /**
     * AyosPush's API rate limit (5 requests/s and 1000/h per account): wait,
     * nothing is wrong with the request itself.
     */
    public function isRateLimit(): bool
    {
        return $this->status === 429 && ! $this->isMessagingTierLimit();
    }

    /**
     * Meta's messaging tier of the sender number is used up (unique
     * conversations per 24 h). Waiting a few minutes does not help.
     */
    public function isMessagingTierLimit(): bool
    {
        return $this->status === 429 && isset($this->errors['messaging_tier']);
    }

    /**
     * Whether AyosPush may have received (and queued) the request although no
     * usable answer came back: a read timeout, or a gateway timeout. Sending
     * again could then reach the recipient twice.
     */
    public function mayHaveReachedAyosPush(): bool
    {
        if ($this->status === 504) {
            return true;
        }

        if ($this->status !== 0) {
            return false;
        }

        // Refused, unresolvable or connect timeout: the request never left.
        return ! preg_match('/cURL error (6|7)\b|Could not resolve host|Failed to connect|Connection refused|Connection timed out/i', $this->getMessage());
    }

    /**
     * Status to answer the management console with. AyosPush's 401/403 are
     * about the application's AyosPush key, not about the signed-in user, so
     * they must not reach the console as 401 (which would end the session).
     */
    public function consoleStatus(): int
    {
        return match (true) {
            $this->status === 429 => 429,
            $this->status === 0, $this->status >= 500 => 502,
            default => 422,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'provider' => 'ayospush',
            'provider_status' => $this->status,
            'required_scope' => $this->requiredScope,
            'retry_after' => $this->retryAfter,
            'errors' => $this->errors ?: null,
        ], fn ($value) => $value !== null);
    }
}
