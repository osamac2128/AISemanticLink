<?php
/**
 * Content chunking service for Knowledge Base indexing.
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

use Vibe\AIIndex\Config;

/**
 * Chunks content into embedding-ready segments.
 *
 * Respects heading boundaries and token limits while maintaining semantic
 * coherence. Generates stable anchors for each chunk and tracks the heading
 * path (breadcrumb) for context.
 */
class Chunker
{
    /**
     * Default target tokens per chunk.
     */
    private const DEFAULT_TARGET_TOKENS = 512;

    /**
     * Default overlap tokens between chunks.
     */
    private const DEFAULT_OVERLAP_TOKENS = 50;

    /**
     * Minimum chunk size in tokens.
     */
    private const MIN_CHUNK_TOKENS = 50;

    /**
     * Maximum heading level to use for splitting (H2, H3, H4).
     */
    private const MAX_SPLIT_HEADING_LEVEL = 4;

    /**
     * Token estimator service.
     *
     * @var TokenEstimator
     */
    private TokenEstimator $tokenEstimator;

    /**
     * Anchor generator service.
     *
     * @var AnchorGenerator
     */
    private AnchorGenerator $anchorGenerator;

    /**
     * Target tokens per chunk.
     *
     * @var int
     */
    private int $targetTokens;

    /**
     * Overlap tokens between chunks.
     *
     * @var int
     */
    private int $overlapTokens;

    /**
     * Constructor.
     *
     * @param TokenEstimator  $tokenEstimator  Token estimation service.
     * @param AnchorGenerator $anchorGenerator Anchor generation service.
     * @param int|null        $targetTokens    Optional target tokens per chunk.
     * @param int|null        $overlapTokens   Optional overlap tokens between chunks.
     */
    public function __construct(
        TokenEstimator $tokenEstimator,
        AnchorGenerator $anchorGenerator,
        ?int $targetTokens = null,
        ?int $overlapTokens = null
    ) {
        $this->tokenEstimator = $tokenEstimator;
        $this->anchorGenerator = $anchorGenerator;
        $this->targetTokens = $targetTokens ?? self::DEFAULT_TARGET_TOKENS;
        $this->overlapTokens = $overlapTokens ?? self::DEFAULT_OVERLAP_TOKENS;
    }

    /**
     * Chunk normalized content.
     *
     * Splits content into chunks suitable for embedding generation while:
     * - Respecting heading boundaries (H2/H3/H4)
     * - Maintaining target token limits
     * - Adding overlap between chunks for context
     * - Tracking the heading path (breadcrumb) for each chunk
     *
     * @param string $content     Normalized plain text from ContentNormalizer.
     * @param array  $headings    Heading structure from ContentNormalizer.
     * @param int    $postId      WordPress post ID for anchor generation.
     * @param string $contentHash Content hash from ContentNormalizer.
     *
     * @return array<array{
     *   chunk_index: int,
     *   anchor: string,
     *   heading_path: array,
     *   chunk_text: string,
     *   chunk_hash: string,
     *   start_offset: int,
     *   end_offset: int,
     *   token_estimate: int
     * }> Array of chunk data.
     */
    public function chunk(string $content, array $headings, int $postId, string $contentHash): array
    {
        // Handle empty content
        if (empty(trim($content))) {
            return [];
        }

        // Map headings to plain text positions
        $mapped_headings = $this->mapHeadingsToPlainText($content, $headings);

        // Split content by heading boundaries first
        $sections = $this->splitByHeadings($content, $mapped_headings);

        // Process each section into chunks
        $chunks = [];
        $chunk_index = 0;
        $previous_chunk_text = '';

        foreach ($sections as $section) {
            $section_chunks = $this->processSection(
                $section,
                $postId,
                $contentHash,
                $chunk_index,
                $previous_chunk_text
            );

            foreach ($section_chunks as $chunk) {
                $chunks[] = $chunk;
                $chunk_index++;
                $previous_chunk_text = $chunk['chunk_text'];
            }
        }

        return $chunks;
    }

    /**
     * Get current heading path at position.
     *
     * Returns the stack of headings that are "active" at the given position,
     * representing the document structure path to that point.
     *
     * @param array $headings Array of heading data with positions.
     * @param int   $position Current position in the content.
     *
     * @return array<string> Array of heading texts representing the path.
     */
    private function getHeadingPathAtPosition(array $headings, int $position): array
    {
        $path = [];
        $level_stack = [];

        foreach ($headings as $heading) {
            // Only consider headings before our position
            if ($heading['position'] > $position) {
                break;
            }

            $level = $heading['level'];
            $text = $heading['text'];

            // Pop headings from stack that are same level or deeper
            while (!empty($level_stack) && end($level_stack)['level'] >= $level) {
                array_pop($level_stack);
                array_pop($path);
            }

            // Push this heading
            $level_stack[] = ['level' => $level, 'text' => $text];
            $path[] = $text;
        }

        return $path;
    }

    /**
     * Find natural break point near target position.
     *
     * Looks for paragraph breaks, sentence endings, or other natural
     * boundaries near the target position.
     *
     * @param string $text      The text to search.
     * @param int    $targetPos Target position to find break near.
     *
     * @return int The position of the natural break point.
     */
    private function findBreakPoint(string $text, int $targetPos): int
    {
        $text_length = strlen($text);

        // If target is at or past end, return end
        if ($targetPos >= $text_length) {
            return $text_length;
        }

        // Search window around target position (10% of target)
        $window = max(50, (int) ($targetPos * 0.1));
        $search_start = max(0, $targetPos - $window);
        $search_end = min($text_length, $targetPos + $window);

        // Extract the search region
        $search_region = substr($text, $search_start, $search_end - $search_start);

        // Priority 1: Look for paragraph break (double newline)
        $paragraph_break = strrpos(substr($search_region, 0, $targetPos - $search_start + $window), "\n\n");
        if ($paragraph_break !== false) {
            return $search_start + $paragraph_break + 2; // Position after the break
        }

        // Priority 2: Look for sentence ending (. ! ?)
        $best_sentence_end = -1;
        $search_substr = substr($search_region, 0, $targetPos - $search_start + $window);

        // Find sentence endings followed by space or newline
        if (preg_match_all('/[.!?][\s\n]/u', $search_substr, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $pos = $match[1] + 2; // Position after the punctuation and space
                if ($pos > $best_sentence_end) {
                    $best_sentence_end = $pos;
                }
            }
        }

        if ($best_sentence_end > 0) {
            return $search_start + $best_sentence_end;
        }

        // Priority 3: Look for single newline
        $newline_pos = strrpos(substr($search_region, 0, $targetPos - $search_start + $window), "\n");
        if ($newline_pos !== false) {
            return $search_start + $newline_pos + 1;
        }

        // Priority 4: Look for space (word boundary)
        $space_pos = strrpos(substr($search_region, 0, $targetPos - $search_start + $window), ' ');
        if ($space_pos !== false) {
            return $search_start + $space_pos + 1;
        }

        // Fallback: Use target position
        return $targetPos;
    }

    /**
     * Create overlap with previous chunk.
     *
     * Extracts text from the end of the previous chunk to prepend to
     * the current chunk for context continuity.
     *
     * @param string $previousChunk The previous chunk's text.
     * @param int    $overlapTokens Number of tokens to overlap.
     *
     * @return string The overlap text.
     */
    private function createOverlap(string $previousChunk, int $overlapTokens): string
    {
        if (empty($previousChunk) || $overlapTokens <= 0) {
            return '';
        }

        // Estimate characters needed for overlap
        $estimated_chars = $this->tokenEstimator->estimateCharsForTokens($overlapTokens);

        // If previous chunk is shorter, use it all
        if (strlen($previousChunk) <= $estimated_chars) {
            return $previousChunk;
        }

        // Get the last portion of the previous chunk
        $overlap_text = substr($previousChunk, -$estimated_chars);

        // Try to start at a word boundary
        $first_space = strpos($overlap_text, ' ');
        if ($first_space !== false && $first_space < strlen($overlap_text) / 2) {
            $overlap_text = substr($overlap_text, $first_space + 1);
        }

        return $overlap_text;
    }

    /**
     * Map heading positions from HTML to plain text.
     *
     * Since headings are extracted from HTML but we chunk plain text,
     * we need to find the corresponding positions in the plain text.
     *
     * @param string $plainText The plain text content.
     * @param array  $headings  Headings with HTML positions.
     *
     * @return array Headings with plain text positions.
     */
    private function mapHeadingsToPlainText(string $plainText, array $headings): array
    {
        $mapped = [];

        foreach ($headings as $heading) {
            $text = $heading['text'];

            // Find the heading text in plain text
            $pos = stripos($plainText, $text);

            if ($pos !== false) {
                $mapped[] = [
                    'level' => $heading['level'],
                    'text' => $text,
                    'position' => $pos,
                ];
            }
        }

        // Sort by position
        usort($mapped, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        return $mapped;
    }

    /**
     * Split content by heading boundaries.
     *
     * @param string $content  The plain text content.
     * @param array  $headings Mapped headings with positions.
     *
     * @return array<array{text: string, heading_path: array, start_offset: int, end_offset: int}> Sections.
     */
    private function splitByHeadings(string $content, array $headings): array
    {
        $sections = [];
        $content_length = strlen($content);

        // If no headings, treat entire content as one section
        if (empty($headings)) {
            return [[
                'text' => $content,
                'heading_path' => [],
                'start_offset' => 0,
                'end_offset' => $content_length,
            ]];
        }

        // Filter to only splitting headings (H2, H3, H4)
        $split_headings = array_filter($headings, function ($h) {
            return $h['level'] >= 2 && $h['level'] <= self::MAX_SPLIT_HEADING_LEVEL;
        });

        $split_headings = array_values($split_headings);

        // If no split headings after filter, treat as one section
        if (empty($split_headings)) {
            return [[
                'text' => $content,
                'heading_path' => [],
                'start_offset' => 0,
                'end_offset' => $content_length,
            ]];
        }

        // Add content before first heading as a section
        $first_heading_pos = $split_headings[0]['position'];
        if ($first_heading_pos > 0) {
            $text = trim(substr($content, 0, $first_heading_pos));
            if (!empty($text)) {
                $sections[] = [
                    'text' => $text,
                    'heading_path' => [],
                    'start_offset' => 0,
                    'end_offset' => $first_heading_pos,
                ];
            }
        }

        // Process each heading section
        for ($i = 0; $i < count($split_headings); $i++) {
            $heading = $split_headings[$i];
            $start = $heading['position'];

            // End is either next heading or content end
            $end = ($i < count($split_headings) - 1)
                ? $split_headings[$i + 1]['position']
                : $content_length;

            $text = trim(substr($content, $start, $end - $start));

            if (!empty($text)) {
                $sections[] = [
                    'text' => $text,
                    'heading_path' => $this->getHeadingPathAtPosition($headings, $start),
                    'start_offset' => $start,
                    'end_offset' => $end,
                ];
            }
        }

        return $sections;
    }

    /**
     * Process a section into one or more chunks.
     *
     * @param array  $section            Section data.
     * @param int    $postId             Post ID for anchor generation.
     * @param string $contentHash        Content hash for anchor generation.
     * @param int    $startChunkIndex    Starting chunk index.
     * @param string $previousChunkText  Previous chunk text for overlap.
     *
     * @return array<array> Array of chunk data.
     */
    private function processSection(
        array $section,
        int $postId,
        string $contentHash,
        int $startChunkIndex,
        string $previousChunkText
    ): array {
        $text = $section['text'];
        $heading_path = $section['heading_path'];
        $section_start = $section['start_offset'];

        // Check if section needs splitting
        $section_tokens = $this->tokenEstimator->estimate($text);

        if ($section_tokens <= $this->targetTokens) {
            // Section fits in one chunk
            $chunk_text = $text;

            // Add overlap from previous chunk if this isn't the first chunk
            if ($startChunkIndex > 0 && !empty($previousChunkText)) {
                $overlap = $this->createOverlap($previousChunkText, $this->overlapTokens);
                if (!empty($overlap)) {
                    $chunk_text = $overlap . "\n\n" . $text;
                }
            }

            return [$this->createChunkData(
                $chunk_text,
                $heading_path,
                $postId,
                $contentHash,
                $startChunkIndex,
                $section_start,
                $section['end_offset']
            )];
        }

        // Section needs splitting - use token estimator
        $sub_chunks = $this->tokenEstimator->splitToTokenLimit($text, $this->targetTokens);
        $chunks = [];
        $current_offset = $section_start;

        foreach ($sub_chunks as $i => $sub_chunk_text) {
            $chunk_index = $startChunkIndex + $i;
            $chunk_length = strlen($sub_chunk_text);

            // Find the actual position of this sub-chunk in the original text
            $sub_pos = strpos($text, trim(substr($sub_chunk_text, 0, 50)));
            if ($sub_pos !== false) {
                $current_offset = $section_start + $sub_pos;
            }

            // Add overlap from previous chunk
            $final_chunk_text = $sub_chunk_text;
            if ($chunk_index > 0) {
                $prev_text = ($i > 0) ? $sub_chunks[$i - 1] : $previousChunkText;
                if (!empty($prev_text)) {
                    $overlap = $this->createOverlap($prev_text, $this->overlapTokens);
                    if (!empty($overlap)) {
                        $final_chunk_text = $overlap . "\n\n" . $sub_chunk_text;
                    }
                }
            }

            $chunks[] = $this->createChunkData(
                $final_chunk_text,
                $heading_path,
                $postId,
                $contentHash,
                $chunk_index,
                $current_offset,
                $current_offset + $chunk_length
            );

            $current_offset += $chunk_length;
        }

        return $chunks;
    }

    /**
     * Create chunk data array.
     *
     * @param string $text        Chunk text.
     * @param array  $headingPath Heading path for this chunk.
     * @param int    $postId      Post ID.
     * @param string $contentHash Document content hash.
     * @param int    $chunkIndex  Chunk index.
     * @param int    $startOffset Start offset in original content.
     * @param int    $endOffset   End offset in original content.
     *
     * @return array Chunk data.
     */
    private function createChunkData(
        string $text,
        array $headingPath,
        int $postId,
        string $contentHash,
        int $chunkIndex,
        int $startOffset,
        int $endOffset
    ): array {
        $chunk_hash = hash('sha256', $text);

        return [
            'chunk_index' => $chunkIndex,
            'anchor' => $this->anchorGenerator->generate($postId, $headingPath, $chunkIndex, $contentHash),
            'heading_path' => $headingPath,
            'chunk_text' => $text,
            'chunk_hash' => $chunk_hash,
            'start_offset' => $startOffset,
            'end_offset' => $endOffset,
            'token_estimate' => $this->tokenEstimator->estimate($text),
        ];
    }

    /**
     * Get the target tokens setting.
     *
     * @return int Target tokens per chunk.
     */
    public function getTargetTokens(): int
    {
        return $this->targetTokens;
    }

    /**
     * Get the overlap tokens setting.
     *
     * @return int Overlap tokens between chunks.
     */
    public function getOverlapTokens(): int
    {
        return $this->overlapTokens;
    }

    /**
     * Set the target tokens per chunk.
     *
     * @param int $tokens Target token count.
     *
     * @return self
     */
    public function setTargetTokens(int $tokens): self
    {
        if ($tokens < self::MIN_CHUNK_TOKENS) {
            throw new \InvalidArgumentException(
                sprintf('Target tokens must be at least %d', self::MIN_CHUNK_TOKENS)
            );
        }

        $this->targetTokens = $tokens;
        return $this;
    }

    /**
     * Set the overlap tokens between chunks.
     *
     * @param int $tokens Overlap token count.
     *
     * @return self
     */
    public function setOverlapTokens(int $tokens): self
    {
        if ($tokens < 0) {
            throw new \InvalidArgumentException('Overlap tokens cannot be negative');
        }

        if ($tokens >= $this->targetTokens) {
            throw new \InvalidArgumentException('Overlap tokens must be less than target tokens');
        }

        $this->overlapTokens = $tokens;
        return $this;
    }
}
