<?php

namespace Kotchasan\Database;

use Kotchasan\Exception\ConfigurationException;
use Kotchasan\Logger\LoggerInterface;

/**
 * TableConfiguration Class
 *
 * Manages table name mappings and prefix application for database operations.
 * This class provides centralized table name resolution with support for
 * custom table mappings and automatic prefix application.
 *
 * @package Kotchasan\Database
 */
class TableConfiguration
{
    /**
     * Table name mappings.
     *
     * @var array
     */
    private array $tables;

    /**
     * Table name prefix.
     *
     * @var string
     */
    private string $prefix;

    /**
     * Logger instance for error reporting.
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Flag to track if configuration is valid.
     *
     * @var bool
     */
    private bool $isValid;

    /**
     * Constructor.
     *
     * @param array $config Configuration array containing 'tables' and 'prefix' keys
     * @param LoggerInterface|null $logger Optional logger instance for error reporting
     */
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->isValid = true;

        try {
            $this->validateAndLoadConfiguration($config);
        } catch (ConfigurationException $e) {
            $this->handleConfigurationError($e->getMessage(), $config);
            // Continue with fallback configuration to maintain system stability
            $this->loadFallbackConfiguration();
        }
    }

    /**
     * Get the configured table name with prefix applied.
     *
     * @param string $table The logical table name
     * @return string The physical table name with prefix
     */
    public function getTableName(string $table): string
    {
        try {
            // Validate table name input
            if (empty($table) || !is_string($table)) {
                $this->logWarning("Invalid table name provided", ['table' => $table]);
                $tableName = $this->sanitizeTableName($table);
            } else {
                // Get the configured table name or use the provided name as fallback
                $tableName = $this->tables[$table] ?? $table;

                // Only validate if using the fallback (not configured)
                // For configured mappings, trust the configuration for backward compatibility
                if (!isset($this->tables[$table]) && !$this->isValidTableName($tableName)) {
                    $this->logWarning("Invalid table name provided", [
                        'logical_name' => $table,
                        'physical_name' => $tableName
                    ]);
                    $tableName = $this->sanitizeTableName($table);
                }
            }

            // Apply prefix if configured. Avoid adding prefix twice when
            // the provided physical table name already includes the prefix.
            if (!empty($this->prefix)) {
                $prefixWithUnderscore = $this->prefix.'_';
                // If $tableName already starts with the prefix (idempotent), return as-is
                if (strpos($tableName, $prefixWithUnderscore) === 0) {
                    return $tableName;
                }
                return $this->prefix.'_'.$tableName;
            }

            return $tableName;
        } catch (\Exception $e) {
            $this->logError("Error resolving table name", [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            // Return sanitized fallback
            return $this->sanitizeTableName($table);
        }
    }

    /**
     * Get the configured prefix.
     *
     * @return string The table prefix
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get all configured table mappings.
     *
     * @return array The table mappings
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Check if a table is defined in the configuration.
     *
     * @param string $table The logical table name
     * @return bool True if the table is configured, false otherwise
     */
    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * Check if the configuration is valid.
     *
     * @return bool True if configuration is valid, false otherwise
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Set logger instance.
     *
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Validates and loads the configuration.
     *
     * @param array $config The configuration array
     * @return void
     * @throws ConfigurationException If configuration is invalid
     */
    private function validateAndLoadConfiguration(array $config): void
    {
        // Validate prefix
        $prefix = $config['prefix'] ?? '';
        if (!is_string($prefix)) {
            throw new ConfigurationException("Table prefix must be a string, ".gettype($prefix)." given");
        }

        if (!empty($prefix)) {
            // Check for truly problematic characters that should be rejected
            $hasNullByte = strpos($prefix, "\0") !== false;
            $hasControlChars = preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1;

            if ($hasNullByte || $hasControlChars) {
                throw new ConfigurationException("Invalid table prefix format: '{$prefix}' contains null bytes or control characters");
            }
        }

        // Validate tables configuration
        $tables = $config['tables'] ?? [];
        if (!is_array($tables)) {
            throw new ConfigurationException("Tables configuration must be an array, ".gettype($tables)." given");
        }

        // Validate individual table mappings
        foreach ($tables as $logicalName => $physicalName) {
            // Convert integer keys to strings (PHP automatically converts numeric string keys to integers)
            if (is_int($logicalName)) {
                $logicalName = (string) $logicalName;
            }

            if (!is_string($logicalName) || !is_string($physicalName)) {
                throw new ConfigurationException("Table mappings must be string to string, invalid mapping: '{$logicalName}' => '{$physicalName}'");
            }

            // Check for truly problematic characters in table names
            $logicalHasNullByte = strpos($logicalName, "\0") !== false;
            $logicalHasControlChars = preg_match('/[\x00-\x1F\x7F]/', $logicalName) === 1;
            $physicalHasNullByte = strpos($physicalName, "\0") !== false;
            $physicalHasControlChars = preg_match('/[\x00-\x1F\x7F]/', $physicalName) === 1;

            if ($logicalHasNullByte || $logicalHasControlChars) {
                throw new ConfigurationException("Invalid logical table name: '{$logicalName}' contains null bytes or control characters");
            }

            if ($physicalHasNullByte || $physicalHasControlChars) {
                throw new ConfigurationException("Invalid physical table name: '{$physicalName}' contains null bytes or control characters");
            }
        }

        // Configuration is valid, load it
        $this->prefix = $prefix;
        $this->tables = $tables;

        $this->logInfo("Table configuration loaded successfully", [
            'prefix' => $this->prefix,
            'table_count' => count($this->tables)
        ]);
    }

    /**
     * Loads fallback configuration when primary configuration fails.
     *
     * @return void
     */
    private function loadFallbackConfiguration(): void
    {
        $this->prefix = '';
        $this->tables = [];
        $this->isValid = false;

        $this->logWarning("Loaded fallback table configuration (no prefix, no table mappings)");
    }

    /**
     * Handles configuration errors with appropriate logging and recovery.
     *
     * @param string $errorMessage The error message
     * @param array $config The failed configuration
     * @return void
     */
    private function handleConfigurationError(string $errorMessage, array $config): void
    {
        $this->isValid = false;

        $this->logError("Table configuration error: {$errorMessage}", [
            'config' => $this->sanitizeConfigForLogging($config),
            'recovery_action' => 'Loading fallback configuration'
        ]);
    }

    /**
     * Validates if a table name is valid.
     *
     * @param string $tableName The table name to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidTableName(string $tableName): bool
    {
        // Table name must not be empty
        if (empty($tableName)) {
            return false;
        }

        // Allow most characters for backward compatibility
        // Only reject obviously problematic characters like null bytes, control characters
        $hasNullByte = strpos($tableName, "\0") !== false;
        $hasControlChars = preg_match('/[\x00-\x1F\x7F]/', $tableName) === 1;

        return !$hasNullByte && !$hasControlChars;
    }

    /**
     * Validates if a prefix is valid.
     *
     * @param string $prefix The prefix to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidPrefix(string $prefix): bool
    {
        // Allow most characters for backward compatibility
        // Only reject obviously problematic characters like null bytes, control characters
        $hasNullByte = strpos($prefix, "\0") !== false;
        $hasControlChars = preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1;

        return !$hasNullByte && !$hasControlChars;
    }

    /**
     * Sanitizes a table name to make it safe for use.
     *
     * @param mixed $tableName The table name to sanitize
     * @return string The sanitized table name
     */
    private function sanitizeTableName($tableName): string
    {
        // Convert to string if not already
        $tableName = (string) $tableName;

        // If empty, return a default safe name
        if (empty($tableName)) {
            return 'unknown_table';
        }

        // Replace hyphens with underscores and remove other invalid characters
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $tableName);

        // Ensure it starts with a letter or underscore
        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 'table_'.$sanitized;
        }

        return $sanitized;
    }

    /**
     * Sanitizes configuration data for safe logging.
     *
     * @param array $config The configuration to sanitize
     * @return array The sanitized configuration
     */
    private function sanitizeConfigForLogging(array $config): array
    {
        $sanitized = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeConfigForLogging($value);
            } elseif (is_string($value) && strlen($value) > 100) {
                // Truncate very long strings
                $sanitized[$key] = substr($value, 0, 100).'... (truncated)';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Logs an error message.
     *
     * @param string $message The error message
     * @param array $context Additional context
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->error("[TableConfiguration] {$message}", $context);
        }
    }

    /**
     * Logs a warning message.
     *
     * @param string $message The warning message
     * @param array $context Additional context
     * @return void
     */
    private function logWarning(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->warning("[TableConfiguration] {$message}", $context);
        }
    }

    /**
     * Logs an info message.
     *
     * @param string $message The info message
     * @param array $context Additional context
     * @return void
     */
    private function logInfo(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->info("[TableConfiguration] {$message}", $context);
        }
    }
}
