<?php
/**
 * Change Feed Generator for AI Entity Index
 *
 * Generates JSON feed of recently changed KB documents.
 * Useful for AI agents to track content updates and maintain
 * synchronized knowledge of site content.
 *
 * @package Vibe\AIIndex\Services\KB
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

use Vibe\AIIndex\Repositories\KB\DocumentRepository;

/**
 * Generates JSON feed of recently changed KB documents.
 *
 * Provides change tracking capabilities for AI agents including
 * feed hashes for efficient change detection and incremental updates.
 */
class ChangeFeedGenerator
{
    /**
     * Document repository instance.
     *
     * @var DocumentRepository
     */
    private DocumentRepository $docRepo;

    /**
     * Feed version.
     *
     * @var string
     */
    public const FEED_VERSION = '1.0';

    /**
     * Default feed limit.
     *
     * @var int
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * Default lookback period (7 days).
     *
     * @var string
     */
    private const DEFAULT_SINCE = '-7 days';

    /**
     * Option key for last known feed hash.
     *
     * @var string
     */
    private const LAST_HASH_OPTION = 'vibe_ai_feed_last_hash';

    /**
     * Initialize the generator.
     */
    public function __construct()
    {
        $this->docRepo = new DocumentRepository();
    }

    /**
     * Generate change feed.
     *
     * @param array $options {
     *     Optional. Feed generation options.
     *
     *     @type string $since          ISO datetime to get changes since. Default 7 days ago.
     *     @type int    $limit          Maximum entries to return. Default 50.
     *     @type bool   $include_chunks Whether to include chunk details. Default false.
     * }
     *
     * @return array The change feed data.
     */
    public function generate(array $options = []): array
    {
        $defaults = [
            'since'          => gmdate('c', strtotime(self::DEFAULT_SINCE)),
            'limit'          => self::DEFAULT_LIMIT,
            'include_chunks' => false,
        ];

        $options = wp_parse_args($options, $defaults);

        $changes = $this->getRecentlyUpdated($options['since'], $options['limit']);
        $feedHash = $this->calculateFeedHash();
        $totalDocuments = $this->docRepo->getDocumentCount();

        $feed = [
            'feed_version'    => self::FEED_VERSION,
            'generated_at'    => gmdate('c'),
            'feed_hash'       => $feedHash,
            'site_url'        => get_site_url(),
            'site_name'       => get_bloginfo('name'),
            'total_documents' => $totalDocuments,
            'query'           => [
                'since' => $options['since'],
                'limit' => $options['limit'],
            ],
            'changes_count'   => count($changes),
            'changes'         => $changes,
        ];

        // Add links for pagination/navigation
        $feed['_links'] = [
            'self'    => $this->getFeedUrl($options['since'], $options['limit']),
            'sitemap' => AISitemapGenerator::getSitemapUrl('json'),
        ];

        /**
         * Filter the generated change feed data.
         *
         * @param array $feed    The feed data.
         * @param array $options The generation options.
         */
        return apply_filters('vibe_ai_change_feed', $feed, $options);
    }

    /**
     * Get recently updated documents.
     *
     * @param string $since ISO datetime string.
     * @param int    $limit Maximum entries to return.
     *
     * @return array<array{
     *     post_id: int,
     *     url: string,
     *     title: string,
     *     updated_at: string,
     *     content_hash: string,
     *     change_type: string
     * }>
     */
    public function getRecentlyUpdated(string $since, int $limit = 50): array
    {
        $documents = $this->docRepo->getRecentlyUpdated($since, $limit);

        $changes = [];
        foreach ($documents as $doc) {
            $changes[] = [
                'post_id'      => $doc['post_id'],
                'url'          => $doc['url'],
                'title'        => $doc['title'],
                'updated_at'   => $this->formatDateTime($doc['updated_at']),
                'content_hash' => $doc['content_hash'],
                'change_type'  => $doc['change_type'],
            ];
        }

        return $changes;
    }

    /**
     * Get documents updated since last fetch (using etag/timestamp).
     *
     * Compares the provided hash with the current feed hash to determine
     * if there are changes.
     *
     * @param string $lastKnownHash The client's last known feed hash.
     *
     * @return array {
     *     @type bool   $has_changes Whether there are changes since the hash.
     *     @type string $current_hash Current feed hash.
     *     @type array  $changes     Array of changes if any (empty if no changes).
     * }
     */
    public function getChangesSince(string $lastKnownHash): array
    {
        $currentHash = $this->calculateFeedHash();

        if ($lastKnownHash === $currentHash) {
            return [
                'has_changes'  => false,
                'current_hash' => $currentHash,
                'changes'      => [],
            ];
        }

        // Get timestamp from stored hash mapping if available
        $lastFetchTime = $this->getLastFetchTime($lastKnownHash);

        if ($lastFetchTime) {
            $changes = $this->getRecentlyUpdated($lastFetchTime, self::DEFAULT_LIMIT);
        } else {
            // If we can't determine the time, return recent changes
            $changes = $this->getRecentlyUpdated(
                gmdate('c', strtotime('-24 hours')),
                self::DEFAULT_LIMIT
            );
        }

        return [
            'has_changes'  => true,
            'current_hash' => $currentHash,
            'changes'      => $changes,
        ];
    }

    /**
     * Calculate feed hash for change detection.
     *
     * This hash represents the current state of all indexed content.
     * When this hash changes, clients know to fetch updated content.
     *
     * @return string MD5 hash of current content state.
     */
    public function calculateFeedHash(): string
    {
        return $this->docRepo->calculateContentHash();
    }

    /**
     * Store feed hash with timestamp for later reference.
     *
     * @param string $hash      The feed hash.
     * @param string $timestamp ISO datetime when hash was generated.
     *
     * @return void
     */
    public function storeFeedHash(string $hash, string $timestamp): void
    {
        $hashes = get_option('vibe_ai_feed_hash_history', []);

        // Keep only last 100 hashes
        if (count($hashes) >= 100) {
            $hashes = array_slice($hashes, -99, 99, true);
        }

        $hashes[$hash] = $timestamp;

        update_option('vibe_ai_feed_hash_history', $hashes);
    }

    /**
     * Get the timestamp associated with a feed hash.
     *
     * @param string $hash The feed hash.
     *
     * @return string|null ISO datetime or null if not found.
     */
    private function getLastFetchTime(string $hash): ?string
    {
        $hashes = get_option('vibe_ai_feed_hash_history', []);

        return $hashes[$hash] ?? null;
    }

    /**
     * Format datetime to ISO 8601.
     *
     * @param string $datetime The datetime string.
     *
     * @return string ISO 8601 formatted datetime.
     */
    private function formatDateTime(string $datetime): string
    {
        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return gmdate('c');
        }

        return gmdate('c', $timestamp);
    }

    /**
     * Get feed URL with optional parameters.
     *
     * @param string|null $since Optional since parameter.
     * @param int|null    $limit Optional limit parameter.
     *
     * @return string The feed URL.
     */
    private function getFeedUrl(?string $since = null, ?int $limit = null): string
    {
        $url = rest_url('vibe-ai/v1/kb/feed');

        $params = [];
        if ($since !== null) {
            $params['since'] = $since;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        return $url;
    }

    /**
     * Check if content has changed since a given hash.
     *
     * Quick check without generating full feed.
     *
     * @param string $hash The hash to compare against.
     *
     * @return bool True if content has changed.
     */
    public function hasChangedSince(string $hash): bool
    {
        return $this->calculateFeedHash() !== $hash;
    }

    /**
     * Get feed for API response with conditional GET support.
     *
     * @param string|null $ifNoneMatch Client's If-None-Match header value.
     * @param array       $options     Generation options.
     *
     * @return array {
     *     @type int   $status_code HTTP status code (200 or 304).
     *     @type array $headers     Response headers.
     *     @type array $body        Response body (empty for 304).
     * }
     */
    public function getConditionalResponse(?string $ifNoneMatch, array $options = []): array
    {
        $currentHash = $this->calculateFeedHash();

        // Check conditional GET
        if ($ifNoneMatch !== null) {
            $clientHash = trim($ifNoneMatch, '"');

            if ($clientHash === $currentHash) {
                return [
                    'status_code' => 304,
                    'headers'     => [
                        'ETag'          => '"' . $currentHash . '"',
                        'Cache-Control' => 'public, max-age=300',
                    ],
                    'body'        => [],
                ];
            }
        }

        // Generate full feed
        $feed = $this->generate($options);

        // Store hash for future reference
        $this->storeFeedHash($currentHash, gmdate('c'));

        return [
            'status_code' => 200,
            'headers'     => [
                'ETag'          => '"' . $currentHash . '"',
                'Cache-Control' => 'public, max-age=300',
                'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
            ],
            'body'        => $feed,
        ];
    }

    /**
     * Get summary of recent activity.
     *
     * Useful for dashboard/status displays.
     *
     * @return array {
     *     @type int    $total_documents Total indexed documents.
     *     @type int    $changes_24h     Changes in last 24 hours.
     *     @type int    $changes_7d      Changes in last 7 days.
     *     @type string $last_update     Timestamp of last update.
     *     @type string $feed_hash       Current feed hash.
     * }
     */
    public function getActivitySummary(): array
    {
        $changes24h = $this->getRecentlyUpdated(
            gmdate('c', strtotime('-24 hours')),
            1000
        );

        $changes7d = $this->getRecentlyUpdated(
            gmdate('c', strtotime('-7 days')),
            1000
        );

        // Get last update time
        $lastUpdate = null;
        if (!empty($changes24h)) {
            $lastUpdate = $changes24h[0]['updated_at'];
        } elseif (!empty($changes7d)) {
            $lastUpdate = $changes7d[0]['updated_at'];
        }

        return [
            'total_documents' => $this->docRepo->getDocumentCount(),
            'changes_24h'     => count($changes24h),
            'changes_7d'      => count($changes7d),
            'last_update'     => $lastUpdate,
            'feed_hash'       => $this->calculateFeedHash(),
        ];
    }
}
