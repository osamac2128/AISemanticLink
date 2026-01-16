<?php
/**
 * Anchor generation service for Knowledge Base chunks.
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

/**
 * Generates stable, deterministic anchors for chunks.
 *
 * Anchors are URL-safe identifiers that can be used to reference specific
 * chunks within a document. They are generated deterministically from
 * chunk metadata to ensure reproducibility.
 */
class AnchorGenerator
{
    /**
     * Prefix for all generated anchors.
     */
    private const ANCHOR_PREFIX = 'kb-';

    /**
     * Length of the hash portion in generated anchors.
     */
    private const HASH_LENGTH = 12;

    /**
     * Pattern for validating anchor format.
     */
    private const ANCHOR_PATTERN = '/^kb-[a-f0-9]{12}$/';

    /**
     * Generate anchor from chunk metadata.
     *
     * Creates a deterministic, URL-safe anchor using a hash of the chunk's
     * identifying information. The format is: kb-{short_hash} where hash
     * is derived from SHA256(post_id|heading_path|chunk_index|content_hash_short).
     *
     * @param int    $postId      The WordPress post ID.
     * @param array  $headingPath Array of heading texts representing the path to this chunk.
     * @param int    $chunkIndex  Zero-based index of the chunk within the document.
     * @param string $contentHash Full content hash from ContentNormalizer.
     *
     * @return string The generated anchor (e.g., "kb-a1b2c3d4e5f6").
     */
    public function generate(int $postId, array $headingPath, int $chunkIndex, string $contentHash): string
    {
        // Build the input string for hashing
        $input_parts = [
            (string) $postId,
            $this->serializeHeadingPath($headingPath),
            (string) $chunkIndex,
            // Use first 16 chars of content hash for the anchor generation
            substr($contentHash, 0, 16),
        ];

        $input_string = implode('|', $input_parts);

        // Generate SHA256 hash and take first HASH_LENGTH characters
        $hash = hash('sha256', $input_string);
        $short_hash = substr($hash, 0, self::HASH_LENGTH);

        return self::ANCHOR_PREFIX . $short_hash;
    }

    /**
     * Parse anchor to extract components (if possible).
     *
     * Attempts to extract information from an anchor. Since anchors are
     * hashed, only the hash portion can be extracted. The original
     * components cannot be recovered.
     *
     * @param string $anchor The anchor to parse.
     *
     * @return array{prefix: string, hash: string}|null Parsed components or null if invalid.
     */
    public function parse(string $anchor): ?array
    {
        if (!$this->isValid($anchor)) {
            return null;
        }

        return [
            'prefix' => self::ANCHOR_PREFIX,
            'hash' => substr($anchor, strlen(self::ANCHOR_PREFIX)),
        ];
    }

    /**
     * Validate anchor format.
     *
     * Checks if the provided string matches the expected anchor format.
     *
     * @param string $anchor The anchor to validate.
     *
     * @return bool True if the anchor is valid, false otherwise.
     */
    public function isValid(string $anchor): bool
    {
        if (empty($anchor)) {
            return false;
        }

        return (bool) preg_match(self::ANCHOR_PATTERN, $anchor);
    }

    /**
     * Generate a human-readable anchor from heading path.
     *
     * Creates a slug-style anchor from the heading path, useful for
     * creating more descriptive URLs when needed.
     *
     * @param array $headingPath Array of heading texts.
     * @param int   $maxLength   Maximum length of the generated anchor.
     *
     * @return string A slug-style anchor.
     */
    public function generateReadableAnchor(array $headingPath, int $maxLength = 64): string
    {
        if (empty($headingPath)) {
            return self::ANCHOR_PREFIX . 'root';
        }

        // Use the last heading in the path
        $heading = end($headingPath);

        if (!is_string($heading) || empty(trim($heading))) {
            return self::ANCHOR_PREFIX . 'section';
        }

        // Convert to lowercase and replace non-alphanumeric with hyphens
        $slug = strtolower($heading);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Truncate if necessary
        if (strlen($slug) > $maxLength - strlen(self::ANCHOR_PREFIX)) {
            $slug = substr($slug, 0, $maxLength - strlen(self::ANCHOR_PREFIX));
            $slug = rtrim($slug, '-');
        }

        // Ensure non-empty
        if (empty($slug)) {
            $slug = 'section';
        }

        return self::ANCHOR_PREFIX . $slug;
    }

    /**
     * Compare two anchors for equality.
     *
     * Performs a constant-time comparison to prevent timing attacks
     * if anchors are used for any security-sensitive purposes.
     *
     * @param string $anchor1 First anchor to compare.
     * @param string $anchor2 Second anchor to compare.
     *
     * @return bool True if anchors are equal, false otherwise.
     */
    public function equals(string $anchor1, string $anchor2): bool
    {
        return hash_equals($anchor1, $anchor2);
    }

    /**
     * Get the anchor prefix.
     *
     * @return string The anchor prefix.
     */
    public function getPrefix(): string
    {
        return self::ANCHOR_PREFIX;
    }

    /**
     * Get the hash length used in anchors.
     *
     * @return int The hash length.
     */
    public function getHashLength(): int
    {
        return self::HASH_LENGTH;
    }

    /**
     * Serialize heading path to a consistent string.
     *
     * @param array $headingPath Array of heading texts.
     *
     * @return string Serialized heading path.
     */
    private function serializeHeadingPath(array $headingPath): string
    {
        if (empty($headingPath)) {
            return '';
        }

        // Filter and normalize heading texts
        $normalized = array_map(function ($heading) {
            if (!is_string($heading)) {
                return '';
            }
            // Normalize whitespace and trim
            return trim(preg_replace('/\s+/', ' ', $heading));
        }, $headingPath);

        // Remove empty entries
        $normalized = array_filter($normalized, function ($heading) {
            return !empty($heading);
        });

        // Join with a delimiter that won't appear in heading text
        return implode(' > ', $normalized);
    }
}
