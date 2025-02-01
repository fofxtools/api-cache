<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;

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
     * @param string $client Client identifier
     *
     * @return string Cache key for rate limiting
     */
    public function getRateLimitKey(string $client): string
    {
        return "api-cache:rate-limit:{$client}";
    }

    /**
     * Get maximum attempts per window for a client
     *
     * @param string $client Client identifier
     *
     * @return int|null Maximum attempts (null or negative means unlimited)
     */
    public function getMaxAttempts(string $client): ?int
    {
        $value = config("api-cache.apis.{$client}.rate_limit_max_attempts");

        return $value === null ? null : (int) $value;
    }

    /**
     * Get the decay seconds for a client
     *
     * @param string $client Client identifier
     *
     * @return int Number of seconds in the rate limit window
     */
    public function getDecaySeconds(string $client): int
    {
        return (int) config("api-cache.apis.{$client}.rate_limit_decay_seconds");
    }

    /**
     * Clear rate limits for a client
     *
     * @param string $client Client identifier
     */
    public function clear(string $client): void
    {
        $key = $this->getRateLimitKey($client);

        $remainingAttemptsBeforeClear = $this->getRemainingAttempts($client);
        $this->limiter->clear($key);
        $remainingAttemptsAfterClear = $this->getRemainingAttempts($client);

        Log::debug('Rate limit state cleared', [
            'client'                          => $client,
            'remaining_attempts_before_clear' => $remainingAttemptsBeforeClear,
            'remaining_attempts_after_clear'  => $remainingAttemptsAfterClear,
        ]);
    }

    /**
     * Get remaining attempts for the client
     *
     * @param string $client Client identifier
     *
     * @return int Number of remaining attempts
     */
    public function getRemainingAttempts(string $client): int
    {
        $key         = $this->getRateLimitKey($client);
        $maxAttempts = $this->getMaxAttempts($client);

        // If null or negative, return PHP_INT_MAX to indicate unlimited attempts
        if ($maxAttempts === null || $maxAttempts < 0) {
            return PHP_INT_MAX;
        }

        return $this->limiter->remaining($key, $maxAttempts);
    }

    /**
     * Get the number of seconds until rate limit resets
     *
     * @param string $client Client identifier
     *
     * @return int Number of seconds until reset (0 if not limited)
     */
    public function getAvailableIn(string $client): int
    {
        $key         = $this->getRateLimitKey($client);
        $maxAttempts = $this->getMaxAttempts($client);

        if (!$this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return 0;
        }

        return $this->limiter->availableIn($key);
    }

    /**
     * Check if request is allowed for the given client
     *
     * @param string $client Client identifier
     *
     * @return bool True if request is allowed, false if rate limited
     */
    public function allowRequest(string $client): bool
    {
        $maxAttempts       = $this->getMaxAttempts($client);
        $remainingAttempts = $this->getRemainingAttempts($client);
        $allowed           = $remainingAttempts > 0;

        if (!$allowed) {
            $availableIn = $this->getAvailableIn($client);
            Log::warning('Rate limit exceeded', [
                'client'       => $client,
                'available_in' => $availableIn,
                'max_attempts' => $maxAttempts,
            ]);
        } else {
            Log::debug('Rate limit status check', [
                'client'             => $client,
                'remaining_attempts' => $remainingAttempts,
                'max_attempts'       => $maxAttempts,
            ]);
        }

        return $allowed;
    }

    /**
     * Increment the rate limit counter for a client
     *
     * @param string $client Client identifier
     * @param int    $amount Amount to increment by (default 1)
     */
    public function incrementAttempts(string $client, int $amount = 1): void
    {
        $key          = $this->getRateLimitKey($client);
        $decaySeconds = $this->getDecaySeconds($client);
        $maxAttempts  = $this->getMaxAttempts($client);

        $this->limiter->increment($key, $decaySeconds, $amount);
        $remainingAttempts = $this->getRemainingAttempts($client);

        Log::debug('Rate limit incremented', [
            'client'             => $client,
            'amount'             => $amount,
            'remaining_attempts' => $remainingAttempts,
            'max_attempts'       => $maxAttempts,
        ]);
    }
}
