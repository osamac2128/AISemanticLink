<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\AIClient;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;

class AIClientTest extends TestCase
{
    private AIClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['mock_options'] = [];
        unset($GLOBALS['mock_wp_remote_post_callback']);

        if (!defined('VIBE_AI_OPENROUTER_KEY')) {
            define('VIBE_AI_OPENROUTER_KEY', 'test-key');
        }

        $this->client = new class ('test-key') extends AIClient {
            public int $sleepCalls = 0;
            protected function sleep(int $seconds): void
            {
                $this->sleepCalls++;
            }
        };
        $this->client->resetRateLimitTracking();
    }

    public function testExtractSuccess(): void
    {
        $GLOBALS['mock_wp_remote_post_callback'] = function ($url, $args) {
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode(['entities' => [['name' => 'Test', 'type' => 'CONCEPT']]])
                            ]
                        ]
                    ]
                ])
            ];
        };

        $result = $this->client->extract('Sample text', 'system prompt');
        $this->assertArrayHasKey('entities', $result);
        $this->assertEquals('Test', $result['entities'][0]['name']);
        $this->assertFalse(get_option('vibe_ai_openrouter_circuit'));
    }

    public function testExtractHandlesEmptyResponse(): void
    {
        $GLOBALS['mock_wp_remote_post_callback'] = function ($url, $args) {
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'choices' => [['message' => ['content' => '{"entities":[]}']]]
                ])
            ];
        };

        $result = $this->client->extract('Sample text', 'system prompt');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('entities', $result);
    }

    public function testRateLimitAndBackoff(): void
    {
        $callCount = 0;
        $GLOBALS['mock_wp_remote_post_callback'] = function ($url, $args) use (&$callCount) {
            $callCount++;
            return [
                'response' => ['code' => 429],
                'headers' => ['x-ratelimit-reset' => '5'],
                'body' => 'Too Many Requests'
            ];
        };

        $this->expectException(RateLimitException::class);

        try {
            $this->client->extract('Rate limited text', 'system prompt');
        } catch (RateLimitException $e) {
            $this->assertEquals(3, $callCount, 'Should retry exactly 3 times');
            $this->assertEquals(2, $this->client->sleepCalls, 'Should sleep 2 times between 3 attempts');

            $state = get_option('vibe_ai_openrouter_circuit');
            $this->assertIsArray($state);
            $this->assertEquals(3, $state['failures']);

            throw $e;
        }
    }

    public function testExtractHandlesInvalidJsonResponse(): void
    {
        $GLOBALS['mock_wp_remote_post_callback'] = function ($url, $args) {
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'choices' => [['message' => ['content' => 'not valid json']]]
                ])
            ];
        };

        $this->expectException(\RuntimeException::class);
        $this->client->extract('Sample text', 'system prompt');
    }

    public function testExtractSendsCorrectHeaders(): void
    {
        $capturedArgs = null;
        $GLOBALS['mock_wp_remote_post_callback'] = function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'choices' => [['message' => ['content' => '{"entities":[]}']]]
                ])
            ];
        };

        $this->client->extract('test content', 'test prompt');

        $this->assertNotNull($capturedArgs);
        $this->assertArrayHasKey('headers', $capturedArgs);
        $this->assertArrayHasKey('Authorization', $capturedArgs['headers']);
        $this->assertStringContainsString('test-key', $capturedArgs['headers']['Authorization']);
    }

    public function testExtractHandlesServerError(): void
    {
        $GLOBALS['mock_wp_remote_post_callback'] = function ($url, $args) {
            return [
                'response' => ['code' => 500],
                'body' => 'Internal Server Error'
            ];
        };

        $this->expectException(\RuntimeException::class);
        $this->client->extract('test content', 'test prompt');
    }
}
