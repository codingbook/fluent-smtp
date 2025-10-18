<?php

namespace FluentMail\App\Services\Mailer\Providers\Outlook;

/**
 * Outlook/Microsoft SMTP Connection Logger - DEBUG VERSION
 *
 * This version shows actual values instead of [REDACTED] for debugging purposes.
 * WARNING: This will log sensitive data like tokens and secrets!
 */

class Logger {
    private static $instance = null;
    private $logFile;
    private $logLevel;

    const LOG_LEVEL_DEBUG   = 'DEBUG';
    const LOG_LEVEL_INFO    = 'INFO';
    const LOG_LEVEL_WARNING = 'WARNING';
    const LOG_LEVEL_ERROR   = 'ERROR';

    private function __construct() {
        // Use plugin root directory for log file (same name as normal logger)
        if (function_exists('plugin_dir_path')) {
            $this->logFile = plugin_dir_path(__FILE__) . '../../../../../outlook-smtp-logs-debug.log';
        } else {
            // Fallback for non-WordPress environments
            $this->logFile = dirname(__FILE__) . '../../../../../outlook-smtp-logs-debug.log';
        }
        $this->logLevel = self::LOG_LEVEL_DEBUG; // Log everything by default
        $this->ensureLogDirectory();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensureLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($logDir);
            } else {
                mkdir($logDir, 0755, true);
            }
        }
    }

    /**
     * Log function entry with parameters
     */
    public function logFunctionEntry($class, $function, $params = []) {
        $this->log(self::LOG_LEVEL_DEBUG, "ENTRY: {$class}::{$function}", [
            'params' => $params
        ]);
    }

    /**
     * Log function exit with return value
     */
    public function logFunctionExit($class, $function, $returnValue = null, $executionTime = null) {
        $message = "EXIT: {$class}::{$function}";
        if ($executionTime !== null) {
            $message .= " (Execution time: " . number_format($executionTime, 4) . "s)";
        }
        $this->log(self::LOG_LEVEL_DEBUG, $message, [
            'return_value' => $returnValue
        ]);
    }

    /**
     * Log API request details
     */
    public function logApiRequest($url, $method, $headers, $body, $extra = []) {
        $this->log(self::LOG_LEVEL_INFO, "API REQUEST: {$method} {$url}", [
            'headers' => $this->redactHeaders($headers),
            'body' => $this->redactBody($body),
            'extra' => $extra
        ]);
    }

    /**
     * Log API response details
     */
    public function logApiResponse($url, $statusCode, $headers, $body, $extra = []) {
        $this->log(self::LOG_LEVEL_INFO, "API RESPONSE: {$statusCode} {$url}", [
            'headers' => $this->redactHeaders($headers),
            'body' => $this->redactBody($body),
            'extra' => $extra
        ]);
    }

    /**
     * Log token operations
     */
    public function logTokenOperation($operation, $details = []) {
        $this->log(self::LOG_LEVEL_INFO, "TOKEN OPERATION: {$operation}", $details);
    }

    /**
     * Log email operations
     */
    public function logEmailOperation($operation, $details = []) {
        $this->log(self::LOG_LEVEL_INFO, "EMAIL OPERATION: {$operation}", $details);
    }

    /**
     * Log errors
     */
    public function logError($class, $function, $message, $details = []) {
        $this->log(self::LOG_LEVEL_ERROR, "ERROR in {$class}::{$function}: {$message}", $details);
    }

    /**
     * Generic log method
     */
    public function log($level, $message, $data = []) {
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'data' => $data
        ];

        $logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . PHP_EOL;

        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function shouldLog($level) {
        $levels = [
            self::LOG_LEVEL_DEBUG => 1,
            self::LOG_LEVEL_INFO => 2,
            self::LOG_LEVEL_WARNING => 3,
            self::LOG_LEVEL_ERROR => 4
        ];

        return isset($levels[$level]) && $levels[$level] >= $levels[$this->logLevel];
    }

    private function redactHeaders($headers) {
        $redacted = [];
        foreach ($headers as $key => $value) {
            if (stripos($key, 'authorization') !== false || stripos($key, 'token') !== false) {
                $redacted[$key] = '[REDACTED]';
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }

    private function redactBody($body) {
        // For simplicity, redact the entire body if it contains sensitive data
        // In a real implementation, you might want to parse and redact specific fields
        if (is_string($body) && (stripos($body, 'access_token') !== false || stripos($body, 'refresh_token') !== false)) {
            return '[REDACTED BODY]';
        }
        return $body;
    }

    /**
     * Get log file path
     */
    public function getLogFilePath() {
        return $this->logFile;
    }

    /**
     * Get log file size
     */
    public function getLogFileSize() {
        if (file_exists($this->logFile)) {
            return filesize($this->logFile);
        }
        return 0;
    }
}

// Global function to get debug logger instance for backward compatibility
function getOutlookSMTPLogger() {
    return Logger::getInstance();
}