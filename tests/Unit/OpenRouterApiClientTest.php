<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\OpenRouterApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class OpenRouterApiClientTest extends TestCase
{
    protected OpenRouterApiClient $client;
    protected string $apiKey     = 'test-api-key';
    protected string $apiBaseUrl = 'https://openrouter.ai/api/v1';
    protected array $apiDefaultHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        Config::set('api-cache.apis.openrouter.api_key', $this->apiKey);
        Config::set('api-cache.apis.openrouter.default_model', 'deepseek/deepseek-chat-v3-0324:free');
        Config::set('api-cache.apis.openrouter.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.openrouter.version', 'v1');
        Config::set('api-cache.apis.openrouter.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.openrouter.rate_limit_decay_seconds', 10);

        $this->apiDefaultHeaders = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        $this->client = new OpenRouterApiClient();
        $this->client->setTimeout(10);
        $this->client->clearRateLimit();
    }

    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_initializes_with_correct_configuration()
    {
        $this->assertEquals('openrouter', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertEquals('v1', $this->client->getVersion());
    }

    public function test_makes_successful_completion_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::response([
                'id'       => 'gen-test',
                'object'   => 'chat.completion',
                'created'  => time(),
                'model'    => config('api-cache.apis.openrouter.default_model'),
                'provider' => 'Chutes',
                'choices'  => [
                    [
                        'text'                 => 'The answer is 4',
                        'logprobs'             => null,
                        'finish_reason'        => 'length',
                        'native_finish_reason' => 'length',
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 8,
                    'completion_tokens' => 50,
                    'total_tokens'      => 58,
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenRouterApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->completions(
            'What is 2+2?',
            config('api-cache.apis.openrouter.default_model'),
            50,
            1,
            0.7,
            1.0
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/completions" &&
                   $request->method() === 'POST' &&
                   $request->data()['prompt'] === 'What is 2+2?' &&
                   $request->data()['model'] === config('api-cache.apis.openrouter.default_model') &&
                   $request->data()['max_tokens'] === 50;
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals('gen-test', $body['id']);
        $this->assertEquals('The answer is 4', $body['choices'][0]['text']);
    }

    public function test_makes_successful_chat_completion_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/chat/completions" => Http::response([
                'id'       => 'gen-test',
                'object'   => 'chat.completion',
                'created'  => time(),
                'model'    => config('api-cache.apis.openrouter.default_model'),
                'provider' => 'Chutes',
                'choices'  => [
                    [
                        'index'   => 0,
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'The meaning of life is a philosophical question...',
                            'refusal' => null,
                        ],
                        'logprobs'             => null,
                        'finish_reason'        => 'length',
                        'native_finish_reason' => 'MAX_TOKENS',
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 8,
                    'completion_tokens' => 100,
                    'total_tokens'      => 108,
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenRouterApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->chatCompletions(
            'What is the meaning of life?',
            config('api-cache.apis.openrouter.default_model'),
            100,
            1,
            0.7,
            1.0
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/chat/completions" &&
                   $request->method() === 'POST' &&
                   $request->data()['messages'][0]['role'] === 'user' &&
                   $request->data()['messages'][0]['content'] === 'What is the meaning of life?' &&
                   $request->data()['model'] === config('api-cache.apis.openrouter.default_model');
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals('gen-test', $body['id']);
        $this->assertEquals('The meaning of life is a philosophical question...', $body['choices'][0]['message']['content']);
    }

    public function test_handles_chat_completion_with_conversation_thread()
    {
        Http::fake([
            "{$this->apiBaseUrl}/chat/completions" => Http::response([
                'id'       => 'gen-test',
                'object'   => 'chat.completion',
                'created'  => time(),
                'model'    => config('api-cache.apis.openrouter.default_model'),
                'provider' => 'Chutes',
                'choices'  => [
                    [
                        'index'   => 0,
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'They beat the Tampa Bay Rays.',
                            'refusal' => null,
                        ],
                        'logprobs'             => null,
                        'finish_reason'        => 'stop',
                        'native_finish_reason' => 'STOP',
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 42,
                    'completion_tokens' => 7,
                    'total_tokens'      => 49,
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenRouterApiClient();
        $this->client->clearRateLimit();

        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Who won the world series in 2020?'],
            ['role' => 'assistant', 'content' => 'The Los Angeles Dodgers won the World Series in 2020.'],
            ['role' => 'user', 'content' => 'Who did they beat?'],
        ];

        $response = $this->client->chatCompletions($messages);

        Http::assertSent(function ($request) use ($messages) {
            return $request->url() === "{$this->apiBaseUrl}/chat/completions" &&
                   $request->method() === 'POST' &&
                   $request->data()['messages'] === $messages;
        });

        // Make sure we used the Http::fake() response
        $body = $response['response']->json();
        $this->assertEquals('They beat the Tampa Bay Rays.', $body['choices'][0]['message']['content']);
    }

    public function test_validates_chat_completion_message_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid message format: each message must have 'role' and 'content' keys");

        $invalidMessages = [
            ['role' => 'system'], // Missing content
        ];

        $this->client->chatCompletions($invalidMessages);
    }

    public function test_caches_responses()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::sequence()
                ->push([
                    'id'      => 'gen-test-1',
                    'choices' => [['text' => 'The answer is 4']],
                ], 200)
                ->push([
                    'id'      => 'gen-test-2',
                    'choices' => [['text' => 'The answer is 4']],
                ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenRouterApiClient();
        $this->client->clearRateLimit();

        // First request
        $response1 = $this->client->completions('What is 2+2?');

        // Second request (should be cached)
        $response2 = $this->client->completions('What is 2+2?');

        // Verify only one HTTP request was made
        Http::assertSentCount(1);

        // Both responses should be identical
        $body1 = $response1['response']->json();
        $body2 = $response2['response']->json();
        $this->assertEquals($body1['id'], $body2['id']);
        $this->assertEquals($body1['choices'], $body2['choices']);

        // Make sure we used the Http::fake() response
        $this->assertEquals('gen-test-1', $body1['id']);
        $this->assertEquals('gen-test-1', $body2['id']);
    }

    public function test_enforces_rate_limits()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::response([
                'id'      => 'gen-test',
                'choices' => [['text' => 'Test response']],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenRouterApiClient();
        $this->client->clearRateLimit();

        // Make requests until rate limit is exceeded
        $this->expectException(RateLimitException::class);

        for ($i = 0; $i <= 5; $i++) {
            $result = $this->client->completions("Test {$i}");

            // Make sure we used the Http::fake() response
            $body = $result['response']->json();
            $this->assertEquals('gen-test', $body['id']);
            $this->assertEquals('Test response', $body['choices'][0]['text']);
        }
    }

    public function test_handles_api_errors()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type'    => 'invalid_request_error',
                ],
            ], 401),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenRouterApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->completions('Test prompt');

        // Make sure we used the Http::fake() response
        $this->assertEquals(401, $response['response']->status());
        $error = $response['response']->json('error');
        $this->assertEquals('Invalid API key', $error['message']);
        $this->assertEquals('invalid_request_error', $error['type']);
    }
}
