<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class OpenRouterApiClient extends BaseApiClient
{
    /**
     * Constructor for OpenRouterApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing OpenRouter API client');

        $clientName = 'openrouter';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Get legacy text completions based on prompt parameters
     *
     * @param string      $prompt           The prompt to use for the completions
     * @param string|null $model            The model to use for the completions
     * @param int         $maxTokens        The maximum number of tokens to generate
     * @param int         $n                The number of completions to generate
     * @param float       $temperature      Controls randomness (0-2, higher is more random)
     * @param float       $topP             Controls diversity via nucleus sampling (0-1)
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException When model is not provided
     *
     * @return array The API response data
     */
    public function completions(
        string $prompt,
        ?string $model = null,
        int $maxTokens = 16,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        $model = $model ?? config('api-cache.apis.openrouter.default_model');

        if (!$model) {
            throw new \InvalidArgumentException(
                'Model required. Either pass $model parameter or set OPENROUTER_DEFAULT_MODEL in your .env file.'
            );
        }

        Log::debug('Making OpenRouter completions request', [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'n'           => $n,
            'temperature' => $temperature,
            'top_p'       => $topP,
        ]);

        $originalParams = [
            'prompt'      => $prompt,
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'n'           => $n,
            'temperature' => $temperature,
            'top_p'       => $topP,
        ];

        $params = array_merge($additionalParams, $originalParams);

        // Pass the prompt as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $prompt;
        }

        return $this->sendCachedRequest('completions', $params, 'POST', $attributes, $amount);
    }

    /**
     * Get chat completions based on messages parameters
     *
     * @param array|string $messages            The messages to use for the completions
     * @param string|null  $model               The model to use for the completions
     * @param int|null     $maxCompletionTokens The maximum number of tokens to generate
     * @param int          $n                   The number of completions to generate
     * @param float        $temperature         Controls randomness (0-2, higher is more random)
     * @param float        $topP                Controls diversity via nucleus sampling (0-1)
     * @param array        $additionalParams    Additional parameters to include in the request
     * @param string|null  $attributes          Optional attributes to store with the cache entry
     * @param int          $amount              Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException When model is not provided or when messages are not properly formatted
     *
     * @return array The API response data
     */
    public function chatCompletions(
        array|string $messages,
        ?string $model = null,
        ?int $maxCompletionTokens = null,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        $model = $model ?? config('api-cache.apis.openrouter.default_model');

        if (!$model) {
            throw new \InvalidArgumentException(
                'Model required. Either pass $model parameter or set OPENROUTER_DEFAULT_MODEL in your .env file.'
            );
        }

        // If messages is a string, assume it is a prompt and wrap it in an array of messages
        if (is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }

        // Ensure all elements in messages are properly formatted with role and content
        foreach ($messages as $message) {
            if (!isset($message['role']) || !isset($message['content'])) {
                Log::error('Invalid message format in chatCompletions request', [
                    'message' => $message,
                ]);

                throw new \InvalidArgumentException("Invalid message format: each message must have 'role' and 'content' keys");
            }
        }

        Log::debug('Making OpenRouter chat completions request', [
            'model'                 => $model,
            'max_completion_tokens' => $maxCompletionTokens,
            'n'                     => $n,
            'temperature'           => $temperature,
            'top_p'                 => $topP,
            'message_count'         => count($messages),
        ]);

        $originalParams = [
            'messages'    => $messages,
            'model'       => $model,
            'n'           => $n,
            'temperature' => $temperature,
            'top_p'       => $topP,
        ];

        if ($maxCompletionTokens !== null) {
            $originalParams['max_completion_tokens'] = $maxCompletionTokens;
        }

        $params = array_merge($additionalParams, $originalParams);

        // Pass the messages as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = json_encode($messages);
        }

        return $this->sendCachedRequest('chat/completions', $params, 'POST', $attributes, $amount);
    }
}
