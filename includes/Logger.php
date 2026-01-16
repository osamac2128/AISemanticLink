<?php
/**
 * Logging utility for AI Entity Index
 *
 * @package Vibe\AIIndex
 */

declare(strict_types=1);

namespace Vibe\AIIndex;

/**
 * Logger class for writing structured logs to daily log files.
 * Logs are stored in wp-content/uploads/vibe-ai-logs/
 */
class Logger
{
    /** @var string Log directory path */
    private string $logDir;

    /** @var string Current log level */
    private string $logLevel;

    /** @var array<string, int> Log level priorities */
    private const LEVEL_PRIORITIES = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    /**
     * Initialize the logger.
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->logDir = $upload_dir['basedir'] . '/' . Config::LOG_DIRECTORY;
        $this->logLevel = get_option('vibe_ai_log_level', 'info');

        // Ensure log directory exists
        if (!file_exists($this->logDir)) {
            wp_mkdir_p($this->logDir);
        }
    }

    /**
     * Log a debug message.
     *
     * @param string              $message Log message
     * @param array<string,mixed> $context Additional context data
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message.
     *
     * @param string              $message Log message
     * @param array<string,mixed> $context Additional context data
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string              $message Log message
     * @param array<string,mixed> $context Additional context data
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string              $message Log message
     * @param array<string,mixed> $context Additional context data
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log an API call.
     *
     * @param string $model        AI model used
     * @param int    $duration_ms  Duration in milliseconds
     * @param bool   $success      Whether the call succeeded
     * @param string $error_message Optional error message
     * @return void
     */
    public function api(string $model, int $duration_ms, bool $success = true, string $error_message = ''): void
    {
        $context = [
            'model' => $model,
            'duration_ms' => $duration_ms,
            'success' => $success,
        ];

        if (!$success && $error_message) {
            $context['error'] = $error_message;
        }

        $this->log('info', 'API Call', $context, 'API');
    }

    /**
     * Write a log entry.
     *
     * @param string              $level   Log level (debug, info, warning, error)
     * @param string              $message Log message
     * @param array<string,mixed> $context Additional context data
     * @param string              $tag     Optional tag for the log entry
     * @return void
     */
    private function log(string $level, string $message, array $context = [], string $tag = ''): void
    {
        // Check if logging is enabled
        if (!get_option('vibe_ai_logging_enabled', true)) {
            return;
        }

        // Check log level threshold
        if (!$this->shouldLog($level)) {
            return;
        }

        // Sanitize context - remove any sensitive data
        $context = $this->sanitizeContext($context);

        // Build log entry
        $timestamp = gmdate('H:i:s');
        $tag_str = $tag ? "[{$tag}]" : "[" . strtoupper($level) . "]";

        $log_entry = "[{$timestamp}] {$tag_str} {$message}";

        if (!empty($context)) {
            $log_entry .= ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $log_entry .= "\n";

        // Write to daily log file
        $log_file = $this->getLogFilePath();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Check if a log level should be logged based on current threshold.
     *
     * @param string $level Log level to check
     * @return bool True if should log, false otherwise
     */
    private function shouldLog(string $level): bool
    {
        $level = strtolower($level);
        $threshold = strtolower($this->logLevel);

        if (!isset(self::LEVEL_PRIORITIES[$level]) || !isset(self::LEVEL_PRIORITIES[$threshold])) {
            return true;
        }

        return self::LEVEL_PRIORITIES[$level] >= self::LEVEL_PRIORITIES[$threshold];
    }

    /**
     * Sanitize context data to remove sensitive information.
     *
     * @param array<string,mixed> $context Context data
     * @return array<string,mixed> Sanitized context
     */
    private function sanitizeContext(array $context): array
    {
        $sensitive_keys = ['api_key', 'password', 'secret', 'token', 'key', 'authorization'];

        foreach ($context as $key => $value) {
            $lower_key = strtolower($key);

            foreach ($sensitive_keys as $sensitive) {
                if (strpos($lower_key, $sensitive) !== false) {
                    $context[$key] = '[REDACTED]';
                    break;
                }
            }

            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            }
        }

        return $context;
    }

    /**
     * Get the current log file path.
     *
     * @return string Full path to today's log file
     */
    private function getLogFilePath(): string
    {
        $date = gmdate('Y-m-d');
        return $this->logDir . '/' . $date . '.log';
    }

    /**
     * Get recent log entries.
     *
     * @param int    $limit     Maximum number of entries to return
     * @param string $min_level Minimum log level to include
     * @return array<array{timestamp:string,level:string,message:string,context:array}>
     */
    public function getRecentLogs(int $limit = 50, string $min_level = 'info'): array
    {
        $log_file = $this->getLogFilePath();

        if (!file_exists($log_file)) {
            return [];
        }

        // Read file in reverse (most recent first)
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $lines = array_reverse($lines);
        $entries = [];
        $min_priority = self::LEVEL_PRIORITIES[$min_level] ?? 0;

        foreach ($lines as $line) {
            if (count($entries) >= $limit) {
                break;
            }

            $entry = $this->parseLine($line);

            if ($entry === null) {
                continue;
            }

            $level_priority = self::LEVEL_PRIORITIES[strtolower($entry['level'])] ?? 0;

            if ($level_priority >= $min_priority) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Parse a log line into structured data.
     *
     * @param string $line Log line to parse
     * @return array{timestamp:string,level:string,message:string,context:array}|null
     */
    private function parseLine(string $line): ?array
    {
        // Pattern: [HH:MM:SS] [LEVEL] Message {json}
        $pattern = '/^\[(\d{2}:\d{2}:\d{2})\]\s+\[([A-Z]+)\]\s+(.+)$/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        $message = $matches[3];
        $context = [];

        // Check for JSON context at end of message
        if (preg_match('/^(.+?)\s+(\{.+\})$/', $message, $msg_matches)) {
            $message = $msg_matches[1];
            $decoded = json_decode($msg_matches[2], true);

            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        return [
            'timestamp' => $matches[1],
            'level' => $matches[2],
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Clean up old log files.
     *
     * @param int $days_to_keep Number of days of logs to retain
     * @return int Number of files deleted
     */
    public function cleanup(int $days_to_keep = 30): int
    {
        $deleted = 0;
        $cutoff = strtotime("-{$days_to_keep} days");

        if (!is_dir($this->logDir)) {
            return 0;
        }

        $files = glob($this->logDir . '/*.log');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            // Extract date from filename (YYYY-MM-DD.log)
            $filename = basename($file, '.log');
            $file_date = strtotime($filename);

            if ($file_date !== false && $file_date < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            $this->info('Log cleanup completed', ['files_deleted' => $deleted]);
        }

        return $deleted;
    }

    /**
     * Get total size of all log files.
     *
     * @return int Total size in bytes
     */
    public function getTotalLogSize(): int
    {
        $total = 0;

        if (!is_dir($this->logDir)) {
            return 0;
        }

        $files = glob($this->logDir . '/*.log');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $total += filesize($file) ?: 0;
        }

        return $total;
    }

    /**
     * Get list of available log files.
     *
     * @return array<array{date:string,size:int,path:string}>
     */
    public function getLogFiles(): array
    {
        $files = [];

        if (!is_dir($this->logDir)) {
            return [];
        }

        $log_files = glob($this->logDir . '/*.log');

        if ($log_files === false) {
            return [];
        }

        foreach ($log_files as $file) {
            $files[] = [
                'date' => basename($file, '.log'),
                'size' => filesize($file) ?: 0,
                'path' => $file,
            ];
        }

        // Sort by date descending (most recent first)
        usort($files, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return $files;
    }

    /**
     * Set the log level threshold.
     *
     * @param string $level Log level (debug, info, warning, error)
     * @return void
     */
    public function setLogLevel(string $level): void
    {
        $level = strtolower($level);

        if (isset(self::LEVEL_PRIORITIES[$level])) {
            $this->logLevel = $level;
            update_option('vibe_ai_log_level', $level);
        }
    }

    /**
     * Get the current log level.
     *
     * @return string Current log level
     */
    public function getLogLevel(): string
    {
        return $this->logLevel;
    }
}

/**
 * Global logging function for convenience.
 *
 * @param string              $level   Log level
 * @param string              $message Log message
 * @param array<string,mixed> $context Context data
 * @return void
 */
function vibe_ai_log(string $level, string $message, array $context = []): void
{
    static $logger = null;

    if ($logger === null) {
        $logger = new Logger();
    }

    $level = strtolower($level);

    switch ($level) {
        case 'debug':
            $logger->debug($message, $context);
            break;
        case 'info':
            $logger->info($message, $context);
            break;
        case 'warning':
            $logger->warning($message, $context);
            break;
        case 'error':
            $logger->error($message, $context);
            break;
        default:
            $logger->info($message, $context);
    }
}
