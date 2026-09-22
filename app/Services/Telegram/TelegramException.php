<?php

namespace App\Services\Telegram;

use RuntimeException;
use Throwable;

/**
 * A Bot API call that failed. $status is Telegram's error_code (or the HTTP
 * status), 0 when Telegram could not be reached.
 */
class TelegramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $description = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $neverSent = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * "Too Many Requests: retry after N": wait, nothing is wrong.
     */
    public function isRateLimit(): bool
    {
        return $this->status === 429;
    }

    /**
     * The person blocked the bot, left, or deleted their account.
     */
    public function isRecipientGone(): bool
    {
        $description = strtolower((string) $this->description);

        return $this->status === 403
            || ($this->status === 400 && str_contains($description, 'chat not found'));
    }

    /**
     * Telegram no longer knows a file id it gave (another bot, or expired):
     * upload the file again.
     */
    public function isFileIdRejected(): bool
    {
        return $this->status === 400
            && (bool) preg_match('/wrong (remote )?file identifier|file reference|FILE_ID|FILE_REFERENCE/i', (string) $this->description);
    }

    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /**
     * No answer after the request left: Telegram may have delivered it.
     */
    public function mayHaveReachedTelegram(): bool
    {
        return $this->status === 0 && ! $this->neverSent;
    }

    public function consoleStatus(): int
    {
        return match (true) {
            $this->status === 429 => 429,
            $this->status === 0, $this->status >= 500 => 502,
            default => 422,
        };
    }
}
