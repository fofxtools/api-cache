<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use FOfX\Helper;

class RateLimitService
{
    /**
     * The rate limiter instance
     */
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Get the rate limit key for a client
     *
     * @param string $clientName Client identifier
     *
     * @return string Cache key for rate limiting
     */
    public function getRateLimitKey(string $clientName): string
    {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        return "api-cache:rate-limit:{$clientName}";
    }

    /**
     * Get maximum attempts per window for a client
     *
     * @param string $clientName Client identifier
     *
     * @return int|null Maximum attempts (null or negative means unlimited)
     */
    public function getMaxAttempts(string $clientName): ?int
    {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        $value = config("api-cache.apis.{$clientName}.rate_limit_max_attempts");

        return $value === null ? null : (int) $value;
    }

    /**
     * Get the decay seconds for a client
     *
     * @param string $clientName Client identifier
     *
     * @return int Number of seconds in the rate limit window
     */
    public function getDecaySeconds(string $clientName): int
    {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        return (int) config("api-cache.apis.{$clientName}.rate_limit_decay_seconds");
    }

    /**
     * Clear rate limits for a client
     *
     * @param string $clientName Client identifier
     */
    public function clear(string $clientName): void
    {
        $key = $this->getRateLimitKey($clientName);

        $remainingAttemptsBeforeClear = $this->getRemainingAttempts($clientName);
        $this->limiter->clear($key);
        $remainingAttemptsAfterClear = $this->getRemainingAttempts($clientName);

        Log::debug('Rate limit state cleared', [
            'client'                          => $clientName,
            'remaining_attempts_before_clear' => $remainingAttemptsBeforeClear,
            'remaining_attempts_after_clear'  => $remainingAttemptsAfterClear,
        ]);
    }

    /**
     * Get remaining attempts for the client
     *
     * @param string $clientName Client identifier
     *
     * @return int Number of remaining attempts
     */
    public function getRemainingAttempts(string $clientName): int
    {
        $key         = $this->getRateLimitKey($clientName);
        $maxAttempts = $this->getMaxAttempts($clientName);

        // If null or negative, return PHP_INT_MAX to indicate unlimited attempts
        if ($maxAttempts === null || $maxAttempts < 0) {
            return PHP_INT_MAX;
        }

        return $this->limiter->remaining($key, $maxAttempts);
    }

    /**
     * Get the number of seconds until rate limit resets
     *
     * @param string $clientName Client identifier
     *
     * @return int Number of seconds until reset (0 if not limited)
     */
    public function getAvailableIn(string $clientName): int
    {
        $key         = $this->getRateLimitKey($clientName);
        $maxAttempts = $this->getMaxAttempts($clientName);

        if (!$this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return 0;
        }

        return $this->limiter->availableIn($key);
    }

    /**
     * Check if request is allowed for the given client
     *
     * @param string $clientName Client identifier
     *
     * @return bool True if request is allowed, false if rate limited
     */
    public function allowRequest(string $clientName): bool
    {
        $maxAttempts       = $this->getMaxAttempts($clientName);
        $remainingAttempts = $this->getRemainingAttempts($clientName);
        $allowed           = $remainingAttempts > 0;

        if (!$allowed) {
            $availableIn = $this->getAvailableIn($clientName);
            Log::warning('Rate limit exceeded', [
                'client'       => $clientName,
                'available_in' => $availableIn,
                'max_attempts' => $maxAttempts,
            ]);
        } else {
            Log::debug('Rate limit status check', [
                'client'             => $clientName,
                'remaining_attempts' => $remainingAttempts,
                'max_attempts'       => $maxAttempts,
            ]);
        }

        return $allowed;
    }

    /**
     * Increment the rate limit counter for a client
     *
     * @param string $clientName Client identifier
     * @param int    $amount     Amount to increment by (default 1)
     */
    public function incrementAttempts(string $clientName, int $amount = 1): void
    {
        $key          = $this->getRateLimitKey($clientName);
        $decaySeconds = $this->getDecaySeconds($clientName);
        $maxAttempts  = $this->getMaxAttempts($clientName);

        $this->limiter->increment($key, $decaySeconds, $amount);
        $remainingAttempts = $this->getRemainingAttempts($clientName);

        Log::debug('Rate limit incremented', [
            'client'             => $clientName,
            'amount'             => $amount,
            'remaining_attempts' => $remainingAttempts,
            'max_attempts'       => $maxAttempts,
        ]);
    }
}
