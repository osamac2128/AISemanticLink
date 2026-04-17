<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Repositories\EntityRepository;
use Vibe\AIIndex\Services\SchemaGenerator;

class SchemaGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['mock_posts'] = [];
        $GLOBALS['mock_post_meta'] = [];
        $GLOBALS['mock_users'] = [];
        $GLOBALS['mock_bloginfo'] = [
            'name' => 'Semantic Test Site',
            'description' => 'A semantic SEO test instance',
        ];
        $GLOBALS['mock_thumbnails'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['mock_posts'],
            $GLOBALS['mock_post_meta'],
            $GLOBALS['mock_users'],
            $GLOBALS['mock_bloginfo'],
            $GLOBALS['mock_thumbnails']
        );

        parent::tearDown();
    }

    public function testGenerateIncludesExpandedSitewideGraph(): void
    {
        $postId = 42;
        $GLOBALS['mock_posts'][$postId] = new \WP_Post([
            'ID' => $postId,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_title' => 'Semantic SEO Readiness',
            'post_excerpt' => 'A short schema description.',
            'post_content' => 'Longer body content for semantic indexing.',
            'post_author' => 7,
            'post_date' => '2026-04-01T10:00:00+00:00',
            'post_modified' => '2026-04-02T11:00:00+00:00',
        ]);
        $GLOBALS['mock_users'][7] = (object) [
            'user_nicename' => 'editor',
            'display_name' => 'Schema Editor',
        ];
        $GLOBALS['mock_thumbnails'][$postId] = 'http://localhost/media/semantic.jpg';

        $primaryEntity = (object) [
            'name' => 'WordPress',
            'slug' => 'wordpress',
            'type' => 'SOFTWARE',
            'is_primary' => true,
            'description' => 'The WordPress publishing platform.',
        ];
        $secondaryEntity = (object) [
            'name' => 'Yoast SEO',
            'slug' => 'yoast-seo',
            'type' => 'PRODUCT',
            'is_primary' => false,
        ];

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('get_entities_for_post')
            ->with($postId, 0.6)
            ->willReturn([$primaryEntity, $secondaryEntity]);

        $generator = new SchemaGenerator($repository);
        $schema = json_decode($generator->generate($postId), true);

        $this->assertIsArray($schema);
        $this->assertSame('https://schema.org', $schema['@context']);

        $graphById = [];
        foreach ($schema['@graph'] as $node) {
            $graphById[$node['@id']] = $node;
        }

        $this->assertArrayHasKey('http://localhost#website', $graphById);
        $this->assertSame('WebSite', $graphById['http://localhost#website']['@type']);

        $this->assertArrayHasKey('http://localhost#publisher', $graphById);
        $this->assertSame('Organization', $graphById['http://localhost#publisher']['@type']);

        $this->assertArrayHasKey('http://localhost/post/42', $graphById);
        $this->assertSame('WebPage', $graphById['http://localhost/post/42']['@type']);

        $article = $graphById['http://localhost/post/42#article'];
        $this->assertSame('Article', $article['@type']);
        $this->assertSame('http://localhost#publisher', $article['publisher']['@id']);
        $this->assertSame('http://localhost/post/42', $article['mainEntityOfPage']['@id']);
        $this->assertSame('A short schema description.', $article['description']);
        $this->assertSame('http://localhost/media/semantic.jpg', $article['image']);
        $this->assertSame('http://localhost/#/entity/wordpress', $article['about']['@id']);
        $this->assertCount(2, $article['mentions']);

        $this->assertArrayHasKey('http://localhost/#/entity/wordpress', $graphById);
        $this->assertSame('SoftwareApplication', $graphById['http://localhost/#/entity/wordpress']['@type']);
    }

    public function testRegenerateCachesSchemaUsingCurrentVersion(): void
    {
        $postId = 7;
        $GLOBALS['mock_posts'][$postId] = new \WP_Post([
            'ID' => $postId,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_title' => 'Cache Test',
            'post_content' => 'Content',
            'post_author' => 1,
            'post_date' => '2026-04-01T10:00:00+00:00',
            'post_modified' => '2026-04-02T11:00:00+00:00',
        ]);
        $GLOBALS['mock_users'][1] = (object) [
            'user_nicename' => 'author',
            'display_name' => 'Cache Author',
        ];

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('get_entities_for_post')->willReturn([]);

        $generator = new SchemaGenerator($repository);
        $generator->regenerate($postId);

        $this->assertArrayHasKey($postId, $GLOBALS['mock_post_meta']);
        $this->assertSame(
            SchemaGenerator::SCHEMA_VERSION,
            $GLOBALS['mock_post_meta'][$postId][SchemaGenerator::META_SCHEMA_VERSION]
        );
        $this->assertNotEmpty($GLOBALS['mock_post_meta'][$postId][SchemaGenerator::META_SCHEMA_CACHE]);
    }
}
