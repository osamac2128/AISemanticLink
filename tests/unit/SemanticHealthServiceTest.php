<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Repositories\EntityRepository;
use Vibe\AIIndex\Repositories\KB\DocumentRepository;
use Vibe\AIIndex\Services\KB\AISitemapGenerator;
use Vibe\AIIndex\Services\KB\ChangeFeedGenerator;
use Vibe\AIIndex\Services\KB\LlmsTxtGenerator;
use Vibe\AIIndex\Services\SemanticHealthService;

class SemanticHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['mock_options'] = [
            'vibe_ai_schema_injection_enabled' => true,
            'vibe_ai_kb_enabled' => true,
            'vibe_ai_kb_post_types' => ['post', 'page'],
        ];

        if (!defined('VIBE_AI_OPENROUTER_KEY')) {
            define('VIBE_AI_OPENROUTER_KEY', 'test-openrouter-key');
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['mock_options'] = [];

        parent::tearDown();
    }

    public function testGetReportBuildsHealthySummaryWithWarningForStaleSchema(): void
    {
        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository->method('get_stats')->willReturn([
            'total_entities' => 24,
            'total_mentions' => 63,
            'avg_confidence' => 0.812,
        ]);

        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('get_stats')->willReturn([
            'total_docs' => 11,
            'indexed_docs' => 9,
            'pending_docs' => 1,
            'chunked_docs' => 0,
            'excluded_docs' => 1,
            'failed_docs' => 0,
        ]);
        $documentRepository->method('get_last_indexed_at')->willReturn('2026-04-17T08:00:00+00:00');
        $documentRepository->method('calculateContentHash')->willReturn('a1b2c3d4e5f67890');

        $sitemapGenerator = $this->createMock(AISitemapGenerator::class);
        $sitemapGenerator->method('getIndexedPages')->willReturn([
            ['url' => 'http://localhost/post/1'],
            ['url' => 'http://localhost/post/2'],
            ['url' => 'http://localhost/post/3'],
        ]);

        $changeFeedGenerator = $this->createMock(ChangeFeedGenerator::class);
        $changeFeedGenerator->method('getActivitySummary')->willReturn([
            'total_documents' => 9,
            'changes_24h' => 2,
            'changes_7d' => 5,
            'last_update' => '2026-04-17T07:00:00+00:00',
            'feed_hash' => 'feedhash1234567890',
        ]);

        $llmsTxtGenerator = $this->createMock(LlmsTxtGenerator::class);
        $llmsTxtGenerator->method('getCuratedPages')->willReturn([
            ['post_id' => 1],
            ['post_id' => 2],
        ]);

        $service = new class(
            $entityRepository,
            $documentRepository,
            $sitemapGenerator,
            $changeFeedGenerator,
            $llmsTxtGenerator,
            (object) []
        ) extends SemanticHealthService {
            protected function get_schema_post_types(): array
            {
                return ['post', 'page'];
            }

            protected function get_kb_post_types(): array
            {
                return ['post', 'page'];
            }

            protected function count_published_posts(array $postTypes): int
            {
                return 10;
            }

            protected function count_schema_cached_posts(array $postTypes): int
            {
                return 9;
            }

            protected function count_valid_schema_posts(array $postTypes): int
            {
                return 8;
            }

            protected function count_disabled_schema_posts(array $postTypes): int
            {
                return 1;
            }

            protected function count_flagged_schema_posts(array $postTypes): int
            {
                return 0;
            }

            protected function count_stale_schema_posts(array $postTypes): int
            {
                return 1;
            }

            protected function count_posts_with_entities(array $postTypes): int
            {
                return 7;
            }

            protected function get_average_entities_per_post(array $postTypes): float
            {
                return 2.4;
            }
        };

        $report = $service->get_report();

        $this->assertSame('healthy', $report['summary']['status']);
        $this->assertGreaterThanOrEqual(90, $report['summary']['score']);
        $this->assertSame(1, $report['summary']['checks_warning']);
        $this->assertSame(0, $report['summary']['checks_failed']);

        $this->assertSame(0.889, $report['schema']['coverage_ratio']);
        $this->assertSame(0.7, $report['entities']['coverage_ratio']);
        $this->assertSame(0.9, $report['knowledge_base']['coverage_ratio']);

        $checksByKey = [];
        foreach ($report['checks'] as $check) {
            $checksByKey[$check['key']] = $check;
        }

        $this->assertSame('warn', $checksByKey['schema_freshness']['status']);
        $this->assertSame('pass', $checksByKey['ai_publishing']['status']);
        $this->assertSame('http://localhost/llms.txt', $report['ai_publishing']['llms_txt']['url']);
    }
}
