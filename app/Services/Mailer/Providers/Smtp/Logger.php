<?php

namespace FluentMail\App\Services\Mailer\Providers\Smtp;

/**
 * SMTP Connection Logger - DEBUG VERSION
 *
 * This version logs all SMTP operations including email content, headers,
 * server responses, and connection details for debugging purposes.
 * WARNING: This will log sensitive data like email content and credentials!
 */

class Logger {
    private static $instance = null;
    private $logFile;
    private $logLevel;
    private $smtpDebugOutput = [];

    const LOG_LEVEL_DEBUG   = 'DEBUG';
    const LOG_LEVEL_INFO    = 'INFO';
    const LOG_LEVEL_WARNING = 'WARNING';
    const LOG_LEVEL_ERROR   = 'ERROR';

    private function __construct() {
        // Use plugin root directory for log file
        if (function_exists('plugin_dir_path')) {
            $this->logFile = plugin_dir_path(__FILE__) . '../../../../../smtp-logs-debug.log';
        } else {
            // Fallback for non-WordPress environments
            $this->logFile = dirname(__FILE__) . '../../../../../smtp-logs-debug.log';
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
     * Log SMTP connection details
     */
    public function logSmtpConnection($host, $port, $encryption, $auth = false) {
        $this->log(self::LOG_LEVEL_INFO, "SMTP CONNECTION", [
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $encryption,
            'auth_required' => $auth
        ]);
    }

    /**
     * Log SMTP authentication
     */
    public function logSmtpAuth($username) {
        $this->log(self::LOG_LEVEL_INFO, "SMTP AUTHENTICATION", [
            'username'     => $username,
            'auth_attempt' => true
        ]);
    }

    /**
     * Log email sending details
     */
    public function logEmailSend($from, $to, $subject, $headers, $body, $altBody = null) {
        $this->log(self::LOG_LEVEL_INFO, "EMAIL SEND ATTEMPT", [
            'from'     => $from,
            'to'       => $to,
            'subject'  => $subject,
            'headers'  => $headers,
            'body'     => $body,
            'alt_body' => $altBody
        ]);
    }

    /**
     * Log SMTP debug output
     */
    public function logSmtpDebug($message) {
        $this->log(self::LOG_LEVEL_DEBUG, "SMTP DEBUG: {$message}", []);
    }

    /**
     * Log email operation
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
     * Log PHPMailer settings
     */
    public function logPhpmailerSettings($settings) {
        $this->log(self::LOG_LEVEL_DEBUG, "PHPMailer Settings", $settings);
    }

    /**
     * Generic log method
     */
    public function log($level, $message, $data = []) {
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry  = [
            'timestamp' => $timestamp,
            'level'     => $level,
            'message'   => $message,
            'data'      => $data
        ];

        $logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . PHP_EOL;

        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function shouldLog($level) {
        $levels = [
            self::LOG_LEVEL_DEBUG   => 1,
            self::LOG_LEVEL_INFO    => 2,
            self::LOG_LEVEL_WARNING => 3,
            self::LOG_LEVEL_ERROR   => 4
        ];

        return isset($levels[$level]) && $levels[$level] >= $levels[$this->logLevel];
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

    /**
     * Get SMTP debug output
     */
    public function getSmtpDebugOutput() {
        return $this->smtpDebugOutput;
    }

    /**
     * Clear SMTP debug output
     */
    public function clearSmtpDebugOutput() {
        $this->smtpDebugOutput = [];
    }

    /**
     * Add SMTP debug message
     */
    public function addSmtpDebugMessage($message) {
        $this->smtpDebugOutput[] = $message;
        $this->logSmtpDebug($message);
    }
}

// Global function to get debug logger instance for backward compatibility
function getSmtpLogger() {
    return Logger::getInstance();
}
