<?php

namespace FOfX\ApiCache\Exceptions;

class RateLimitException extends ApiException
{
    protected int $retryAfter;

    public function setRetryAfter(int $seconds): self
    {
        $this->retryAfter = $seconds;

        return $this;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
