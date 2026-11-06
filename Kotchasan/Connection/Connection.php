<?php

namespace Kotchasan\Connection;

use Kotchasan\Cache\QueryCache;
use Kotchasan\Logger\LoggerInterface;

/**
 * Class Connection
 *
 * Represents a database connection.
 *
 * @package Kotchasan\Connection
 */
class Connection implements ConnectionInterface
{
    /**
     * The driver instance.
     *
     * @var DriverInterface
     */
    protected DriverInterface $driver;

    /**
     * Flag indicating whether the connection is active.
     *
     * @var bool
     */
    protected bool $connected = false;

    /**
     * The last error message.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * The logger instance.
     *
     * @var LoggerInterface|null
     */
    protected ?LoggerInterface $logger = null;

    /**
     * The query cache instance.
     *
     * @var QueryCache|null
     */
    protected ?QueryCache $queryCache = null;

    /**
     * Connection constructor.
     *
     * @param DriverInterface $driver The driver instance.
     */
    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        try {
            if ($this->logger !== null && $this->logger->isDebugEnabled()) {
                $this->logger->debug('Connecting to database', [
                    'driver' => get_class($this->driver)
                ]);
            }

            // Using empty config array as a default
            $this->connected = $this->driver->connect([]);

            if (!$this->connected) {
                $this->lastError = $this->driver->getLastError();

                if ($this->logger !== null) {
                    $this->logger->error('Failed to connect to database', [
                        'driver' => get_class($this->driver),
                        'error' => $this->lastError
                    ]);
                }
            } else if ($this->logger !== null && $this->logger->isDebugEnabled()) {
                $this->logger->debug('Connected to database', [
                    'driver' => get_class($this->driver)
                ]);
            }
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            $this->connected = false;

            if ($this->logger !== null) {
                $this->logger->error('Exception connecting to database', [
                    'driver' => get_class($this->driver),
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
            }
        }

        return $this->connected;
    }

    /**
     * Connects to the database using the provided configuration.
     *
     * @param array $config The configuration array.
     * @return bool True if the connection was successful, false otherwise.
     */
    public function connectWithConfig(array $config): bool
    {
        if ($this->connected) {
            return true;
        }

        try {
            if ($this->logger !== null && $this->logger->isDebugEnabled()) {
                // Remove sensitive information like passwords from logging
                $logConfig = $config;
                if (isset($logConfig['password'])) {
                    $logConfig['password'] = '********';
                }

                $this->logger->debug('Connecting to database with configuration', [
                    'driver' => get_class($this->driver),
                    'config' => $logConfig
                ]);
            }

            $this->connected = $this->driver->connect($config);

            if (!$this->connected) {
                $this->lastError = $this->driver->getLastError();

                if ($this->logger !== null) {
                    $this->logger->error('Failed to connect to database with configuration', [
                        'driver' => get_class($this->driver),
                        'error' => $this->lastError
                    ]);
                }
            } else if ($this->logger !== null && $this->logger->isDebugEnabled()) {
                $this->logger->debug('Connected to database with configuration', [
                    'driver' => get_class($this->driver)
                ]);
            }
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            $this->connected = false;

            if ($this->logger !== null) {
                $this->logger->error('Exception connecting to database with configuration', [
                    'driver' => get_class($this->driver),
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
            }
        }

        return $this->connected;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): bool
    {
        if (!$this->connected) {
            return true;
        }

        try {
            $result = $this->driver->disconnect();

            if ($result) {
                $this->connected = false;
            } else {
                $this->lastError = $this->driver->getLastError();
            }

            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->driver->isConnected();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection()
    {
        // The driver itself is the connection in our implementation
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastError(): ?string
    {
        return $this->lastError ?? $this->driver->getLastError();
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * {@inheritdoc}
     */
    public function setQueryCache(QueryCache $queryCache): void
    {
        $this->queryCache = $queryCache;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryCache(): ?QueryCache
    {
        return $this->queryCache;
    }

    /**
     * {@inheritdoc}
     */
    public function optimizeTable(string $tableName): bool
    {
        return $this->driver->optimizeTable($tableName);
    }

    /**
     * {@inheritdoc}
     */
    public function formatSqlFunction(string $type, array $parameters, ?string $alias): string
    {
        return $this->driver->formatSqlFunction($type, $parameters, $alias);
    }
}
