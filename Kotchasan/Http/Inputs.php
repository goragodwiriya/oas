<?php
/**
 * @filesource Kotchasan/Http/Inputs.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * Enhanced Inputs for array handling with security and performance improvements
 */

namespace Kotchasan\Http;

/**
 * Enhanced Input Collection for handling arrays with fluent interface
 */
class Inputs implements \Iterator, \Countable, \ArrayAccess
{
    /**
     * @var array Collection of InputItem objects
     */
    protected $items = [];

    /**
     * @var string|null Input type
     */
    protected ?string $type = null;

    /**
     * @var int Current iterator position
     */
    protected $position = 0;

    /**
     * @var array Array keys for iteration
     */
    protected $keys = [];

    /**
     * @var array Security configuration
     */
    protected static $securityConfig = [
        'maxDepth' => 5,
        'maxSize' => 2000,
        'allowedMethods' => [
            'topic', 'filter', 'toInt', 'username', 'email', 'url', 'text', 'htmlText', 'bool', 'color',
            'alphanumeric', 'phone', 'date', 'float', 'json', 'base64', 'password', 'oneLine', 'cut',
            'description', 'detail', 'keywords', 'time', 'quote', 'textarea', 'number',
            'toBoolean', 'toDouble', 'toFloat', 'toObject', 'toString', 'toArray', 'toJson', 'exists'
        ]
    ];

    /**
     * Constructor
     *
     * @param array $items Input items
     * @param string|null $type Input type
     * @throws \InvalidArgumentException
     */
    public function __construct(array $items = [], ?string $type = null)
    {
        $this->type = $type;
        $this->validateArraySecurity($items);
        $this->buildCollection($items);
        $this->keys = array_keys($this->items);
    }

    /**
     * Validate array security constraints
     *
     * @param array $items
     * @throws \InvalidArgumentException
     */
    protected function validateArraySecurity(array $items): void
    {
        // Check array depth
        if ($this->getArrayDepth($items) > self::$securityConfig['maxDepth']) {
            throw new \InvalidArgumentException('Array depth exceeds maximum allowed: '.self::$securityConfig['maxDepth']);
        }

        // Check array size
        if (count($items, COUNT_RECURSIVE) > self::$securityConfig['maxSize']) {
            throw new \InvalidArgumentException('Array size exceeds maximum allowed: '.self::$securityConfig['maxSize']);
        }
    }

    /**
     * Calculate array depth
     *
     * @param array $array
     * @return int
     */
    protected function getArrayDepth(array $array): int
    {
        $maxDepth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                $maxDepth = max($depth, $maxDepth);
            }
        }

        return $maxDepth;
    }

    /**
     * Build collection from array
     *
     * @param array $items
     */
    protected function buildCollection(array $items): void
    {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $this->items[$key] = new static($value, $this->type);
            } else {
                $this->items[$key] = new InputItem($value, $this->type);
            }
        }
    }

    /**
     * Magic method for fluent interface on all items
     *
     * @param string $name Method name
     * @param array $arguments Method arguments
     * @return array|self
     * @throws \BadMethodCallException
     */
    public function __call(string $name, array $arguments)
    {
        if (!in_array($name, self::$securityConfig['allowedMethods'])) {
            throw new \BadMethodCallException("Method '{$name}' is not allowed for security reasons");
        }

        $result = [];
        foreach ($this->items as $key => $item) {
            $result[$key] = $this->applyMethod($item, $name, $arguments);
        }

        return $result;
    }

    /**
     * Apply filter method like OldKotchasan (returns array directly)
     * Also supports callback filtering
     *
     * @param string|callable $pattern Pattern for filtering or callback function
     * @param string $replace Replacement string (only for string pattern)
     * @return array|self
     */
    public function filter($pattern, string $replace = '')
    {
        // If it's a callable, use callback filtering and return Inputs object
        if (is_callable($pattern)) {
            $filtered = array_filter($this->items, $pattern, ARRAY_FILTER_USE_BOTH);
            return new static($this->itemsToArray($filtered), $this->type);
        }

        // String pattern filtering (like OldKotchasan) - returns array directly
        $result = [];
        foreach ($this->items as $key => $item) {
            if ($item instanceof InputItem) {
                $result[$key] = $item->filter($pattern, $replace);
            } elseif ($item instanceof self) {
                $result[$key] = $item->filter($pattern, $replace);
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * Apply method to item
     *
     * @param InputItem|Inputs $item
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    protected function applyMethod($item, string $name, array $arguments)
    {
        if ($item instanceof static ) {
            // Recursive call for nested arrays
            return $item->$name(...$arguments);
        }

        return $item->$name(...$arguments);
    }

    /**
     * Get item by key
     *
     * @param string|int $key
     * @return InputItem|Inputs
     */
    public function get($key)
    {
        return $this->items[$key] ?? new InputItem(null, $this->type);
    }

    /**
     * Check if key exists
     *
     * @param string|int $key
     * @return bool
     */
    public function has($key): bool
    {
        return isset($this->items[$key]);
    }

    /**
     * Get all keys
     *
     * @return array
     */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * Convert to plain array
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            if ($item instanceof static ) {
                $result[$key] = $item->toArray();
            } else {
                $result[$key] = (string) $item;
            }
        }
        return $result;
    }

    /**
     * Convert to JSON string
     *
     * @return string
     */
    public function toJson(): string
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            if ($item instanceof InputItem) {
                $result[$key] = $item->getValue();
            } elseif ($item instanceof static ) {
                $result[$key] = json_decode($item->toJson(), true);
            } else {
                $result[$key] = $item;
            }
        }
        return json_encode($result);
    }

    /**
     * Get first item
     *
     * @return InputItem|Inputs|null
     */
    public function first()
    {
        return reset($this->items) ?: null;
    }

    /**
     * Get last item
     *
     * @return InputItem|Inputs|null
     */
    public function last()
    {
        return end($this->items) ?: null;
    }

    /**
     * Map items with callback
     *
     * @param callable $callback
     * @return array
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    /**
     * Convert items back to array format
     *
     * @param array $items
     * @return array
     */
    protected function itemsToArray(array $items): array
    {
        $result = [];
        foreach ($items as $key => $item) {
            if ($item instanceof static ) {
                $result[$key] = $item->toArray();
            } else {
                $result[$key] = $item->getValue();
            }
        }
        return $result;
    }

    // ===== Iterator Interface =====

    #[\ReturnTypeWillChange]
    /**
     * @return mixed
     */
    public function current()
    {
        return $this->items[$this->keys[$this->position]] ?? null;
    }

    #[\ReturnTypeWillChange]
    /**
     * @return mixed
     */
    public function key()
    {
        return $this->keys[$this->position] ?? null;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }

    // ===== Countable Interface =====

    public function count(): int
    {
        return count($this->items);
    }

    // ===== ArrayAccess Interface =====

    #[\ReturnTypeWillChange]
    /**
     * @param $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    /**
     * @param $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->items[$offset] ?? new InputItem(null, $this->type);
    }

    #[\ReturnTypeWillChange]
    /**
     * @param $offset
     * @param $value
     */
    public function offsetSet($offset, $value): void
    {
        if (is_array($value)) {
            $this->items[$offset] = new static($value, $this->type);
        } else {
            $this->items[$offset] = new InputItem($value, $this->type);
        }
        $this->keys = array_keys($this->items);
    }

    #[\ReturnTypeWillChange]
    /**
     * @param $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
        $this->keys = array_keys($this->items);
    }

    // ===== Utility Methods =====

    /**
     * Check if collection is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Get collection size
     *
     * @return int
     */
    public function size(): int
    {
        return count($this->items);
    }

    /**
     * Create Inputs instance
     *
     * @param array $items
     * @param string|null $type
     * @return static
     */
    public static function create(array $items = [], ?string $type = null): self
    {
        return new static($items, $type);
    }
}
