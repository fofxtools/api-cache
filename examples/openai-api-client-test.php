<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run OpenAI API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $verbose            Whether to enable verbose output
 *
 * @return void
 */
function runOpenAITests(bool $compressionEnabled, $verbose = true): void
{
    echo "\nRunning OpenAI API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'openai';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 5);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 10);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    // $dropExisting is false. MySQL and file-based SQLite will use existing tables if they exist,
    // otherwise create them. SQLite memory will always create new tables regardless of $dropExisting
    // since memory databases are temporary.
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance.  Clear rate limit since we might have run tests before.
    $client = new OpenAIApiClient();
    $client->setTimeout(10);
    $client->clearRateLimit();

    // Test completions endpoint
    $model = 'gpt-3.5-turbo-instruct';
    echo "\nTesting completions endpoint with model: {$model}...\n";

    try {
        $result = $client->completions(
            'What is 2+2?',
            $model,
            50,
            1,
            0.7
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing completions: {$e->getMessage()}\n";
    }

    // Test chat completions endpoint with string input
    $model = 'gpt-4o-mini';
    echo "\nTesting chat completions endpoint with model: {$model}...\n";

    try {
        $result = $client->chatCompletions(
            'What is the meaning of life?',
            $model,
            100,
            1,
            0.7
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing chat completions with string: {$e->getMessage()}\n";
    }

    // Test chat completions endpoint with array input
    // $messages contains the conversation history
    echo "\nTesting chat completions endpoint with array input and model: {$model}...\n";

    try {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Who won the world series in 2020?'],
            ['role' => 'assistant', 'content' => 'The Los Angeles Dodgers won the World Series in 2020.'],
            ['role' => 'user', 'content' => 'Who did they beat?'],
        ];

        $result = $client->chatCompletions(
            $messages,
            $model,
            100,
            1,
            0.7
        );
        echo format_api_response($result, $verbose);
    } catch (\Exception $e) {
        echo "Error testing chat completions with array: {$e->getMessage()}\n";
    }

    // Test error handling with invalid message format
    echo "\nTesting error handling with invalid message format...\n";

    try {
        $invalidMessages = [
            ['role' => 'system'], // Missing content
            ['content' => 'Hello'], // Missing role
        ];

        $result = $client->chatCompletions($invalidMessages);
        echo "This should not be reached\n";
    } catch (\InvalidArgumentException $e) {
        echo "Successfully caught invalid message format: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        $prompt = 'What is 2+2?';
        echo "First request...\n";
        $result1 = $client->completions($prompt);
        echo format_api_response($result1, $verbose);

        echo "\nSecond request (should be cached)...\n";
        $result2 = $client->completions($prompt);
        echo format_api_response($result2, $verbose);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting
    echo "\nTesting rate limiting...\n";

    try {
        for ($i = 0; $i <= 5; $i++) {
            echo "Request {$i}...\n";
            $result = $client->completions("Test {$i}");
            echo format_api_response($result, $verbose);
        }
    } catch (RateLimitException $e) {
        echo "Successfully hit rate limit: {$e->getMessage()}\n";
        echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
    }
}

// Run tests without compression
runOpenAITests(false);

// Run tests with compression
runOpenAITests(true);
