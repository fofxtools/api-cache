<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

/**
 * Thrown when rate limit is exceeded
 */
class RateLimitException extends \Exception
{
    protected string $clientName;
    protected int $availableInSeconds;

    public function __construct(
        string $clientName,
        int $availableInSeconds,
        string $message = '',
        ?\Throwable $previous = null
    ) {
        $message = $message ?: "Rate limit exceeded for client '{$clientName}'. Available in {$availableInSeconds} seconds.";
        parent::__construct($message, 429, $previous);

        $this->clientName         = $clientName;
        $this->availableInSeconds = $availableInSeconds;
    }

    /**
     * Returns the client name
     *
     * @return string The client name
     */
    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * Returns the number of seconds until the rate limit is available again
     *
     * @return int The number of seconds until the rate limit is available again
     */
    public function getAvailableInSeconds(): int
    {
        return $this->availableInSeconds;
    }
}
