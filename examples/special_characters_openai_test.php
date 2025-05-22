<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run OpenAI API tests with special characters
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $verbose            Whether to enable verbose output
 *
 * @return void
 */
function runOpenAITests(bool $compressionEnabled, bool $verbose = true): void
{
    echo "\nRunning OpenAI API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'openai';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 60);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    // $dropExisting is false. MySQL and file-based SQLite will use existing tables if they exist,
    // otherwise create them. SQLite memory will always create new tables regardless of $dropExisting
    // since memory databases are temporary.
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new OpenAIApiClient();
    $client->setTimeout(10);
    $client->clearRateLimit();

    // Test cases with special characters
    $testCases = [
        'emojis' => [
            'prompt'      => 'Please include these emojis in your response: 🌟 🎉 🌈 🚀 💻',
            'description' => 'Testing with emoji characters',
        ],
        'accents' => [
            'prompt'      => 'Please include these accented characters in your response: é à ü ñ ç ß',
            'description' => 'Testing with accented characters',
        ],
        'rtl' => [
            'prompt'      => 'Please include this Arabic text in your response: مرحبا بالعالم',
            'description' => 'Testing with right-to-left text',
        ],
        'cjk' => [
            'prompt'      => 'Please include these CJK characters in your response: 你好世界 こんにちは世界 안녕하세요',
            'description' => 'Testing with CJK characters',
        ],
        'math' => [
            'prompt'      => 'Please include these mathematical symbols in your response: ∑ ∫ ∏ √ ∞ ≠ ≤ ≥',
            'description' => 'Testing with mathematical symbols',
        ],
        'control' => [
            'prompt'      => "Please include these control characters in your response: \n \t \r",
            'description' => 'Testing with control characters',
        ],
        'special' => [
            'prompt'      => 'Please include these special symbols in your response: ♠ ♣ ♥ ♦ ™ ® © ¢ €',
            'description' => 'Testing with special symbols',
        ],
        'sql_injection' => [
            'prompt'      => 'Please include these SQL-like characters in your response: SELECT * FROM users; DROP TABLE students; --',
            'description' => 'Testing with SQL injection-like text',
        ],
        'mixed' => [
            'prompt'      => 'Please include this mixed text in your response: Hello 你好 مرحبا ∑=∞ 🌟 é',
            'description' => 'Testing with mixed characters',
        ],
    ];

    // Run each test case
    foreach ($testCases as $name => $test) {
        echo "\nTesting {$test['description']}...\n";

        try {
            $result = $client->chatCompletions(
                $test['prompt'],
                'gpt-4o-mini',
                100,
                1,
                0.7
            );
            echo format_api_response($result, $verbose);
        } catch (\Exception $e) {
            echo "Error testing {$name}: {$e->getMessage()}\n";
        }

        // Small delay to avoid hitting rate limits too quickly
        sleep(1);
    }
}

// Run tests without compression
runOpenAITests(false);

// Run tests with compression
runOpenAITests(true);
