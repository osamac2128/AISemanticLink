<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Prompts\PromptManager;
use Vibe\AIIndex\Services\Exceptions\RateLimitException;
use Vibe\AIIndex\Services\KB\PIIDetector;
use Vibe\AIIndex\Services\ModelRouter;

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
     * The AI client for making API requests.
     */
    private AIClient $ai_client;

    /**
     * Optional prompt manager for versioned prompt delivery.
     */
    private ?PromptManager $prompt_manager = null;

    /**
     * PII scanner instance for content moderation.
     */
    private $piiScanner;

    /**
     * Constructor.
     *
     * @param AIClient|null $ai_client  The AI client instance. If null, creates a new one.
     * @param object|null   $piiScanner PII scanner with scan() method. Defaults to PIIDetector.
     */
    public function __construct(?AIClient $ai_client = null, ?object $piiScanner = null)
    {
        $this->ai_client = $ai_client ?? new AIClient();
        $this->piiScanner = $piiScanner ?? new PIIDetector();
    }

    /**
     * Inject a PromptManager for versioned prompt delivery.
     *
     * @param PromptManager $manager The prompt manager instance.
     */
    public function setPromptManager(PromptManager $manager): void
    {
        $this->prompt_manager = $manager;
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

        $model = $model ?? ModelRouter::select('extraction');
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
        if (is_string($response)) {
            $response = $this->decode_json_response($response);
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

        // Scan for PII in extracted entities
        foreach ($validated_entities as &$entity) {
            $scan = $this->piiScanner->scan(($entity['name'] ?? '') . ' ' . ($entity['context'] ?? ''));
            if ($scan->hasPii) {
                $entity['pii_flagged'] = true;
                $entity['pii_findings'] = $scan->findings;
            }
        }
        unset($entity);

        // Limit number of entities
        return array_slice($validated_entities, 0, Config::MAX_ENTITIES_PER_POST);
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
        if ($confidence < Config::CONFIDENCE_LOW) {
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
        if (in_array($type, Config::VALID_TYPES, true)) {
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
if (mb_strlen($context) > Config::MAX_CONTEXT_LENGTH) {
                $context = mb_substr($context, 0, Config::MAX_CONTEXT_LENGTH - 3) . '...';
        }

        return sanitize_text_field($context);
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

        // Use 80% of AIClient::MAX_INPUT_CHARS to leave room for system prompt and response tokens
        $maxContentLength = (int) floor(AIClient::MAX_INPUT_CHARS * 0.8);
        if (mb_strlen($content) > $maxContentLength) {
            $original_length = mb_strlen($content);
            $content = mb_substr($content, 0, $maxContentLength);
            error_log(sprintf(
                'Vibe AI: Content truncated. Original: %d chars, Truncated: %d chars, Lost: %d chars',
                $original_length,
                $maxContentLength,
                $original_length - $maxContentLength
            ));
        }

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
        $prompt = ($this->prompt_manager ?? new PromptManager())->getPrompt('extraction');

        /**
         * Filter the system prompt used for entity extraction.
         *
         * @param string $prompt The default system prompt.
         */
        if (has_filter('vibe_ai_system_prompt')) {
            $allow_override = current_user_can('manage_options')
                || defined('WP_CLI')
                || defined('DOING_CRON');

            if ($allow_override) {
                $prompt = apply_filters('vibe_ai_system_prompt', $prompt);
            }
        }

        return $prompt;
    }

    /**
     * Decode an AI response string into an array.
     *
     * @param string $response The raw response string.
     *
     * @return array<string, mixed> The decoded response.
     *
     * @throws \RuntimeException If decoding fails.
     */
    private function decode_json_response(string $response): array
    {
        $cleaned_response = $this->strip_markdown_fences($response);

        $decoded = json_decode($cleaned_response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = json_decode($response, true);
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \RuntimeException(
                'Failed to parse AI response JSON: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    /**
     * Remove common markdown code fences from AI responses.
     *
     * @param string $response The raw response string.
     *
     * @return string The cleaned response.
     */
    private function strip_markdown_fences(string $response): string
    {
        $response = trim($response);

        return preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $response);
    }

    /**
     * Get the minimum confidence threshold.
     *
     * @return float The minimum confidence threshold.
     */
    public function get_min_confidence_threshold(): float
    {
        return Config::CONFIDENCE_LOW;
    }

    /**
     * Get the allowed entity types.
     *
     * @return array<string> The allowed entity types.
     */
    public function get_allowed_types(): array
    {
        return Config::VALID_TYPES;
    }
}
