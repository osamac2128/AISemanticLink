<?php
/**
 * Content normalization service for Knowledge Base indexing.
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

/**
 * Normalizes WordPress content for KB indexing.
 *
 * Extracts clean text and heading structure from Gutenberg/Classic content.
 * Handles shortcodes, blocks, and HTML to produce plain text suitable for
 * chunking and embedding generation.
 */
class ContentNormalizer
{
    /**
     * Pattern for matching heading tags (H1-H6).
     */
    private const HEADING_PATTERN = '/<h([1-6])(?:\s+[^>]*)?>(.*?)<\/h\1>/is';

    /**
     * Pattern for matching paragraph breaks.
     */
    private const PARAGRAPH_PATTERN = '/<\/p>\s*<p[^>]*>|<br\s*\/?>\s*<br\s*\/?>/i';

    /**
     * Normalize post content to plain text with heading structure.
     *
     * Processes a WordPress post and extracts normalized content along with
     * the heading structure and a content hash for change detection.
     *
     * @param int $post_id The WordPress post ID to normalize.
     *
     * @return array{content: string, headings: array, hash: string} Normalized content data.
     *
     * @throws \InvalidArgumentException If the post does not exist.
     */
    public function normalize(int $post_id): array
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            throw new \InvalidArgumentException(
                sprintf('Post with ID %d does not exist', $post_id)
            );
        }

        // Get the raw content
        $raw_content = $post->post_content;

        // Handle empty content
        if (empty(trim($raw_content))) {
            return [
                'content' => '',
                'headings' => [],
                'hash' => $this->computeHash(''),
            ];
        }

        // Extract headings before stripping HTML
        $processed_content = $this->processContent($raw_content);
        $headings = $this->extractHeadings($processed_content);

        // Convert to plain text
        $plain_text = $this->stripBlocks($raw_content);

        // Compute hash of normalized content
        $hash = $this->computeHash($plain_text);

        return [
            'content' => $plain_text,
            'headings' => $headings,
            'hash' => $hash,
        ];
    }

    /**
     * Strip Gutenberg blocks to plain text.
     *
     * Processes Gutenberg blocks, renders shortcodes, and strips all HTML
     * while preserving paragraph breaks as double newlines.
     *
     * @param string $content The raw WordPress content with blocks/shortcodes.
     *
     * @return string Plain text content with preserved paragraph structure.
     */
    public function stripBlocks(string $content): string
    {
        if (empty(trim($content))) {
            return '';
        }

        // Process Gutenberg blocks (renders block content)
        if (function_exists('do_blocks')) {
            $content = do_blocks($content);
        }

        // Process shortcodes
        if (function_exists('do_shortcode')) {
            $content = do_shortcode($content);
        }

        // Preserve paragraph breaks before stripping HTML
        $content = $this->preserveParagraphBreaks($content);

        // Strip all HTML tags
        $content = wp_strip_all_tags($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize multiple newlines to double newlines (paragraph breaks)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        // Normalize whitespace within lines (but preserve newlines)
        $content = preg_replace('/[^\S\n]+/', ' ', $content);

        // Trim each line
        $lines = array_map('trim', explode("\n", $content));
        $content = implode("\n", $lines);

        // Remove empty lines at start/end, normalize internal empty lines
        $content = trim($content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return $content;
    }

    /**
     * Extract heading structure (H1-H6) with positions.
     *
     * Parses HTML content and extracts all heading elements with their
     * hierarchy level, text content, and position in the document.
     *
     * @param string $content HTML content to extract headings from.
     *
     * @return array<array{level: int, text: string, position: int}> Array of heading data.
     */
    public function extractHeadings(string $content): array
    {
        if (empty(trim($content))) {
            return [];
        }

        $headings = [];

        // Match all heading tags
        if (preg_match_all(self::HEADING_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                $full_match = $match[0];
                $position = $match[1];
                $level = (int) $matches[1][$index][0];
                $text = $matches[2][$index][0];

                // Strip any nested HTML from heading text
                $text = wp_strip_all_tags($text);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim($text);

                // Skip empty headings
                if (empty($text)) {
                    continue;
                }

                // Calculate position in plain text (approximate)
                // We use the byte offset from the HTML, which will be adjusted
                // when used by the chunker
                $headings[] = [
                    'level' => $level,
                    'text' => $text,
                    'position' => $position,
                ];
            }
        }

        return $headings;
    }

    /**
     * Compute content hash for change detection.
     *
     * Uses SHA256 to generate a deterministic hash of the normalized content.
     * This can be used to detect when content has changed and needs re-processing.
     *
     * @param string $content The normalized content to hash.
     *
     * @return string The SHA256 hash of the content.
     */
    public function computeHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Get document metadata from post.
     *
     * Extracts relevant metadata including taxonomies, author information,
     * and dates for enriching knowledge base entries.
     *
     * @param int $post_id The WordPress post ID.
     *
     * @return array<string, mixed> Document metadata.
     *
     * @throws \InvalidArgumentException If the post does not exist.
     */
    public function getDocumentMeta(int $post_id): array
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            throw new \InvalidArgumentException(
                sprintf('Post with ID %d does not exist', $post_id)
            );
        }

        // Get author information
        $author_id = (int) $post->post_author;
        $author_data = get_userdata($author_id);

        $author = [
            'id' => $author_id,
            'name' => $author_data ? $author_data->display_name : '',
            'slug' => $author_data ? $author_data->user_nicename : '',
        ];

        // Get taxonomies
        $taxonomies = $this->getPostTaxonomies($post_id);

        // Get dates
        $dates = [
            'published' => $post->post_date,
            'published_gmt' => $post->post_date_gmt,
            'modified' => $post->post_modified,
            'modified_gmt' => $post->post_modified_gmt,
        ];

        // Get permalink
        $permalink = get_permalink($post_id);

        return [
            'post_id' => $post_id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'author' => $author,
            'taxonomies' => $taxonomies,
            'dates' => $dates,
            'permalink' => $permalink ?: '',
            'excerpt' => $post->post_excerpt,
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
        ];
    }

    /**
     * Process content to prepare for heading extraction.
     *
     * Renders blocks and shortcodes while preserving HTML structure
     * needed for heading extraction.
     *
     * @param string $content Raw WordPress content.
     *
     * @return string Processed HTML content.
     */
    private function processContent(string $content): string
    {
        // Process Gutenberg blocks
        if (function_exists('do_blocks')) {
            $content = do_blocks($content);
        }

        // Process shortcodes
        if (function_exists('do_shortcode')) {
            $content = do_shortcode($content);
        }

        return $content;
    }

    /**
     * Preserve paragraph breaks by converting to newlines.
     *
     * @param string $content HTML content.
     *
     * @return string Content with paragraph breaks converted to newlines.
     */
    private function preserveParagraphBreaks(string $content): string
    {
        // Convert closing paragraph tags to double newlines
        $content = preg_replace('/<\/p>/i', "\n\n", $content);

        // Convert br tags to single newlines
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);

        // Convert other block-level closing tags to newlines
        $block_tags = ['div', 'article', 'section', 'header', 'footer', 'li', 'tr', 'blockquote'];
        foreach ($block_tags as $tag) {
            $content = preg_replace('/<\/' . $tag . '>/i', "\n", $content);
        }

        // Convert heading closing tags to double newlines
        $content = preg_replace('/<\/h[1-6]>/i', "\n\n", $content);

        return $content;
    }

    /**
     * Get all taxonomies and terms for a post.
     *
     * @param int $post_id The post ID.
     *
     * @return array<string, array<array{id: int, name: string, slug: string}>> Taxonomies with terms.
     */
    private function getPostTaxonomies(int $post_id): array
    {
        $result = [];

        // Get all taxonomies for this post type
        $post_type = get_post_type($post_id);

        if ($post_type === false) {
            return $result;
        }

        $taxonomies = get_object_taxonomies($post_type);

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $result[$taxonomy] = [];

            foreach ($terms as $term) {
                $result[$taxonomy][] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        return $result;
    }
}
