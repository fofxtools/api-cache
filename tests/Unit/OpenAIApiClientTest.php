<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\OpenAIApiClient;
use FOfX\ApiCache\RateLimitException;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class OpenAIApiClientTest extends TestCase
{
    protected OpenAIApiClient $client;
    protected string $apiKey     = 'test-api-key';
    protected string $apiBaseUrl = 'https://api.openai.com/v1';
    protected array $apiDefaultHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        Config::set('api-cache.apis.openai.api_key', $this->apiKey);
        Config::set('api-cache.apis.openai.base_url', $this->apiBaseUrl);
        Config::set('api-cache.apis.openai.version', 'v1');
        Config::set('api-cache.apis.openai.rate_limit_max_attempts', 5);
        Config::set('api-cache.apis.openai.rate_limit_decay_seconds', 10);

        $this->apiDefaultHeaders = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        $this->client = new OpenAIApiClient();
        $this->client->setTimeout(10);
        $this->client->clearRateLimit();
    }

    /**
     * Get service providers to register for testing.
     * Called implicitly by Orchestra TestCase to register providers before tests run.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_initializes_with_correct_configuration()
    {
        $this->assertEquals('openai', $this->client->getClientName());
        $this->assertEquals($this->apiBaseUrl, $this->client->getBaseUrl());
        $this->assertEquals($this->apiKey, $this->client->getApiKey());
        $this->assertEquals('v1', $this->client->getVersion());
    }

    public function test_makes_successful_completion_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::response([
                'id'      => 'cmpl-test',
                'object'  => 'text_completion',
                'created' => time(),
                'model'   => 'gpt-3.5-turbo-instruct:20230824-v2',
                'choices' => [
                    [
                        'text'          => "\n\n2+2=4",
                        'index'         => 0,
                        'logprobs'      => null,
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 7,
                    'completion_tokens' => 6,
                    'total_tokens'      => 13,
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenAIApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->completions(
            'What is 2+2?',
            'gpt-3.5-turbo-instruct',
            16,
            1,
            1.0,
            1.0
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/completions" &&
                   $request->method() === 'POST' &&
                   $request['prompt'] === 'What is 2+2?' &&
                   $request['model'] === 'gpt-3.5-turbo-instruct' &&
                   $request['max_tokens'] === 16;
        });

        $body = $response['response']->json();
        $this->assertEquals('cmpl-test', $body['id']);
        $this->assertEquals("\n\n2+2=4", $body['choices'][0]['text']);
    }

    public function test_makes_successful_chat_completion_request()
    {
        Http::fake([
            "{$this->apiBaseUrl}/chat/completions" => Http::response([
                'id'      => 'chatcmpl-test',
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => 'gpt-4o-mini-2024-07-18',
                'choices' => [
                    [
                        'index'   => 0,
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'The meaning of life is a philosophical question...',
                            'refusal' => null,
                        ],
                        'logprobs'      => null,
                        'finish_reason' => 'length',
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 14,
                    'completion_tokens' => 100,
                    'total_tokens'      => 114,
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenAIApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->chatCompletions(
            'What is the meaning of life?',
            'gpt-4o-mini',
            100,
            1,
            0.7,
            1.0
        );

        Http::assertSent(function ($request) {
            return $request->url() === "{$this->apiBaseUrl}/chat/completions" &&
                   $request->method() === 'POST' &&
                   $request['messages'][0]['role'] === 'user' &&
                   $request['messages'][0]['content'] === 'What is the meaning of life?' &&
                   $request['model'] === 'gpt-4o-mini';
        });

        $body = $response['response']->json();
        $this->assertEquals('chatcmpl-test', $body['id']);
        $this->assertEquals('The meaning of life is a philosophical question...', $body['choices'][0]['message']['content']);
    }

    public function test_handles_chat_completion_with_conversation_thread()
    {
        Http::fake([
            "{$this->apiBaseUrl}/chat/completions" => Http::response([
                'id'      => 'chatcmpl-test',
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => 'gpt-4o-mini-2024-07-18',
                'choices' => [
                    [
                        'index'   => 0,
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'The Los Angeles Dodgers defeated the Tampa Bay Rays.',
                            'refusal' => null,
                        ],
                        'logprobs'      => null,
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens'     => 53,
                    'completion_tokens' => 18,
                    'total_tokens'      => 71,
                ],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenAIApiClient();
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
                   $request['messages'] === $messages;
        });

        $body = $response['response']->json();
        $this->assertEquals('The Los Angeles Dodgers defeated the Tampa Bay Rays.', $body['choices'][0]['message']['content']);
    }

    public function test_validates_chat_completion_message_format()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid message format: each message must have 'role' and 'content' keys");

        $invalidMessages = [
            ['role' => 'system'], // Missing content
            ['content' => 'Hello'], // Missing role
        ];

        $this->client->chatCompletions($invalidMessages);
    }

    public function test_caches_responses()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::sequence()
                ->push([
                    'id'      => 'cmpl-test-1',
                    'choices' => [['text' => '2+2=4']],
                ], 200)
                ->push([
                    'id'      => 'cmpl-test-2',
                    'choices' => [['text' => '2+2=4']],
                ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenAIApiClient();
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
    }

    public function test_enforces_rate_limits()
    {
        Http::fake([
            "{$this->apiBaseUrl}/completions" => Http::response([
                'id'      => 'cmpl-test',
                'choices' => [['text' => 'Test response']],
            ], 200),
        ]);

        // Reinitialize client so that its HTTP pending request picks up the fake
        $this->client = new OpenAIApiClient();
        $this->client->clearRateLimit();

        // Make requests until rate limit is exceeded
        $this->expectException(RateLimitException::class);

        for ($i = 0; $i <= 5; $i++) {
            $this->client->completions("Test {$i}");
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
        $this->client = new OpenAIApiClient();
        $this->client->clearRateLimit();

        $response = $this->client->completions('Test prompt');

        // Assert that we got the error response
        $this->assertEquals(401, $response['response']->status());
        $error = $response['response']->json('error');
        $this->assertEquals('Invalid API key', $error['message']);
        $this->assertEquals('invalid_request_error', $error['type']);
    }
}
