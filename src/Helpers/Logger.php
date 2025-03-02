<?php

namespace CloudflareBot\Helpers;

use Exception;

class Logger
{
    private array $config;
    private string $logFile;
    private bool $enabled;
    private string $level;
    
    /**
     * Logger constructor
     *
     * @param array $config The logger configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? false;
        $this->level = strtolower($config['level'] ?? 'info');
        $this->logFile = $config['path'] ?? __DIR__ . '/../../logs/bot.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                throw new Exception("Failed to create log directory: {$logDir}");
            }
        }
    }
    
    /**
     * Log a debug message
     *
     * @param string $message The message to log
     * @param array $context Additional context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log an info message
     *
     * @param string $message The message to log
     * @param array $context Additional context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log a warning message
     *
     * @param string $message The message to log
     * @param array $context Additional context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log an error message
     *
     * @param string $message The message to log
     * @param array $context Additional context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log a message
     *
     * @param string $level The log level
     * @param string $message The message to log
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        
        // Check log level
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $level = strtoupper($level);
        $contextString = empty($context) ? '' : ' ' . json_encode($context);
        
        $logLine = "[{$timestamp}] [{$level}] {$message}{$contextString}" . PHP_EOL;
        
        // Append to log file
        file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * Check if a message with the given level should be logged
     *
     * @param string $level The log level to check
     * @return bool True if the message should be logged
     */
    private function shouldLog(string $level): bool
    {
        $levels = [
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3
        ];
        
        return $levels[$level] >= $levels[$this->level];
    }
} 