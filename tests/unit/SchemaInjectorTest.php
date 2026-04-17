<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vibe\AIIndex\Services\SchemaInjector;
use Vibe\AIIndex\Services\SchemaGenerator;

class SchemaInjectorTest extends TestCase
{
    private SchemaInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_options']['vibe_ai_schema_injection_enabled'] = true;

        $generator = $this->createMock(SchemaGenerator::class);
        $this->injector = new SchemaInjector($generator);
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $GLOBALS['mock_options']['vibe_ai_schema_injection_enabled'] = true;
        $this->assertTrue($this->injector->is_enabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $GLOBALS['mock_options']['vibe_ai_schema_injection_enabled'] = false;
        $this->assertFalse($this->injector->is_enabled());
    }

    public function testIsEnabledDefaultsToTrueWhenOptionMissing(): void
    {
        unset($GLOBALS['mock_options']['vibe_ai_schema_injection_enabled']);
        $this->assertTrue($this->injector->is_enabled());
    }

    public function testSetEnabledUpdatesState(): void
    {
        $this->injector->set_enabled(false);
        $this->assertFalse(get_option('vibe_ai_schema_injection_enabled'));

        $this->injector->set_enabled(true);
        $this->assertTrue(get_option('vibe_ai_schema_injection_enabled'));
    }
}
