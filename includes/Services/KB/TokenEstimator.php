<?php
/**
 * Token estimation service for text chunking.
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

/**
 * Estimates token counts for text chunks.
 *
 * Uses word-based approximation for token counting without requiring
 * external dependencies like tiktoken. Provides methods for estimating
 * token counts and splitting text to target token limits.
 */
class TokenEstimator
{
    /**
     * Average tokens per word (based on typical English text).
     *
     * Most tokenizers produce approximately 1.3 tokens per word on average
     * for standard English text.
     */
    private const TOKENS_PER_WORD = 1.3;

    /**
     * Average tokens per character (fallback for non-word content).
     *
     * Used for content that doesn't tokenize well by words (code, URLs, etc.).
     */
    private const TOKENS_PER_CHAR = 0.25;

    /**
     * Minimum word length to count as a full word.
     */
    private const MIN_WORD_LENGTH = 2;

    /**
     * Pattern for matching words.
     */
    private const WORD_PATTERN = '/[\p{L}\p{N}]+/u';

    /**
     * Estimate tokens in text.
     *
     * Uses a word-based approximation that accounts for typical tokenization
     * patterns. The estimate is intentionally slightly conservative to avoid
     * exceeding token limits.
     *
     * @param string $text The text to estimate tokens for.
     *
     * @return int Estimated token count.
     */
    public function estimate(string $text): int
    {
        if (empty(trim($text))) {
            return 0;
        }

        // Count words using Unicode-aware pattern
        $word_count = preg_match_all(self::WORD_PATTERN, $text, $matches);

        if ($word_count === false) {
            $word_count = 0;
        }

        // Count long words (they often tokenize to multiple tokens)
        $long_word_bonus = 0;
        if ($word_count > 0 && isset($matches[0])) {
            foreach ($matches[0] as $word) {
                $length = mb_strlen($word);
                // Words longer than 8 characters often become multiple tokens
                if ($length > 8) {
                    $long_word_bonus += (int) floor(($length - 8) / 4);
                }
            }
        }

        // Base word-based estimate
        $word_estimate = (int) ceil($word_count * self::TOKENS_PER_WORD);

        // Add bonus for long words
        $word_estimate += $long_word_bonus;

        // Account for punctuation and special characters
        $punctuation_count = preg_match_all('/[^\p{L}\p{N}\s]/u', $text);
        if ($punctuation_count === false) {
            $punctuation_count = 0;
        }

        // Most punctuation is a single token
        $punctuation_tokens = (int) ceil($punctuation_count * 0.5);

        // Account for whitespace tokens (newlines can be tokens)
        $newline_count = substr_count($text, "\n");
        $whitespace_tokens = (int) ceil($newline_count * 0.3);

        $total = $word_estimate + $punctuation_tokens + $whitespace_tokens;

        // Ensure minimum of 1 token for non-empty text
        return max(1, $total);
    }

    /**
     * Split text to target token count.
     *
     * Splits text into chunks that approximately match the target token count.
     * Attempts to split at natural boundaries (sentences, paragraphs) when possible.
     *
     * @param string $text         The text to split.
     * @param int    $targetTokens Target token count per chunk.
     *
     * @return array<string> Array of text chunks.
     */
    public function splitToTokenLimit(string $text, int $targetTokens): array
    {
        if (empty(trim($text))) {
            return [];
        }

        if ($targetTokens <= 0) {
            throw new \InvalidArgumentException('Target tokens must be positive');
        }

        $total_tokens = $this->estimate($text);

        // If text is already under the limit, return as single chunk
        if ($total_tokens <= $targetTokens) {
            return [trim($text)];
        }

        $chunks = [];

        // First, try splitting by paragraphs (double newlines)
        $paragraphs = preg_split('/\n\n+/', $text);

        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        $current_chunk = '';
        $current_tokens = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if (empty($paragraph)) {
                continue;
            }

            $paragraph_tokens = $this->estimate($paragraph);

            // If single paragraph exceeds limit, split it further
            if ($paragraph_tokens > $targetTokens) {
                // Save current chunk if not empty
                if (!empty($current_chunk)) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = '';
                    $current_tokens = 0;
                }

                // Split large paragraph by sentences
                $sentence_chunks = $this->splitBySentences($paragraph, $targetTokens);
                $chunks = array_merge($chunks, $sentence_chunks);
                continue;
            }

            // Check if adding this paragraph would exceed the limit
            if ($current_tokens + $paragraph_tokens > $targetTokens && !empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
                $current_chunk = $paragraph;
                $current_tokens = $paragraph_tokens;
            } else {
                // Add paragraph to current chunk
                $current_chunk .= (empty($current_chunk) ? '' : "\n\n") . $paragraph;
                $current_tokens += $paragraph_tokens;
            }
        }

        // Add final chunk if not empty
        if (!empty(trim($current_chunk))) {
            $chunks[] = trim($current_chunk);
        }

        return $chunks;
    }

    /**
     * Check if text exceeds token limit.
     *
     * @param string $text  The text to check.
     * @param int    $limit The token limit.
     *
     * @return bool True if text exceeds the limit, false otherwise.
     */
    public function exceedsLimit(string $text, int $limit): bool
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be positive');
        }

        return $this->estimate($text) > $limit;
    }

    /**
     * Get the estimated character count for a target token count.
     *
     * Useful for pre-sizing buffers or estimating content length.
     *
     * @param int $tokens Target token count.
     *
     * @return int Estimated character count.
     */
    public function estimateCharsForTokens(int $tokens): int
    {
        if ($tokens <= 0) {
            return 0;
        }

        // Average word length in English is about 5 characters
        $avg_word_length = 5;
        $words = (int) ceil($tokens / self::TOKENS_PER_WORD);

        // Add space between words
        return $words * ($avg_word_length + 1);
    }

    /**
     * Split text by sentences while respecting token limit.
     *
     * @param string $text         The text to split.
     * @param int    $targetTokens Target token count per chunk.
     *
     * @return array<string> Array of text chunks.
     */
    private function splitBySentences(string $text, int $targetTokens): array
    {
        // Split by sentence-ending punctuation
        // This pattern handles common sentence endings while avoiding splits on abbreviations
        $sentences = preg_split(
            '/(?<=[.!?])\s+(?=[A-Z\p{Lu}])/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($sentences === false || empty($sentences)) {
            // Fallback: split by any whitespace clusters
            return $this->splitByWords($text, $targetTokens);
        }

        $chunks = [];
        $current_chunk = '';
        $current_tokens = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if (empty($sentence)) {
                continue;
            }

            $sentence_tokens = $this->estimate($sentence);

            // If single sentence exceeds limit, split by words
            if ($sentence_tokens > $targetTokens) {
                // Save current chunk if not empty
                if (!empty($current_chunk)) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = '';
                    $current_tokens = 0;
                }

                // Split large sentence by words
                $word_chunks = $this->splitByWords($sentence, $targetTokens);
                $chunks = array_merge($chunks, $word_chunks);
                continue;
            }

            // Check if adding this sentence would exceed the limit
            if ($current_tokens + $sentence_tokens > $targetTokens && !empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
                $current_chunk = $sentence;
                $current_tokens = $sentence_tokens;
            } else {
                // Add sentence to current chunk
                $current_chunk .= (empty($current_chunk) ? '' : ' ') . $sentence;
                $current_tokens += $sentence_tokens;
            }
        }

        // Add final chunk if not empty
        if (!empty(trim($current_chunk))) {
            $chunks[] = trim($current_chunk);
        }

        return $chunks;
    }

    /**
     * Split text by words while respecting token limit.
     *
     * This is the fallback splitting method for content that can't be
     * split by paragraphs or sentences.
     *
     * @param string $text         The text to split.
     * @param int    $targetTokens Target token count per chunk.
     *
     * @return array<string> Array of text chunks.
     */
    private function splitByWords(string $text, int $targetTokens): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || empty($words)) {
            // Ultimate fallback: return original text if non-empty
            $trimmed = trim($text);
            return empty($trimmed) ? [] : [$trimmed];
        }

        $chunks = [];
        $current_chunk = '';
        $current_tokens = 0;

        foreach ($words as $word) {
            $word_tokens = $this->estimate($word);

            // Check if adding this word would exceed the limit
            if ($current_tokens + $word_tokens > $targetTokens && !empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
                $current_chunk = $word;
                $current_tokens = $word_tokens;
            } else {
                // Add word to current chunk
                $current_chunk .= (empty($current_chunk) ? '' : ' ') . $word;
                $current_tokens += $word_tokens;
            }
        }

        // Add final chunk if not empty
        if (!empty(trim($current_chunk))) {
            $chunks[] = trim($current_chunk);
        }

        return $chunks;
    }
}
