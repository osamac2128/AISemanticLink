<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Config;

final class ModelRouter
{
    private const BUDGET_OPERATIONS = [
        'extraction',
        'deduplication',
        'indexing',
        'kb_embed_chunks',
        'kb_chunk_build',
    ];

    public static function select(string $operation): string
    {
        if (in_array($operation, self::BUDGET_OPERATIONS, true)) {
            return Config::BUDGET_MODEL;
        }

        return Config::DEFAULT_MODEL;
    }
}
