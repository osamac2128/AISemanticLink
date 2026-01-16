<?php
/**
 * AI Sitemap Generator for AI Entity Index
 *
 * Generates AI-specific sitemap of KB-indexed pages.
 * Separate from standard WordPress sitemap, this provides
 * enhanced metadata for AI crawlers and agents.
 *
 * @package Vibe\AIIndex\Services\KB
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

use Vibe\AIIndex\Repositories\KB\DocumentRepository;

/**
 * Generates AI-specific sitemap of KB-indexed pages.
 *
 * Provides both XML and JSON sitemap formats with enhanced
 * metadata including content hashes, chunk counts, and
 * AI-specific annotations.
 */
class AISitemapGenerator
{
    /**
     * Document repository instance.
     *
     * @var DocumentRepository
     */
    private DocumentRepository $docRepo;

    /**
     * AI sitemap XML namespace.
     *
     * @var string
     */
    public const AI_NAMESPACE = 'http://vibeai.dev/ai-sitemap';

    /**
     * Sitemap rewrite rule query var.
     *
     * @var string
     */
    public const QUERY_VAR = 'vibe_ai_sitemap';

    /**
     * Initialize the generator.
     */
    public function __construct()
    {
        $this->docRepo = new DocumentRepository();
    }

    /**
     * Generate XML sitemap.
     *
     * @return string XML sitemap content.
     */
    public function generateXML(): string
    {
        $pages = $this->getIndexedPages();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        // Start urlset with namespaces
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:ai', self::AI_NAMESPACE);

        foreach ($pages as $page) {
            $xml->startElement('url');

            // Standard sitemap elements
            $xml->writeElement('loc', $page['url']);
            $xml->writeElement('lastmod', $this->formatDateTime($page['lastmod']));

            // AI-specific elements
            $xml->writeElement('ai:title', $page['title']);
            $xml->writeElement('ai:chunks', (string) $page['chunk_count']);
            $xml->writeElement('ai:hash', $page['content_hash']);

            $xml->endElement(); // url
        }

        $xml->endElement(); // urlset
        $xml->endDocument();

        $content = $xml->outputMemory();

        /**
         * Filter the generated AI sitemap XML content.
         *
         * @param string $content The generated XML.
         * @param array  $pages   The indexed pages data.
         */
        return apply_filters('vibe_ai_sitemap_xml', $content, $pages);
    }

    /**
     * Generate JSON sitemap.
     *
     * @return array JSON-serializable sitemap data.
     */
    public function generateJSON(): array
    {
        $pages = $this->getIndexedPages();
        $siteUrl = get_site_url();

        $sitemap = [
            'sitemap_version' => '1.0',
            'generated_at'    => gmdate('c'),
            'site_url'        => $siteUrl,
            'site_name'       => get_bloginfo('name'),
            'total_pages'     => count($pages),
            'content_hash'    => $this->docRepo->calculateContentHash(),
            'pages'           => [],
        ];

        foreach ($pages as $page) {
            $sitemap['pages'][] = [
                'url'          => $page['url'],
                'title'        => $page['title'],
                'lastmod'      => $this->formatDateTime($page['lastmod']),
                'content_hash' => $page['content_hash'],
                'chunk_count'  => $page['chunk_count'],
            ];
        }

        /**
         * Filter the generated AI sitemap JSON data.
         *
         * @param array $sitemap The sitemap data.
         * @param array $pages   The indexed pages data.
         */
        return apply_filters('vibe_ai_sitemap_json', $sitemap, $pages);
    }

    /**
     * Get indexed pages with metadata.
     *
     * @return array<array{
     *     url: string,
     *     title: string,
     *     lastmod: string,
     *     content_hash: string,
     *     chunk_count: int
     * }>
     */
    public function getIndexedPages(): array
    {
        $documents = $this->docRepo->getAllDocuments([
            'limit'   => 10000, // High limit for sitemap
            'orderby' => 'post_modified',
            'order'   => 'DESC',
        ]);

        $pages = [];
        foreach ($documents as $doc) {
            $pages[] = [
                'url'          => $doc['url'],
                'title'        => $doc['title'],
                'lastmod'      => $doc['updated_at'],
                'content_hash' => $doc['content_hash'],
                'chunk_count'  => $doc['chunk_count'],
            ];
        }

        return $pages;
    }

    /**
     * Register rewrite rules for /ai-sitemap.xml
     *
     * Should be called on 'init' hook.
     *
     * @return void
     */
    public static function registerRewriteRules(): void
    {
        // Add rewrite rule for XML sitemap
        add_rewrite_rule(
            '^ai-sitemap\.xml$',
            'index.php?' . self::QUERY_VAR . '=xml',
            'top'
        );

        // Add rewrite rule for JSON sitemap
        add_rewrite_rule(
            '^ai-sitemap\.json$',
            'index.php?' . self::QUERY_VAR . '=json',
            'top'
        );

        // Register query var
        add_filter('query_vars', function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
    }

    /**
     * Handle sitemap request.
     *
     * Should be called on 'template_redirect' hook.
     *
     * @return void
     */
    public function handleRequest(): void
    {
        $format = get_query_var(self::QUERY_VAR);

        if (empty($format)) {
            return;
        }

        // Prevent caching plugins from interfering
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        switch ($format) {
            case 'xml':
                $this->outputXML();
                break;

            case 'json':
                $this->outputJSON();
                break;

            default:
                return;
        }

        exit;
    }

    /**
     * Output XML sitemap with proper headers.
     *
     * @return void
     */
    private function outputXML(): void
    {
        $content = $this->generateXML();

        // Set headers
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        // Calculate ETag
        $etag = md5($content);
        header('ETag: "' . $etag . '"');

        // Handle conditional GET
        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH'], '"');
            if ($clientEtag === $etag) {
                http_response_code(304);
                return;
            }
        }

        echo $content;
    }

    /**
     * Output JSON sitemap with proper headers.
     *
     * @return void
     */
    private function outputJSON(): void
    {
        $data = $this->generateJSON();

        // Set headers
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        // Calculate ETag based on content hash
        $etag = $data['content_hash'];
        header('ETag: "' . $etag . '"');

        // Handle conditional GET
        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH'], '"');
            if ($clientEtag === $etag) {
                http_response_code(304);
                return;
            }
        }

        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
     * Get sitemap URL.
     *
     * @param string $format Format: 'xml' or 'json'.
     *
     * @return string The sitemap URL.
     */
    public static function getSitemapUrl(string $format = 'xml'): string
    {
        $extension = $format === 'json' ? 'json' : 'xml';
        return home_url("/ai-sitemap.{$extension}");
    }

    /**
     * Flush rewrite rules.
     *
     * Should be called on plugin activation.
     *
     * @return void
     */
    public static function flushRewriteRules(): void
    {
        self::registerRewriteRules();
        flush_rewrite_rules();
    }
}
