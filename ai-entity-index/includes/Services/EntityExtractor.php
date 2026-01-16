<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Services\Exceptions\RateLimitException;

/**
 * Entity extraction service.
 *
 * Responsible for extracting named entities from WordPress content
 * using AI-powered analysis via the OpenRouter API.
 *
 * @package Vibe\AIIndex\Services
 */
class EntityExtractor
{
    /**
     * Minimum confidence threshold for accepting entities.
     */
    private const MIN_CONFIDENCE_THRESHOLD = 0.4;

    /**
     * Maximum entities to extract per post.
     */
    private const MAX_ENTITIES_PER_POST = 50;

    /**
     * Maximum context snippet length.
     */
    private const MAX_CONTEXT_LENGTH = 100;

    /**
     * Allowed entity types.
     *
     * @var array<string>
     */
    private const ALLOWED_TYPES = [
        'PERSON',
        'ORG',
        'COMPANY',
        'LOCATION',
        'COUNTRY',
        'PRODUCT',
        'SOFTWARE',
        'EVENT',
        'WORK',
        'CONCEPT',
    ];

    /**
     * The system prompt for entity extraction.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert Semantic Knowledge Graph Engineer specializing in Named Entity Recognition and normalization for SEO and AI discoverability.

CORE RULES:
1. Extract ONLY Named Entities (proper nouns with specific identity)
2. IGNORE generic nouns, adjectives, and common concepts
3. NORMALIZE names to their most complete, canonical form
4. RESOLVE ambiguity using context
5. Assign appropriate TYPE from: PERSON, ORG, COMPANY, LOCATION, COUNTRY, PRODUCT, SOFTWARE, EVENT, WORK, CONCEPT
6. Provide CONFIDENCE score (0.0-1.0) based on extraction certainty
7. Include CONTEXT snippet (exact quote, max 100 chars) showing entity mention

RESPONSE FORMAT (strict JSON only, no markdown):
{"entities": [{"name": "Canonical Name", "type": "TYPE", "confidence": 0.95, "context": "...snippet...", "aliases": ["alternate name"]}]}
PROMPT;

    /**
     * The AI client for making API requests.
     */
    private AIClient $ai_client;

    /**
     * Constructor.
     *
     * @param AIClient|null $ai_client The AI client instance. If null, creates a new one.
     */
    public function __construct(?AIClient $ai_client = null)
    {
        $this->ai_client = $ai_client ?? new AIClient();
    }

    /**
     * Extract entities from a WordPress post.
     *
     * @param int         $post_id The post ID to extract entities from.
     * @param string|null $model   Optional model override.
     *
     * @return array<int, array<string, mixed>> Array of extracted entities.
     *
     * @throws \InvalidArgumentException If post does not exist.
     * @throws RateLimitException        If rate limit is exceeded.
     * @throws \RuntimeException         If extraction fails.
     */
    public function extract_from_post(int $post_id, ?string $model = null): array
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            throw new \InvalidArgumentException(
                sprintf('Post with ID %d does not exist', $post_id)
            );
        }

        $content = $this->prepare_content($post);

        if (empty(trim($content))) {
            return [];
        }

        return $this->extract_from_content($content, $model);
    }

    /**
     * Extract entities from raw content.
     *
     * @param string      $content The content to extract entities from.
     * @param string|null $model   Optional model override.
     *
     * @return array<int, array<string, mixed>> Array of extracted entities.
     *
     * @throws RateLimitException If rate limit is exceeded.
     * @throws \RuntimeException  If extraction fails.
     */
    public function extract_from_content(string $content, ?string $model = null): array
    {
        if (empty(trim($content))) {
            return [];
        }

        $system_prompt = $this->get_system_prompt();

        $response = $this->ai_client->extract($content, $system_prompt, $model);

        return $this->parse_ai_response($response);
    }

    /**
     * Parse and validate AI response.
     *
     * @param array<string, mixed>|string $response The AI response (JSON string or decoded array).
     *
     * @return array<int, array<string, mixed>> Array of validated entities.
     *
     * @throws \RuntimeException If response format is invalid.
     */
    public function parse_ai_response($response): array
    {
        // Handle string input
        if (is_string($response)) {
            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(
                    'Failed to parse AI response JSON: ' . json_last_error_msg()
                );
            }

            $response = $decoded;
        }

        if (!is_array($response)) {
            throw new \RuntimeException('AI response must be an array');
        }

        if (!isset($response['entities'])) {
            throw new \RuntimeException('AI response missing "entities" key');
        }

        if (!is_array($response['entities'])) {
            throw new \RuntimeException('AI response "entities" must be an array');
        }

        $validated_entities = [];

        foreach ($response['entities'] as $index => $entity) {
            $validated = $this->validate_entity($entity, $index);

            if ($validated !== null) {
                $validated_entities[] = $validated;
            }
        }

        // Limit number of entities
        return array_slice($validated_entities, 0, self::MAX_ENTITIES_PER_POST);
    }

    /**
     * Validate and normalize a single entity.
     *
     * @param mixed $entity The entity data to validate.
     * @param int   $index  The entity index for error messages.
     *
     * @return array<string, mixed>|null The validated entity or null if invalid.
     */
    private function validate_entity($entity, int $index): ?array
    {
        if (!is_array($entity)) {
            return null;
        }

        // Required fields
        if (!isset($entity['name']) || !is_string($entity['name']) || empty(trim($entity['name']))) {
            return null;
        }

        if (!isset($entity['type']) || !is_string($entity['type'])) {
            return null;
        }

        // Confidence validation
        $confidence = $entity['confidence'] ?? 0.0;

        if (!is_numeric($confidence)) {
            $confidence = 0.0;
        }

        $confidence = (float) $confidence;

        // Reject entities below minimum confidence threshold
        if ($confidence < self::MIN_CONFIDENCE_THRESHOLD) {
            return null;
        }

        // Clamp confidence to valid range
        $confidence = max(0.0, min(1.0, $confidence));

        // Normalize type
        $type = $this->normalize_type($entity['type']);

        // Normalize context
        $context = $entity['context'] ?? '';

        if (!is_string($context)) {
            $context = '';
        }

        $context = $this->normalize_context($context);

        // Normalize aliases
        $aliases = $entity['aliases'] ?? [];

        if (!is_array($aliases)) {
            $aliases = [];
        }

        $aliases = $this->normalize_aliases($aliases);

        return [
            'name' => sanitize_text_field(trim($entity['name'])),
            'type' => $type,
            'confidence' => $confidence,
            'context' => $context,
            'aliases' => $aliases,
        ];
    }

    /**
     * Normalize entity type to allowed values.
     *
     * @param string $type The raw type from AI response.
     *
     * @return string The normalized type.
     */
    private function normalize_type(string $type): string
    {
        $type = strtoupper(trim($type));

        // Direct match
        if (in_array($type, self::ALLOWED_TYPES, true)) {
            return $type;
        }

        // Common variations mapping
        $type_map = [
            'ORGANIZATION' => 'ORG',
            'ORGANISATION' => 'ORG',
            'CORPORATION' => 'COMPANY',
            'CORP' => 'COMPANY',
            'BUSINESS' => 'COMPANY',
            'PLACE' => 'LOCATION',
            'CITY' => 'LOCATION',
            'STATE' => 'LOCATION',
            'REGION' => 'LOCATION',
            'NATION' => 'COUNTRY',
            'APP' => 'SOFTWARE',
            'APPLICATION' => 'SOFTWARE',
            'PROGRAM' => 'SOFTWARE',
            'TOOL' => 'SOFTWARE',
            'BOOK' => 'WORK',
            'MOVIE' => 'WORK',
            'FILM' => 'WORK',
            'SONG' => 'WORK',
            'ALBUM' => 'WORK',
            'ARTICLE' => 'WORK',
            'CONFERENCE' => 'EVENT',
            'MEETING' => 'EVENT',
            'IDEA' => 'CONCEPT',
            'THEORY' => 'CONCEPT',
            'TECHNOLOGY' => 'CONCEPT',
            'BRAND' => 'PRODUCT',
            'ITEM' => 'PRODUCT',
        ];

        if (isset($type_map[$type])) {
            return $type_map[$type];
        }

        // Default to CONCEPT for unknown types
        return 'CONCEPT';
    }

    /**
     * Normalize context snippet.
     *
     * @param string $context The raw context string.
     *
     * @return string The normalized context.
     */
    private function normalize_context(string $context): string
    {
        $context = trim($context);

        if (empty($context)) {
            return '';
        }

        // Truncate to max length
        if (mb_strlen($context) > self::MAX_CONTEXT_LENGTH) {
            $context = mb_substr($context, 0, self::MAX_CONTEXT_LENGTH - 3) . '...';
        }

        return wp_kses_post($context);
    }

    /**
     * Normalize aliases array.
     *
     * @param array<mixed> $aliases The raw aliases array.
     *
     * @return array<string> The normalized aliases.
     */
    private function normalize_aliases(array $aliases): array
    {
        $normalized = [];

        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }

            $alias = sanitize_text_field(trim($alias));

            if (!empty($alias)) {
                $normalized[] = $alias;
            }
        }

        // Remove duplicates and limit count
        return array_slice(array_unique($normalized), 0, 20);
    }

    /**
     * Prepare post content for AI processing.
     *
     * @param \WP_Post $post The post object.
     *
     * @return string The prepared plain text content.
     */
    private function prepare_content(\WP_Post $post): string
    {
        // Combine title and content
        $content = $post->post_title . "\n\n" . $post->post_content;

        // Strip shortcodes
        $content = strip_shortcodes($content);

        // Strip HTML tags
        $content = wp_strip_all_tags($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        return $content;
    }

    /**
     * Get the system prompt for entity extraction.
     *
     * Allows filtering via WordPress hooks.
     *
     * @return string The system prompt.
     */
    private function get_system_prompt(): string
    {
        /**
         * Filter the system prompt used for entity extraction.
         *
         * @param string $prompt The default system prompt.
         */
        return apply_filters('vibe_ai_system_prompt', self::SYSTEM_PROMPT);
    }

    /**
     * Get the minimum confidence threshold.
     *
     * @return float The minimum confidence threshold.
     */
    public function get_min_confidence_threshold(): float
    {
        return self::MIN_CONFIDENCE_THRESHOLD;
    }

    /**
     * Get the allowed entity types.
     *
     * @return array<string> The allowed entity types.
     */
    public function get_allowed_types(): array
    {
        return self::ALLOWED_TYPES;
    }
}
