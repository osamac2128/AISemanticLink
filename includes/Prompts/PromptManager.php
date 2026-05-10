<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Prompts;

/**
 * Centralized AI prompt management.
 *
 * Manages versioned prompts used across the plugin, enabling future
 * prompt versioning, A/B testing, and tracking which prompt produced
 * each extraction.
 *
 * @package Vibe\AIIndex\Prompts
 */
class PromptManager
{
    /**
     * Current version for each prompt type.
     *
     * @var array<string, string>
     */
    private const CURRENT_VERSIONS = [
        'extraction' => 'v1',
    ];

    /**
     * Get the current version identifier for a prompt type.
     *
     * @param string $type The prompt type (e.g., 'extraction').
     *
     * @return string The version identifier (e.g., 'v1').
     */
    public function getCurrentVersion(string $type): string
    {
        return self::CURRENT_VERSIONS[$type] ?? 'v1';
    }

    /**
     * Get the full prompt text for a given type.
     *
     * @param string $type The prompt type.
     *
     * @return string The prompt text.
     *
     * @throws \InvalidArgumentException If the prompt type is unknown.
     */
    public function getPrompt(string $type): string
    {
        return match ($type) {
            'extraction' => $this->getExtractionPrompt(),
            default => throw new \InvalidArgumentException("Unknown prompt type: {$type}"),
        };
    }

    /**
     * Return the extraction prompt.
     *
     * This MUST remain in sync with EntityExtractor::SYSTEM_PROMPT.
     *
     * @return string The entity extraction system prompt.
     */
    private function getExtractionPrompt(): string
    {
        return <<<'PROMPT'
You are an expert Semantic Knowledge Graph Engineer specializing in Named Entity Recognition and normalization for SEO and AI discoverability.

CORE RULES:
1. Extract ONLY Named Entities (proper nouns with specific identity)
2. IGNORE generic nouns, adjectives, and common concepts
3. NORMALIZE names to their most complete, canonical form
4. RESOLVE ambiguity using context
5. Assign appropriate TYPE from: PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT, TECHNOLOGY, BRAND
6. Provide CONFIDENCE score (0.0-1.0) based on extraction certainty
7. Include CONTEXT snippet (exact quote, max 500 chars) showing entity mention

RESPONSE FORMAT (strict JSON only, no markdown):
{"entities": [{"name": "Canonical Name", "type": "TYPE", "confidence": 0.95, "context": "...snippet...", "aliases": ["alternate name"]}]}
PROMPT;
    }
}
