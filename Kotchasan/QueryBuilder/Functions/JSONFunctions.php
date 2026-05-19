<?php

namespace Kotchasan\QueryBuilder\Functions;

/**
 * Class JSONFunctions
 *
 * Provides MySQL JSON-specific functions for query building.
 *
 * @package Kotchasan\QueryBuilder\Functions
 */
class JSONFunctions
{
    /**
     * Creates a JSON_EXTRACT function call.
     *
     * @param string $column The JSON column.
     * @param string $path The JSON path (e.g., '$.name', '$[0]').
     * @return string The JSON_EXTRACT function call.
     */
    public static function extract(string $column, string $path): string
    {
        return "JSON_EXTRACT({$column}, '{$path}')";
    }

    /**
     * Creates a JSON_CONTAINS function call.
     *
     * @param string $column The JSON column.
     * @param mixed $value The value to check for.
     * @param string|null $path The optional JSON path.
     * @return string The JSON_CONTAINS function call.
     */
    public static function contains(string $column, $value, ?string $path = null): string
    {
        if (is_string($value)) {
            $value = "'{$value}'";
        }

        if ($path === null) {
            return "JSON_CONTAINS({$column}, {$value})";
        }

        return "JSON_CONTAINS({$column}, {$value}, '{$path}')";
    }

    /**
     * Creates a JSON_OBJECT function call.
     *
     * @param array $keyValues Key-value pairs for the JSON object.
     * @return string The JSON_OBJECT function call.
     */
    public static function object(array $keyValues): string
    {
        $parts = [];

        foreach ($keyValues as $key => $value) {
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_array($value) || is_object($value)) {
                $value = "'".json_encode($value)."'";
            }

            $parts[] = "'{$key}', {$value}";
        }

        return "JSON_OBJECT(".implode(', ', $parts).")";
    }

    /**
     * Creates a JSON_ARRAY function call.
     *
     * @param array $values The array values.
     * @return string The JSON_ARRAY function call.
     */
    public static function createArray(array $values): string
    {
        $parts = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_array($value) || is_object($value)) {
                $value = "'".json_encode($value)."'";
            }

            $parts[] = $value;
        }

        return "JSON_ARRAY(".implode(', ', $parts).")";
    }

    /**
     * Creates a JSON_MERGE_PATCH function call.
     *
     * @param string $doc1 The first JSON document.
     * @param string $doc2 The second JSON document.
     * @param string ...$docs Additional JSON documents.
     * @return string The JSON_MERGE_PATCH function call.
     */
    public static function mergePatch(string $doc1, string $doc2, string ...$docs): string
    {
        $args = array_merge([$doc1, $doc2], $docs);
        return "JSON_MERGE_PATCH(".implode(', ', $args).")";
    }

    /**
     * Creates a JSON_MERGE_PRESERVE function call.
     *
     * @param string $doc1 The first JSON document.
     * @param string $doc2 The second JSON document.
     * @param string ...$docs Additional JSON documents.
     * @return string The JSON_MERGE_PRESERVE function call.
     */
    public static function mergePreserve(string $doc1, string $doc2, string ...$docs): string
    {
        $args = array_merge([$doc1, $doc2], $docs);
        return "JSON_MERGE_PRESERVE(".implode(', ', $args).")";
    }

    /**
     * Creates a JSON_SET function call.
     *
     * @param string $column The JSON column.
     * @param array $pathValues Array of path-value pairs.
     * @return string The JSON_SET function call.
     */
    public static function set(string $column, array $pathValues): string
    {
        $parts = [];

        foreach ($pathValues as $path => $value) {
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_array($value) || is_object($value)) {
                $value = "'".json_encode($value)."'";
            }

            $parts[] = "'{$path}', {$value}";
        }

        return "JSON_SET({$column}, ".implode(', ', $parts).")";
    }

    /**
     * Creates a JSON_INSERT function call.
     *
     * @param string $column The JSON column.
     * @param array $pathValues Array of path-value pairs.
     * @return string The JSON_INSERT function call.
     */
    public static function insert(string $column, array $pathValues): string
    {
        $parts = [];

        foreach ($pathValues as $path => $value) {
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_array($value) || is_object($value)) {
                $value = "'".json_encode($value)."'";
            }

            $parts[] = "'{$path}', {$value}";
        }

        return "JSON_INSERT({$column}, ".implode(', ', $parts).")";
    }

    /**
     * Creates a JSON_REPLACE function call.
     *
     * @param string $column The JSON column.
     * @param array $pathValues Array of path-value pairs.
     * @return string The JSON_REPLACE function call.
     */
    public static function replace(string $column, array $pathValues): string
    {
        $parts = [];

        foreach ($pathValues as $path => $value) {
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_array($value) || is_object($value)) {
                $value = "'".json_encode($value)."'";
            }

            $parts[] = "'{$path}', {$value}";
        }

        return "JSON_REPLACE({$column}, ".implode(', ', $parts).")";
    }

    /**
     * Creates a JSON_REMOVE function call.
     *
     * @param string $column The JSON column.
     * @param string ...$paths The JSON paths to remove.
     * @return string The JSON_REMOVE function call.
     */
    public static function remove(string $column, string ...$paths): string
    {
        $quotedPaths = array_map(function ($path) {
            return "'{$path}'";
        }, $paths);

        return "JSON_REMOVE({$column}, ".implode(', ', $quotedPaths).")";
    }

    /**
     * Creates a JSON_TYPE function call.
     *
     * @param string $column The JSON column or expression.
     * @return string The JSON_TYPE function call.
     */
    public static function type(string $column): string
    {
        return "JSON_TYPE({$column})";
    }

    /**
     * Creates a JSON_DEPTH function call.
     *
     * @param string $column The JSON column or expression.
     * @return string The JSON_DEPTH function call.
     */
    public static function depth(string $column): string
    {
        return "JSON_DEPTH({$column})";
    }

    /**
     * Creates a JSON_LENGTH function call.
     *
     * @param string $column The JSON column or expression.
     * @param string|null $path The optional JSON path.
     * @return string The JSON_LENGTH function call.
     */
    public static function length(string $column, ?string $path = null): string
    {
        if ($path === null) {
            return "JSON_LENGTH({$column})";
        }

        return "JSON_LENGTH({$column}, '{$path}')";
    }

    /**
     * Creates a JSON_VALID function call.
     *
     * @param string $column The JSON column or expression.
     * @return string The JSON_VALID function call.
     */
    public static function valid(string $column): string
    {
        return "JSON_VALID({$column})";
    }

    /**
     * Creates a JSON_PRETTY function call.
     *
     * @param string $column The JSON column or expression.
     * @return string The JSON_PRETTY function call.
     */
    public static function pretty(string $column): string
    {
        return "JSON_PRETTY({$column})";
    }
}
