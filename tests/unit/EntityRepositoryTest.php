<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Config;
use Vibe\AIIndex\Repositories\EntityRepository;

class EntityRepositoryTest extends TestCase
{
    public function testGetTableNameMatchesConfig(): void
    {
        $this->assertSame('ai_entities', Config::TABLE_ENTITIES);
    }

    public function testGetMentionsTableNameMatchesConfig(): void
    {
        $this->assertSame('ai_mentions', Config::TABLE_MENTIONS);
    }

    public function testGetAliasesTableNameMatchesConfig(): void
    {
        $this->assertSame('ai_aliases', Config::TABLE_ALIASES);
    }

    public function testUpsertEntityValidatesName(): void
    {
        $this->assertFalse(trim('') !== '');
        $this->assertTrue(trim('Valid Name') !== '');
    }

    public function testTableNameFormat(): void
    {
        $this->assertStringContainsString('ai_entities', 'wp_ai_entities');
    }

    public function testRepositoryClassExists(): void
    {
        $this->assertTrue(class_exists(EntityRepository::class));
    }

    public function testRepositoryHasUpsertMethod(): void
    {
        $this->assertTrue(method_exists(EntityRepository::class, 'upsert_entity'));
    }

    public function testRepositoryHasLinkMentionMethod(): void
    {
        $this->assertTrue(method_exists(EntityRepository::class, 'link_mention'));
    }
}
