<?php
/**
 * PII Detector Service
 *
 * Detects potential PII in content for safety controls.
 *
 * @package Vibe\AIIndex\Services\KB
 */

declare(strict_types=1);

namespace Vibe\AIIndex\Services\KB;

/**
 * Detects potential PII in content.
 */
class PIIDetector
{
    // Patterns for common PII
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    private const PHONE_PATTERN = '/(\+?1[-.\s]?)?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}/';
    private const SSN_PATTERN = '/\b\d{3}-\d{2}-\d{4}\b/';
    private const CREDIT_CARD_PATTERN = '/\b(?:\d{4}[-\s]?){3}\d{4}\b/';

    /**
     * PII type constants.
     */
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_SSN = 'ssn';
    public const TYPE_CREDIT_CARD = 'credit_card';

    /**
     * Pattern mapping for each PII type.
     *
     * @var array<string, string>
     */
    private array $patterns = [
        self::TYPE_EMAIL => self::EMAIL_PATTERN,
        self::TYPE_PHONE => self::PHONE_PATTERN,
        self::TYPE_SSN => self::SSN_PATTERN,
        self::TYPE_CREDIT_CARD => self::CREDIT_CARD_PATTERN,
    ];

    /**
     * Detect PII in text.
     *
     * @param string $text The text to scan for PII.
     * @return array{has_pii: bool, types: array<string>, matches: array<string, array<string>>}
     */
    public function detect(string $text): array
    {
        $result = [
            'has_pii' => false,
            'types' => [],
            'matches' => [],
        ];

        foreach ($this->patterns as $type => $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $text, $matches)) {
                $result['has_pii'] = true;
                $result['types'][] = $type;
                $result['matches'][$type] = array_unique($matches[0]);
            }
        }

        return $result;
    }

    /**
     * Check if text contains email addresses.
     *
     * @param string $text The text to scan.
     * @return bool True if email addresses are found.
     */
    public function hasEmail(string $text): bool
    {
        return (bool) preg_match(self::EMAIL_PATTERN, $text);
    }

    /**
     * Check if text contains phone numbers.
     *
     * @param string $text The text to scan.
     * @return bool True if phone numbers are found.
     */
    public function hasPhone(string $text): bool
    {
        return (bool) preg_match(self::PHONE_PATTERN, $text);
    }

    /**
     * Check if text contains Social Security Numbers.
     *
     * @param string $text The text to scan.
     * @return bool True if SSNs are found.
     */
    public function hasSSN(string $text): bool
    {
        return (bool) preg_match(self::SSN_PATTERN, $text);
    }

    /**
     * Check if text contains credit card numbers.
     *
     * @param string $text The text to scan.
     * @return bool True if credit card numbers are found.
     */
    public function hasCreditCard(string $text): bool
    {
        return (bool) preg_match(self::CREDIT_CARD_PATTERN, $text);
    }

    /**
     * Redact PII from text (replace with [REDACTED]).
     *
     * @param string $text The text containing PII.
     * @return string Text with PII replaced by [REDACTED].
     */
    public function redact(string $text): string
    {
        $redacted = $text;

        foreach ($this->patterns as $type => $pattern) {
            $replacement = '[REDACTED:' . strtoupper($type) . ']';
            $redacted = preg_replace($pattern, $replacement, $redacted);
        }

        return $redacted;
    }

    /**
     * Redact specific PII types from text.
     *
     * @param string        $text  The text containing PII.
     * @param array<string> $types Array of PII types to redact.
     * @return string Text with specified PII types replaced.
     */
    public function redactTypes(string $text, array $types): string
    {
        $redacted = $text;

        foreach ($types as $type) {
            if (isset($this->patterns[$type])) {
                $replacement = '[REDACTED:' . strtoupper($type) . ']';
                $redacted = preg_replace($this->patterns[$type], $replacement, $redacted);
            }
        }

        return $redacted;
    }

    /**
     * Get all supported PII types.
     *
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * Add a custom PII pattern.
     *
     * @param string $type    The PII type name.
     * @param string $pattern The regex pattern.
     * @return void
     */
    public function addPattern(string $type, string $pattern): void
    {
        $this->patterns[$type] = $pattern;
    }

    /**
     * Remove a PII pattern.
     *
     * @param string $type The PII type name.
     * @return void
     */
    public function removePattern(string $type): void
    {
        unset($this->patterns[$type]);
    }
}
