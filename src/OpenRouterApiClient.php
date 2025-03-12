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
     * @param string $prompt           The prompt to use for the completions
     * @param string $model            The model to use for the completions
     * @param int    $maxTokens        The maximum number of tokens to generate
     * @param int    $n                The number of completions to generate
     * @param float  $temperature      Controls randomness (0-2, higher is more random)
     * @param float  $topP             Controls diversity via nucleus sampling (0-1)
     * @param array  $additionalParams Additional parameters to include in the request
     *
     * @return array The API response data
     */
    public function completions(
        string $prompt,
        string $model = 'meta-llama/llama-3.3-70b-instruct:free',
        int $maxTokens = 16,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array {
        Log::debug('Making OpenRouter completions request', [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'n'           => $n,
            'temperature' => $temperature,
            'top_p'       => $topP,
        ]);

        $params = array_merge($additionalParams, [
            'prompt'      => $prompt,
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'n'           => $n,
            'temperature' => $temperature,
            'top_p'       => $topP,
        ]);

        return $this->sendCachedRequest('completions', $params, 'POST');
    }

    /**
     * Get chat completions based on messages parameters
     *
     * @param array|string $messages            The messages to use for the completions
     * @param string       $model               The model to use for the completions
     * @param int|null     $maxCompletionTokens The maximum number of tokens to generate
     * @param int          $n                   The number of completions to generate
     * @param float        $temperature         Controls randomness (0-2, higher is more random)
     * @param float        $topP                Controls diversity via nucleus sampling (0-1)
     * @param array        $additionalParams    Additional parameters to include in the request
     *
     * @throws \InvalidArgumentException When messages are not properly formatted
     *
     * @return array The API response data
     */
    public function chatCompletions(
        array|string $messages,
        string $model = 'meta-llama/llama-3.3-70b-instruct:free',
        ?int $maxCompletionTokens = null,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array {
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

        $params = array_merge($additionalParams, [
            'messages'    => $messages,
            'model'       => $model,
            'n'           => $n,
            'temperature' => $temperature,
            'top_p'       => $topP,
        ]);

        if ($maxCompletionTokens !== null) {
            $params['max_completion_tokens'] = $maxCompletionTokens;
        }

        return $this->sendCachedRequest('chat/completions', $params, 'POST');
    }
}
