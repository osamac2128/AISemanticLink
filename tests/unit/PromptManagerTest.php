<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Prompts\PromptManager;

final class PromptManagerTest extends TestCase
{
    private PromptManager $manager;

    protected function setUp(): void
    {
        $this->manager = new PromptManager();
    }

    public function testCurrentExtractionPromptVersion(): void
    {
        self::assertSame('v1', $this->manager->getCurrentVersion('extraction'));
    }

    public function testExtractionPromptContentMatchesCurrent(): void
    {
        $prompt = $this->manager->getPrompt('extraction');

        self::assertStringContainsString('Named Entity Recognition', $prompt);
        self::assertStringContainsString('Semantic Knowledge Graph Engineer', $prompt);
        self::assertStringContainsString('strict JSON only', $prompt);
    }

    public function testExtractionPromptContainsAllTypes(): void
    {
        $prompt = $this->manager->getPrompt('extraction');

        $types = ['PERSON', 'ORG', 'COMPANY', 'LOCATION', 'COUNTRY', 'PRODUCT', 'SOFTWARE', 'EVENT', 'WORK', 'CONCEPT', 'TECHNOLOGY', 'BRAND'];

        foreach ($types as $type) {
            self::assertStringContainsString(
                $type,
                $prompt,
                "Extraction prompt must mention entity type: {$type}"
            );
        }
    }

    public function testUnknownPromptTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown prompt type: nonexistent');

        $this->manager->getPrompt('nonexistent');
    }

    public function testGetCurrentVersionUnknownType(): void
    {
        self::assertSame('v1', $this->manager->getCurrentVersion('unknown'));
    }
}
