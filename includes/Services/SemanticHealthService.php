<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Services;

use Vibe\AIIndex\Config;
use Vibe\AIIndex\Repositories\EntityRepository;
use Vibe\AIIndex\Repositories\KB\DocumentRepository;
use Vibe\AIIndex\Services\KB\AISitemapGenerator;
use Vibe\AIIndex\Services\KB\ChangeFeedGenerator;
use Vibe\AIIndex\Services\KB\LlmsTxtGenerator;

/**
 * Builds semantic SEO health metrics for the admin dashboard.
 *
 * This report intentionally stays lightweight enough for frequent admin polling
 * while still answering the most important readiness questions:
 * schema coverage, entity coverage, KB index coverage, and AI crawler readiness.
 */
class SemanticHealthService
{
    /**
     * WordPress database instance.
     *
     * @var object
     */
    private object $wpdb;

    private EntityRepository $entityRepository;

    private DocumentRepository $documentRepository;

    private AISitemapGenerator $sitemapGenerator;

    private ChangeFeedGenerator $changeFeedGenerator;

    private LlmsTxtGenerator $llmsTxtGenerator;

    /**
     * @param object|null $wpdb Optional database adapter for tests.
     */
    public function __construct(
        ?EntityRepository $entityRepository = null,
        ?DocumentRepository $documentRepository = null,
        ?AISitemapGenerator $sitemapGenerator = null,
        ?ChangeFeedGenerator $changeFeedGenerator = null,
        ?LlmsTxtGenerator $llmsTxtGenerator = null,
        ?object $database = null
    ) {
        global $wpdb;

        $this->wpdb = $database ?? $wpdb;
        $this->entityRepository = $entityRepository ?? new EntityRepository();
        $this->documentRepository = $documentRepository ?? new DocumentRepository();
        $this->sitemapGenerator = $sitemapGenerator ?? new AISitemapGenerator();
        $this->changeFeedGenerator = $changeFeedGenerator ?? new ChangeFeedGenerator();
        $this->llmsTxtGenerator = $llmsTxtGenerator ?? new LlmsTxtGenerator();
    }

    /**
     * Get a consolidated semantic SEO health report.
     *
     * @return array<string, mixed>
     */
    public function get_report(): array
    {
        $schemaPostTypes = $this->get_schema_post_types();
        $kbPostTypes = $this->get_kb_post_types();

        $entityStats = $this->entityRepository->get_stats();
        $docStats = $this->documentRepository->get_stats();
        $changeActivity = $this->changeFeedGenerator->getActivitySummary();
        $indexedPages = $this->sitemapGenerator->getIndexedPages();
        $curatedPages = $this->llmsTxtGenerator->getCuratedPages();

        $schemaEligiblePosts = $this->count_published_posts($schemaPostTypes);
        $schemaDisabledPosts = $this->count_disabled_schema_posts($schemaPostTypes);
        $schemaCachedPosts = $this->count_schema_cached_posts($schemaPostTypes);
        $schemaValidPosts = $this->count_valid_schema_posts($schemaPostTypes);
        $schemaFlaggedForRefresh = $this->count_flagged_schema_posts($schemaPostTypes);
        $schemaStalePosts = max(
            $this->count_stale_schema_posts($schemaPostTypes),
            $schemaFlaggedForRefresh
        );
        $schemaTrackablePosts = max(0, $schemaEligiblePosts - $schemaDisabledPosts);
        $schemaMissingPosts = max(
            0,
            $schemaTrackablePosts - $schemaValidPosts
        );

        $postsWithEntities = $this->count_posts_with_entities($schemaPostTypes);
        $postsWithoutEntities = max(0, $schemaEligiblePosts - $postsWithEntities);
        $avgEntitiesPerPost = $this->get_average_entities_per_post($schemaPostTypes);

        $kbEligiblePosts = $this->count_published_posts($kbPostTypes);
        $kbIndexedDocs = (int) ($docStats['indexed_docs'] ?? 0);
        $kbPendingDocs = (int) ($docStats['pending_docs'] ?? 0);
        $kbChunkedDocs = (int) ($docStats['chunked_docs'] ?? 0);
        $kbFailedDocs = (int) ($docStats['failed_docs'] ?? 0);
        $kbExcludedDocs = (int) ($docStats['excluded_docs'] ?? 0);
        $kbTrackedDocs = (int) ($docStats['total_docs'] ?? 0);

        $schema = [
            'enabled'             => (bool) get_option('vibe_ai_schema_injection_enabled', true),
            'post_types'          => $schemaPostTypes,
            'eligible_posts'      => $schemaEligiblePosts,
            'cached_posts'        => $schemaCachedPosts,
            'valid_cached_posts'  => $schemaValidPosts,
            'missing_posts'       => $schemaMissingPosts,
            'stale_posts'         => $schemaStalePosts,
            'flagged_for_refresh' => $schemaFlaggedForRefresh,
            'disabled_posts'      => $schemaDisabledPosts,
            'coverage_ratio'      => $this->calculate_ratio($schemaValidPosts, $schemaTrackablePosts),
            'cache_version'       => SchemaGenerator::SCHEMA_VERSION,
        ];

        $entities = [
            'total_entities'         => (int) ($entityStats['total_entities'] ?? 0),
            'total_mentions'         => (int) ($entityStats['total_mentions'] ?? 0),
            'avg_confidence'         => (float) ($entityStats['avg_confidence'] ?? 0),
            'posts_with_entities'    => $postsWithEntities,
            'posts_without_entities' => $postsWithoutEntities,
            'coverage_ratio'         => $this->calculate_ratio($postsWithEntities, $schemaEligiblePosts),
            'avg_entities_per_post'  => $avgEntitiesPerPost,
        ];

        $knowledgeBase = [
            'enabled'         => Config::isKBEnabled(),
            'post_types'      => $kbPostTypes,
            'eligible_posts'  => $kbEligiblePosts,
            'tracked_docs'    => $kbTrackedDocs,
            'indexed_docs'    => $kbIndexedDocs,
            'pending_docs'    => $kbPendingDocs,
            'chunked_docs'    => $kbChunkedDocs,
            'failed_docs'     => $kbFailedDocs,
            'excluded_docs'   => $kbExcludedDocs,
            'coverage_ratio'  => $this->calculate_ratio($kbIndexedDocs, $kbEligiblePosts),
            'last_indexed_at' => $this->documentRepository->get_last_indexed_at(),
        ];

        $aiPublishing = [
            'llms_txt' => [
                'url'             => home_url('/llms.txt'),
                'ready'           => !empty($indexedPages),
                'indexed_pages'   => count($indexedPages),
                'curated_pages'   => count($curatedPages),
                'entry_limit'     => 100,
            ],
            'ai_sitemap' => [
                'url'           => home_url('/ai-sitemap'),
                'ready'         => !empty($indexedPages),
                'indexed_pages' => count($indexedPages),
                'content_hash'  => $this->documentRepository->calculateContentHash(),
            ],
            'changes' => [
                'url'            => home_url('/changes'),
                'ready'          => ((int) ($changeActivity['total_documents'] ?? 0)) > 0,
                'total_documents'=> (int) ($changeActivity['total_documents'] ?? 0),
                'changes_24h'    => (int) ($changeActivity['changes_24h'] ?? 0),
                'changes_7d'     => (int) ($changeActivity['changes_7d'] ?? 0),
                'last_update'    => $changeActivity['last_update'] ?? null,
                'feed_hash'      => $changeActivity['feed_hash'] ?? '',
            ],
        ];

        $checks = $this->build_checks($schema, $entities, $knowledgeBase, $aiPublishing);
        $summary = $this->build_summary($checks);

        return [
            'generated_at'   => gmdate('c'),
            'summary'        => $summary,
            'schema'         => $schema,
            'entities'       => $entities,
            'knowledge_base' => $knowledgeBase,
            'ai_publishing'  => $aiPublishing,
            'checks'         => $checks,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function get_schema_post_types(): array
    {
        $postTypes = (array) apply_filters(
            'vibe_ai_schema_post_types',
            apply_filters('vibe_ai_post_types', Config::DEFAULT_POST_TYPES)
        );

        return $this->sanitize_post_types($postTypes);
    }

    /**
     * @return array<int, string>
     */
    protected function get_kb_post_types(): array
    {
        $postTypes = get_option('vibe_ai_kb_post_types', Config::DEFAULT_POST_TYPES);

        return $this->sanitize_post_types(is_array($postTypes) ? $postTypes : Config::DEFAULT_POST_TYPES);
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_published_posts(array $postTypes): int
    {
        if (empty($postTypes)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $query = $this->wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ({$placeholders})",
            ...$postTypes
        );

        return (int) $this->wpdb->get_var($query);
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_schema_cached_posts(array $postTypes): int
    {
        return $this->count_postmeta_posts($postTypes, Config::META_SCHEMA_CACHE);
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_valid_schema_posts(array $postTypes): int
    {
        if (empty($postTypes)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $params = array_merge(
            [
                Config::META_SCHEMA_CACHE,
                Config::META_SCHEMA_VERSION,
            ],
            $postTypes,
            [SchemaGenerator::SCHEMA_VERSION]
        );
        $query = $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT posts.ID)
             FROM {$this->wpdb->posts} posts
             INNER JOIN {$this->wpdb->postmeta} schema_cache
                ON schema_cache.post_id = posts.ID
               AND schema_cache.meta_key = %s
               AND schema_cache.meta_value <> ''
             INNER JOIN {$this->wpdb->postmeta} schema_version
                ON schema_version.post_id = posts.ID
               AND schema_version.meta_key = %s
               AND schema_version.meta_value = %d
             WHERE posts.post_status = 'publish'
               AND posts.post_type IN ({$placeholders})",
            Config::META_SCHEMA_CACHE,
            Config::META_SCHEMA_VERSION,
            SchemaGenerator::SCHEMA_VERSION,
            ...$postTypes
        );

        return (int) $this->wpdb->get_var($query);
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_disabled_schema_posts(array $postTypes): int
    {
        return $this->count_postmeta_posts(
            $postTypes,
            '_vibe_ai_schema_disabled',
            ['1', 'true']
        );
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_flagged_schema_posts(array $postTypes): int
    {
        return $this->count_postmeta_posts(
            $postTypes,
            '_vibe_ai_needs_extraction',
            ['1', 'true']
        );
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_stale_schema_posts(array $postTypes): int
    {
        if (empty($postTypes)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $query = $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT posts.ID)
             FROM {$this->wpdb->posts} posts
             INNER JOIN {$this->wpdb->postmeta} schema_cache
                ON schema_cache.post_id = posts.ID
               AND schema_cache.meta_key = %s
               AND schema_cache.meta_value <> ''
             LEFT JOIN {$this->wpdb->postmeta} schema_version
                ON schema_version.post_id = posts.ID
               AND schema_version.meta_key = %s
             WHERE posts.post_status = 'publish'
               AND posts.post_type IN ({$placeholders})
               AND (
                    schema_version.meta_value IS NULL
                    OR schema_version.meta_value <> %d
               )",
            ...$params
        );

        return (int) $this->wpdb->get_var($query);
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function count_posts_with_entities(array $postTypes): int
    {
        if (empty($postTypes)) {
            return 0;
        }

        $mentionsTable = $this->wpdb->prefix . Config::TABLE_MENTIONS;
        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $query = $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT mentions.post_id)
             FROM {$mentionsTable} mentions
             INNER JOIN {$this->wpdb->posts} posts ON posts.ID = mentions.post_id
             WHERE posts.post_status = 'publish'
               AND posts.post_type IN ({$placeholders})",
            ...$postTypes
        );

        return (int) $this->wpdb->get_var($query);
    }

    /**
     * @param array<int, string> $postTypes
     */
    protected function get_average_entities_per_post(array $postTypes): float
    {
        if (empty($postTypes)) {
            return 0.0;
        }

        $mentionsTable = $this->wpdb->prefix . Config::TABLE_MENTIONS;
        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $query = $this->wpdb->prepare(
            "SELECT COALESCE(COUNT(*) / NULLIF(COUNT(DISTINCT mentions.post_id), 0), 0)
             FROM {$mentionsTable} mentions
             INNER JOIN {$this->wpdb->posts} posts ON posts.ID = mentions.post_id
             WHERE posts.post_status = 'publish'
               AND posts.post_type IN ({$placeholders})",
            ...$postTypes
        );

        return round((float) $this->wpdb->get_var($query), 2);
    }

    /**
     * @param array<int, string>       $postTypes
     * @param string                   $metaKey
     * @param array<int, string>|null  $truthyValues
     */
    protected function count_postmeta_posts(array $postTypes, string $metaKey, ?array $truthyValues = null): int
    {
        if (empty($postTypes)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $params = [$metaKey];
        $valueSql = '';

        if ($truthyValues !== null && !empty($truthyValues)) {
            $valuePlaceholders = implode(', ', array_fill(0, count($truthyValues), '%s'));
            $valueSql = " AND meta.meta_value IN ({$valuePlaceholders})";
            $params = array_merge($params, $truthyValues);
        }

        $params = array_merge($params, $postTypes);

        $query = $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT posts.ID)
             FROM {$this->wpdb->posts} posts
             INNER JOIN {$this->wpdb->postmeta} meta
                ON meta.post_id = posts.ID
               AND meta.meta_key = %s{$valueSql}
             WHERE posts.post_status = 'publish'
               AND posts.post_type IN ({$placeholders})",
            ...$params
        );

        return (int) $this->wpdb->get_var($query);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $entities
     * @param array<string, mixed> $knowledgeBase
     * @param array<string, mixed> $aiPublishing
     * @return array<int, array<string, mixed>>
     */
    protected function build_checks(
        array $schema,
        array $entities,
        array $knowledgeBase,
        array $aiPublishing
    ): array {
        $apiKeyConfigured = defined('VIBE_AI_OPENROUTER_KEY') && !empty(VIBE_AI_OPENROUTER_KEY);
        $schemaCoverage = (float) ($schema['coverage_ratio'] ?? 0.0);
        $entityCoverage = (float) ($entities['coverage_ratio'] ?? 0.0);
        $kbEnabled = (bool) ($knowledgeBase['enabled'] ?? false);
        $kbIndexedDocs = (int) ($knowledgeBase['indexed_docs'] ?? 0);
        $kbFailedDocs = (int) ($knowledgeBase['failed_docs'] ?? 0);
        $stalePosts = (int) ($schema['stale_posts'] ?? 0);
        $flaggedPosts = (int) ($schema['flagged_for_refresh'] ?? 0);

        return [
            $this->make_check(
                'api_key',
                'AI API key configured',
                $apiKeyConfigured ? 'pass' : 'fail',
                $apiKeyConfigured
                    ? 'Entity extraction can reach the configured AI provider.'
                    : 'Define VIBE_AI_OPENROUTER_KEY in wp-config.php before running extraction or KB jobs.'
            ),
            $this->make_check(
                'schema_injection',
                'Schema injection enabled',
                (bool) ($schema['enabled'] ?? false) ? 'pass' : 'fail',
                (bool) ($schema['enabled'] ?? false)
                    ? 'JSON-LD injection is enabled for eligible singular content.'
                    : 'Schema injection is disabled, so no frontend structured data will be emitted.'
            ),
            $this->make_check(
                'schema_coverage',
                'Structured data coverage',
                $this->status_from_ratio($schemaCoverage, 0.80, 0.45, (int) ($schema['eligible_posts'] ?? 0)),
                sprintf(
                    '%d of %d eligible posts currently have valid cached schema.',
                    (int) ($schema['valid_cached_posts'] ?? 0),
                    max(0, (int) ($schema['eligible_posts'] ?? 0) - (int) ($schema['disabled_posts'] ?? 0))
                )
            ),
            $this->make_check(
                'schema_freshness',
                'Structured data freshness',
                $stalePosts === 0 && $flaggedPosts === 0
                    ? 'pass'
                    : ($stalePosts <= 5 ? 'warn' : 'fail'),
                $stalePosts === 0 && $flaggedPosts === 0
                    ? 'No stale or refresh-queued schema was detected.'
                    : sprintf(
                        '%d posts have stale schema and %d are flagged for regeneration.',
                        $stalePosts,
                        $flaggedPosts
                    )
            ),
            $this->make_check(
                'entity_coverage',
                'Entity coverage',
                $this->status_from_ratio($entityCoverage, 0.60, 0.30, (int) ($schema['eligible_posts'] ?? 0)),
                sprintf(
                    '%d published posts currently have extracted entities.',
                    (int) ($entities['posts_with_entities'] ?? 0)
                )
            ),
            $this->make_check(
                'knowledge_base',
                'Knowledge Base indexing',
                !$kbEnabled
                    ? 'warn'
                    : ($kbFailedDocs > 0
                        ? 'fail'
                        : ($kbIndexedDocs > 0 ? 'pass' : 'warn')),
                !$kbEnabled
                    ? 'Knowledge Base indexing is disabled.'
                    : ($kbFailedDocs > 0
                        ? sprintf('%d KB documents are currently failing.', $kbFailedDocs)
                        : sprintf('%d KB documents are indexed and ready for semantic search.', $kbIndexedDocs))
            ),
            $this->make_check(
                'ai_publishing',
                'AI discovery endpoints',
                (bool) ($aiPublishing['ai_sitemap']['ready'] ?? false) ? 'pass' : 'warn',
                (bool) ($aiPublishing['ai_sitemap']['ready'] ?? false)
                    ? 'llms.txt, AI sitemap, and change feed all have indexed content to expose.'
                    : 'Public AI discovery endpoints are live, but there is no indexed KB content behind them yet.'
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     * @return array<string, int|string>
     */
    protected function build_summary(array $checks): array
    {
        $scoreMap = [
            'pass' => 1.0,
            'warn' => 0.5,
            'fail' => 0.0,
        ];

        $passed = 0;
        $warnings = 0;
        $failed = 0;
        $score = 0.0;

        foreach ($checks as $check) {
            $status = $check['status'] ?? 'warn';
            $score += $scoreMap[$status] ?? 0.0;

            if ($status === 'pass') {
                $passed++;
                continue;
            }

            if ($status === 'fail') {
                $failed++;
                continue;
            }

            $warnings++;
        }

        $total = count($checks);
        $normalizedScore = $total > 0 ? (int) round(($score / $total) * 100) : 0;
        $status = 'critical';

        if ($normalizedScore >= 85) {
            $status = 'healthy';
        } elseif ($normalizedScore >= 65) {
            $status = 'attention';
        }

        return [
            'score'          => $normalizedScore,
            'status'         => $status,
            'checks_total'   => $total,
            'checks_passed'  => $passed,
            'checks_warning' => $warnings,
            'checks_failed'  => $failed,
        ];
    }

    /**
     * @param string $key
     * @param string $label
     * @param string $status
     * @param string $message
     * @return array<string, string>
     */
    protected function make_check(string $key, string $label, string $status, string $message): array
    {
        return [
            'key'     => $key,
            'label'   => $label,
            'status'  => $status,
            'message' => $message,
        ];
    }

    protected function calculate_ratio(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 3);
    }

    protected function status_from_ratio(float $ratio, float $passThreshold, float $warnThreshold, int $eligiblePosts): string
    {
        if ($eligiblePosts <= 0) {
            return 'warn';
        }

        if ($ratio >= $passThreshold) {
            return 'pass';
        }

        if ($ratio >= $warnThreshold) {
            return 'warn';
        }

        return 'fail';
    }

    /**
     * @param array<int, string> $postTypes
     * @return array<int, string>
     */
    private function sanitize_post_types(array $postTypes): array
    {
        $sanitized = array_map('sanitize_text_field', $postTypes);
        $sanitized = array_filter($sanitized, static fn ($postType) => $postType !== '');

        return array_values(array_unique($sanitized));
    }
}
