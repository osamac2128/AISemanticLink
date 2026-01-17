<?php
/**
 * LLMs.txt Generator for AI Entity Index
 *
 * Generates /llms.txt file content for AI crawlers.
 * Provides a curated list of important pages for LLMs following
 * the llmstxt.org specification.
 *
 * @package Vibe\AIIndex\Services\KB
 * @since 1.0.0
 * @see https://llmstxt.org/
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

use Vibe\AIIndex\Repositories\KB\DocumentRepository;

/**
 * Generates /llms.txt file for AI crawlers.
 *
 * Provides a curated list of important pages formatted according to
 * the llmstxt.org specification, helping AI systems understand
 * site structure and content priorities.
 */
class LlmsTxtGenerator
{
    /**
     * Document repository instance.
     *
     * @var DocumentRepository
     */
    private DocumentRepository $docRepo;

    /**
     * Option key for site description override.
     *
     * @var string
     */
    private const SITE_DESCRIPTION_OPTION = 'vibe_ai_llms_site_description';

    /**
     * Option key for curated sections.
     *
     * @var string
     */
    private const CURATED_SECTIONS_OPTION = 'vibe_ai_llms_sections';

    /**
     * Default maximum entries for full index.
     *
     * @var int
     */
    private const DEFAULT_MAX_ENTRIES = 100;

    /**
     * Initialize the generator.
     */
    public function __construct()
    {
        $this->docRepo = new DocumentRepository();
    }

    /**
     * Generate llms.txt content.
     *
     * @param array $options {
     *     Optional. Generation options.
     *
     *     @type string $mode                 Generation mode: 'curated' or 'full'. Default 'curated'.
     *     @type bool   $include_descriptions Whether to include page descriptions. Default true.
     *     @type int    $max_entries          Maximum entries for full index. Default 100.
     * }
     *
     * @return string The generated llms.txt content.
     */
    public function generate(array $options = []): string
    {
        $defaults = [
            'mode'                 => 'curated',
            'include_descriptions' => true,
            'max_entries'          => self::DEFAULT_MAX_ENTRIES,
        ];

        $options = wp_parse_args($options, $defaults);

        $siteDescription = $this->getSiteDescription();
        $siteName = get_bloginfo('name');

        $output = "# {$siteName}\n";
        $output .= "> {$siteDescription}\n\n";

        if ($options['mode'] === 'curated') {
            $output .= $this->generateCuratedSections($options['include_descriptions']);
        }

        // Always include full index section
        $output .= $this->generateFullIndex(
            $options['max_entries'],
            $options['include_descriptions']
        );

        /**
         * Filter the generated llms.txt content.
         *
         * @param string $output  The generated content.
         * @param array  $options The generation options.
         */
        return apply_filters('vibe_ai_llms_txt_content', $output, $options);
    }

    /**
     * Get curated/pinned pages from settings.
     *
     * @return array<array{post_id: int, title: string, url: string, description: string}>
     */
    public function getCuratedPages(): array
    {
        $pinnedIds = $this->docRepo->getPinnedPages();

        if (empty($pinnedIds)) {
            return $this->getDefaultCuratedPages();
        }

        $pages = [];
        foreach ($pinnedIds as $postId) {
            $post = get_post($postId);

            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $pages[] = [
                'post_id'     => $post->ID,
                'title'       => $post->post_title,
                'url'         => get_permalink($post->ID),
                'description' => $this->getPageDescription($post),
            ];
        }

        return $pages;
    }

    /**
     * Get all indexed KB pages.
     *
     * @param int $limit Maximum pages to return.
     *
     * @return array<array{post_id: int, title: string, url: string, description: string}>
     */
    public function getAllPages(int $limit = 100): array
    {
        $documents = $this->docRepo->getAllDocuments([
            'limit'   => $limit,
            'orderby' => 'post_title',
            'order'   => 'ASC',
        ]);

        $pages = [];
        foreach ($documents as $doc) {
            $post = get_post($doc['post_id']);

            if (!$post) {
                continue;
            }

            $pages[] = [
                'post_id'     => $doc['post_id'],
                'title'       => $doc['title'],
                'url'         => $doc['url'],
                'description' => $this->getPageDescription($post),
            ];
        }

        return $pages;
    }

    /**
     * Set pinned pages order.
     *
     * @param array<int> $postIds Array of post IDs in desired order.
     *
     * @return void
     */
    public function setPinnedPages(array $postIds): void
    {
        $this->docRepo->setPinnedPages($postIds);
    }

    /**
     * Get site description for header.
     *
     * @return string The site description.
     */
    public function getSiteDescription(): string
    {
        // Check for custom override
        $customDescription = get_option(self::SITE_DESCRIPTION_OPTION, '');

        if (!empty($customDescription)) {
            return $customDescription;
        }

        // Fall back to WordPress tagline
        $tagline = get_bloginfo('description');

        if (!empty($tagline)) {
            return $tagline;
        }

        // Generate a default description
        return sprintf(
            'Official website for %s. Browse our content to learn more.',
            get_bloginfo('name')
        );
    }

    /**
     * Set custom site description.
     *
     * @param string $description The description to set.
     *
     * @return bool True on success, false on failure.
     */
    public function setSiteDescription(string $description): bool
    {
        return update_option(
            self::SITE_DESCRIPTION_OPTION,
            sanitize_text_field($description)
        );
    }

    /**
     * Get curated sections configuration.
     *
     * @return array<array{title: string, pages: array<int>}>
     */
    public function getCuratedSections(): array
    {
        $sections = get_option(self::CURATED_SECTIONS_OPTION, []);

        if (!is_array($sections) || empty($sections)) {
            return $this->getDefaultSections();
        }

        return $sections;
    }

    /**
     * Set curated sections configuration.
     *
     * @param array<array{title: string, pages: array<int>}> $sections Section configuration.
     *
     * @return bool True on success, false on failure.
     */
    public function setCuratedSections(array $sections): bool
    {
        // Validate and sanitize
        $sanitized = [];
        foreach ($sections as $section) {
            if (!isset($section['title']) || !isset($section['pages'])) {
                continue;
            }

            $sanitized[] = [
                'title' => sanitize_text_field($section['title']),
                'pages' => array_map('intval', (array) $section['pages']),
            ];
        }

        return update_option(self::CURATED_SECTIONS_OPTION, $sanitized);
    }

    /**
     * Format output as llms.txt format.
     *
     * @param array  $pages           Array of page data.
     * @param string $siteDescription Site description.
     *
     * @return string Formatted llms.txt content.
     */
    private function format(array $pages, string $siteDescription): string
    {
        $siteName = get_bloginfo('name');

        $output = "# {$siteName}\n";
        $output .= "> {$siteDescription}\n\n";

        $output .= "## Pages\n";

        foreach ($pages as $page) {
            $line = "- [{$page['title']}]({$page['url']})";

            if (!empty($page['description'])) {
                $line .= " - {$page['description']}";
            }

            $output .= $line . "\n";
        }

        return $output;
    }

    /**
     * Generate curated sections output.
     *
     * @param bool $includeDescriptions Whether to include descriptions.
     *
     * @return string Formatted sections content.
     */
    private function generateCuratedSections(bool $includeDescriptions): string
    {
        $sections = $this->getCuratedSections();
        $output = '';

        foreach ($sections as $section) {
            if (empty($section['pages'])) {
                continue;
            }

            $output .= "## {$section['title']}\n";

            foreach ($section['pages'] as $postId) {
                $post = get_post($postId);

                if (!$post || $post->post_status !== 'publish') {
                    continue;
                }

                $url = get_permalink($post->ID);
                $path = wp_parse_url($url, PHP_URL_PATH) ?: $url;
                $line = "- [{$post->post_title}]({$path})";

                if ($includeDescriptions) {
                    $desc = $this->getPageDescription($post);
                    if (!empty($desc)) {
                        $line .= " - {$desc}";
                    }
                }

                $output .= $line . "\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * Generate full index section.
     *
     * @param int  $maxEntries          Maximum entries to include.
     * @param bool $includeDescriptions Whether to include descriptions.
     *
     * @return string Formatted full index content.
     */
    private function generateFullIndex(int $maxEntries, bool $includeDescriptions): string
    {
        $pages = $this->getAllPages($maxEntries);

        if (empty($pages)) {
            return '';
        }

        $output = "## Full Index\n";

        foreach ($pages as $page) {
            $path = wp_parse_url($page['url'], PHP_URL_PATH) ?: $page['url'];
            $line = "- [{$page['title']}]({$path})";

            if ($includeDescriptions && !empty($page['description'])) {
                // Truncate description for index
                $desc = wp_trim_words($page['description'], 10, '...');
                $line .= " - {$desc}";
            }

            $output .= $line . "\n";
        }

        return $output;
    }

    /**
     * Get page description from post.
     *
     * @param \WP_Post $post The post object.
     *
     * @return string The page description.
     */
    private function getPageDescription(\WP_Post $post): string
    {
        // Try post excerpt first
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        // Try Yoast SEO meta description
        $yoastDesc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        if (!empty($yoastDesc)) {
            return $yoastDesc;
        }

        // Try Rank Math meta description
        $rankMathDesc = get_post_meta($post->ID, 'rank_math_description', true);
        if (!empty($rankMathDesc)) {
            return $rankMathDesc;
        }

        // Generate from content
        return wp_trim_words(wp_strip_all_tags($post->post_content), 20, '...');
    }

    /**
     * Get default curated pages when none are configured.
     *
     * @return array<array{post_id: int, title: string, url: string, description: string}>
     */
    private function getDefaultCuratedPages(): array
    {
        $pages = [];

        // Try to find common important pages
        $importantSlugs = ['about', 'contact', 'faq', 'getting-started', 'documentation'];

        foreach ($importantSlugs as $slug) {
            $post = get_page_by_path($slug);

            if ($post && $post->post_status === 'publish') {
                $pages[] = [
                    'post_id'     => $post->ID,
                    'title'       => $post->post_title,
                    'url'         => get_permalink($post->ID),
                    'description' => $this->getPageDescription($post),
                ];
            }
        }

        // If no important pages found, get recent posts
        if (empty($pages)) {
            $recentPosts = get_posts([
                'post_type'      => ['post', 'page'],
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);

            foreach ($recentPosts as $post) {
                $pages[] = [
                    'post_id'     => $post->ID,
                    'title'       => $post->post_title,
                    'url'         => get_permalink($post->ID),
                    'description' => $this->getPageDescription($post),
                ];
            }
        }

        return $pages;
    }

    /**
     * Get default section configuration.
     *
     * @return array<array{title: string, pages: array<int>}>
     */
    private function getDefaultSections(): array
    {
        $sections = [];

        // Start Here section
        $startHerePages = [];
        $starterSlugs = ['getting-started', 'quick-start', 'introduction', 'overview'];

        foreach ($starterSlugs as $slug) {
            $post = get_page_by_path($slug);
            if ($post && $post->post_status === 'publish') {
                $startHerePages[] = $post->ID;
            }
        }

        if (!empty($startHerePages)) {
            $sections[] = [
                'title' => 'Start Here',
                'pages' => $startHerePages,
            ];
        }

        // Documentation section
        $docPages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'meta_query'     => [
                [
                    'key'     => '_wp_page_template',
                    'value'   => 'template-documentation.php',
                    'compare' => '=',
                ],
            ],
        ]);

        if (!empty($docPages)) {
            $sections[] = [
                'title' => 'Documentation',
                'pages' => wp_list_pluck($docPages, 'ID'),
            ];
        }

        return $sections;
    }
}
