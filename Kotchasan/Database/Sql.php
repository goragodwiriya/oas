<?php
namespace Kotchasan\Database;

use Kotchasan\QueryBuilder\QueryBuilder;

/**
 * SQL Function Helper
 *
 * @see https://www.kotchasan.com/
 */
class Sql
{
    /**
     * SQL statement stored here
     *
     * @var string
     */
    protected $sql;

    /**
     * Array to store parameters for binding
     *
     * @var array
     */
    protected $values;

    /**
     * Database type context for SQL generation
     *
     * @var string
     */
    protected static $database_type = 'mysql';

    /**
     * Set database type for context-aware SQL generation
     *
     * @param string $type Database type: mysql, mssql, postgresql
     */
    public static function setDatabaseType($type)
    {
        $type = strtolower(trim((string) $type));
        $supported = ['mysql', 'mssql', 'postgresql', 'sqlite'];
        if (!in_array($type, $supported, true)) {
            $type = 'mysql';
        }
        self::$database_type = $type;
    }

    /**
     * Get current database type
     *
     * @return string
     */
    public static function getDatabaseType()
    {
        return self::$database_type;
    }

    /**
     * Quote an identifier based on database type
     *
     * @param string $identifier
     * @return string
     */
    protected static function quoteIdentifier(string $identifier)
    {
        $parts = explode('.', $identifier);
        $dbType = self::getDatabaseType();

        switch ($dbType) {
        case 'mssql':
            foreach ($parts as &$part) {
                $part = '['.str_replace(']', ']]', $part).']';
            }
            break;
        case 'postgresql':
        case 'sqlite':
            foreach ($parts as &$part) {
                $part = '"'.str_replace('"', '""', $part).'"';
            }
            break;
        case 'mysql':
        default:
            foreach ($parts as &$part) {
                $part = '`'.str_replace('`', '``', $part).'`';
            }
            break;
        }

        return implode('.', $parts);
    }

    /**
     * Calculate the average of the selected column
     *
     * @param string      $column_name The name of the column to calculate the average for
     * @param string|null $alias       The alias for the resulting column, optional
     * @param bool        $distinct    If true, calculates the average of distinct values only; default is false
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function AVG($column_name, $alias = null, $distinct = false)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('AVG', [
            'column' => $column_name,
            'distinct' => $distinct
        ], $alias);
    }

    /**
     * Generate a SQL BETWEEN ... AND ... clause
     *
     * @param string $column_name The name of the column for the BETWEEN clause
     * @param string $min The minimum value for the range
     * @param string $max The maximum value for the range
     *
     * @return static
     */
    public static function BETWEEN($column_name, $min, $max)
    {
        $obj = new static();
        $values = [];
        $minSql = self::quoteValue($column_name, $min, $values);
        $maxSql = self::quoteValue($column_name, $max, $values);
        $obj->sql = self::column($column_name).' BETWEEN '.$minSql.' AND '.$maxSql;
        $obj->values = $values;
        return $obj;
    }

    /**
     * Generate a SQL CONCAT or CONCAT_WS clause
     *
     * @param array       $fields    List of fields to concatenate
     * @param string|null $alias     The alias for the resulting concatenation, optional
     * @param string|null $separator Null (default) to use CONCAT, specify a separator to use CONCAT_WS
     *
     * @throws \InvalidArgumentException If $fields is not an array
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function CONCAT($fields, $alias = null, $separator = null)
    {
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('$fields must be an array');
        }

        return new \Kotchasan\QueryBuilder\SqlFunction('CONCAT', [
            'fields' => $fields,
            'separator' => $separator
        ], $alias);
    }

    /**
     * Create a GROUP_CONCAT SQL statement
     *
     * @param string|array $fields      Field name or list of fields to concatenate (e.g., ['column1', '|', 'column2'])
     * @param string|null  $alias       The alias for the resulting concatenated column, optional
     * @param string       $separator   The separator to use between concatenated values, default is ','
     * @param bool         $distinct    If true, returns only distinct values; default is false
     * @param string|array $order       The order in which concatenated values should appear
     *
     * @return static
     */
    public static function GROUP_CONCAT($fields, $alias = null, $separator = ',', $distinct = false, $order = null)
    {
        // Normalize fields to array format for consistent handling
        if (!is_array($fields)) {
            $fields = [$fields];
        }

        return new \Kotchasan\QueryBuilder\SqlFunction('GROUP_CONCAT', [
            'fields' => $fields,
            'separator' => $separator,
            'distinct' => $distinct,
            'order' => $order
        ], $alias);
    }

    /**
     * Count the number of records for the selected column
     *
     * @param string      $column_name The name of the column to count, defaults to '*'
     * @param string|null $alias       The alias for the resulting count, optional
     * @param bool        $distinct    If true, counts only distinct values; default is false
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function COUNT($column_name = '*', $alias = null, $distinct = false)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('COUNT', [
            'column' => $column_name,
            'distinct' => $distinct
        ], $alias);
    }

    /**
     * Extract date from a DATETIME column
     *
     * @param string      $column_name The name of the DATETIME column
     * @param string|null $alias       The alias for the resulting date, optional
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function DATE($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DATE', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Calculate the difference in days between two dates or between a date and NOW()
     *
     * @param mixed       $column_name1 Column name (string), date literal (e.g. '2026-01-01'), or Sql/SqlFunction (e.g. Sql::NOW())
     * @param mixed       $column_name2 Column name (string), date literal, or Sql/SqlFunction
     * @param string|null $alias        The alias for the resulting difference, optional
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function DATEDIFF($column_name1, $column_name2, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DATEDIFF', [
            'column1' => $column_name1,
            'column2' => $column_name2
        ], $alias);
    }

    /**
     * Format a date column for display
     *
     * @param string      $column_name The name of the date column
     * @param string      $format      The format string for date formatting
     * @param string|null $alias       The alias for the resulting formatted date, optional
     *
     * @return static
     */
    public static function DATE_FORMAT($column_name, $format, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DATE_FORMAT', [
            'column' => $column_name,
            'format' => $format
        ], $alias);
    }

    /**
     * Extract the day from a DATE or DATETIME column
     *
     * @param string      $column_name The name of the DATE or DATETIME column
     * @param string|null $alias       The alias for the resulting day, optional
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function DAY($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DAY', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Return distinct values of a column
     *
     * @param string      $column_name The name of the column to retrieve distinct values from
     * @param string|null $alias       The alias for the resulting distinct values, optional
     *
     * @return static
     */
    public static function DISTINCT($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DISTINCT', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Extract sorting information and return as an array.
     *
     * @param array  $columns List of columns that are valid for sorting.
     * @param string $sort    Sorting instructions in the format 'column_name direction'.
     * @param array  $default Default sorting array if no valid sort instructions are provided.
     *
     * @return array|string
     */
    public static function extractSort($columns, $sort, $default = [])
    {
        $all_fields = implode('|', $columns);
        $sorts = explode(',', $sort);
        $result = [];

        foreach ($sorts as $item) {
            if (preg_match('/('.$all_fields.')([\s]+(asc|desc))?$/i', trim($item), $match)) {
                $result[] = $match[0];
            }
        }

        if (empty($result)) {
            return $default;
        } elseif (count($result) === 1) {
            return $result[0];
        } else {
            return $result;
        }
    }

    /**
     * Format a column for display
     *
     * This function generates a SQL FORMAT expression to format a column for display.
     *
     * @param string      $column_name The name of the column to format
     * @param string      $format      The format string for formatting
     * @param string|null $alias       The alias for the resulting formatted column, optional
     *
     * @return static
     */
    public static function FORMAT($column_name, $format, $alias = null)
    {
        $obj = new static();
        $values = [];
        $formatSql = self::quoteValue('format', $format, $values);
        $expression = 'FORMAT('.self::column($column_name).", {$formatSql})";

        if ($alias) {
            $expression .= ' AS '.self::quoteIdentifier($alias);
        }

        $obj->sql = $expression;
        $obj->values = $values;
        return $obj;
    }

    /**
     * Extract the hour from a DATETIME column
     *
     * @param string      $column_name The name of the DATETIME column to extract hours from.
     * @param string|null $alias       Optional alias for the HOUR() function result in SQL.
     *                                 If provided, formats the SQL as HOUR(...) AS `$alias`.
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function HOUR($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('HOUR', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Create an IFNULL SQL statement
     *
     * @param string      $column_name1 The first column name
     * @param string      $column_name2 The second column name
     * @param string|null $alias        The alias for the resulting expression, optional
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function IFNULL($column_name1, $column_name2, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('IFNULL', [
            'column1' => $column_name1,
            'column2' => $column_name2
        ], $alias);
    }

    /**
     * Create an IS NOT NULL SQL statement
     *
     * @param string $column_name The column name to check for not null
     *
     * @return static
     */
    public static function ISNOTNULL($column_name)
    {
        $expression = self::column($column_name).' IS NOT NULL';
        return self::create($expression);
    }

    /**
     * Create an IS NULL SQL statement
     *
     * @param string $column_name The column name to check for null
     *
     * @return static
     */
    public static function ISNULL($column_name)
    {
        $expression = self::column($column_name).' IS NULL';
        return self::create($expression);
    }

    /**
     * Find the maximum value of a column
     *
     * @param string      $column_name The column name to find the maximum value
     * @param string|null $alias       Optional alias for the result
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function MAX($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('MAX', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Find the minimum value of a column
     *
     * @param string      $column_name The column name to find the minimum value
     * @param string|null $alias       Optional alias for the result
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function MIN($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('MIN', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Returns the length of a string
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function LENGTH($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('LENGTH', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Convert a string to uppercase
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function UPPER($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('UPPER', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Convert a string to lowercase
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function LOWER($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('LOWER', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Trim whitespace from both sides of a string
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function TRIM($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('TRIM', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Trim whitespace from the left side of a string
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function LTRIM($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('LTRIM', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Trim whitespace from the right side of a string
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function RTRIM($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('RTRIM', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Extract a substring from a string
     *
     * @param string      $column_name
     * @param int         $start
     * @param int|null    $length
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function SUBSTRING($column_name, $start, $length = null, $alias = null)
    {
        $start = (int) $start;
        $length = $length === null ? null : (int) $length;

        return new \Kotchasan\QueryBuilder\SqlFunction('SUBSTRING', [
            'column' => $column_name,
            'start' => $start,
            'length' => $length
        ], $alias);
    }

    /**
     * Replace substring within a string
     *
     * @param string      $column_name
     * @param string      $search
     * @param string      $replace
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function REPLACE($column_name, $search, $replace, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('REPLACE', [
            'column' => $column_name,
            'search' => $search,
            'replace' => $replace
        ], $alias);
    }

    /**
     * Extract minutes from a DATETIME column
     *
     * This function generates a SQL MINUTE expression to extract minutes from a DATETIME column.
     *
     * @param string      $column_name The name of the column to extract minutes from
     * @param string|null $alias       Optional alias for the result
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function MINUTE($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('MINUTE', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Extract month from a DATE or DATETIME column
     *
     * @param string      $column_name The name of the column to extract the month from
     * @param string|null $alias       Optional alias for the result
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function MONTH($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('MONTH', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Generate SQL to find the next value in a sequence (MAX + 1)
     *
     * Used to find the next ID in a table.
     *
     * @param string $field      The field name to find the maximum value
     * @param string $table_name The table name
     * @param mixed  $condition  (optional) WHERE condition for the query
     * @param string $alias      (optional) Alias for the resulting field, null means no alias
     * @param string $operator   (optional) Logical operator like AND or OR
     * @param string $id         (optional) Key field name
     *
     * @return static
     */
    public static function NEXT($field, $table_name, $condition = null, $alias = null, $operator = 'AND', $id = 'id')
    {
        $obj = new static();

        // Build the WHERE clause if condition is provided
        if (!empty($condition)) {
            $condition = ' WHERE '.$obj->buildWhere($condition, $obj->values, $operator, $id);
        } else {
            $condition = '';
        }

        // Build the SQL expression to find next ID
        $obj->sql = '(1 + IFNULL((SELECT MAX(`'.$field.'`) FROM '.$table_name.' AS X'.$condition.'), 0))';

        // Add an alias if provided
        if (isset($alias)) {
            $obj->sql .= " AS `$alias`";
        }

        // Return the SQL object
        return $obj;
    }

    /**
     * Returns the current date and time as a SQL function NOW().
     *
     * @param string|null $alias Optional alias for the NOW() function result in SQL.
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function NOW($alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('NOW', [], $alias);
    }

    /**
     * Returns the current date
     *
     * @param string|null $alias
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function CURDATE($alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('CURDATE', [], $alias);
    }

    /**
     * Returns the current time
     *
     * @param string|null $alias
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function CURTIME($alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('CURTIME', [], $alias);
    }

    /**
     * Create a raw expression wrapper for QueryBuilder usage
     *
     * @param string $sql
     * @return \Kotchasan\QueryBuilder\RawExpression
     */
    public static function raw(string $sql)
    {
        return new \Kotchasan\QueryBuilder\RawExpression($sql);
    }

    /**
     * Create a column reference wrapper for QueryBuilder usage
     * Uses SqlFunction to handle database-specific identifier quoting
     *
     * @param string $column
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function column(string $column)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('COLUMN', [
            'column' => $column
        ]);
    }

    /**
     * Searches for a substring in a string and returns its position. If not found, returns 0; indexing starts from 1.
     *
     * @param string      $substr The substring to search for. If it's a field name, it should be enclosed in ``.
     * @param string      $str    The original string to search within. If it's a field name, it should be enclosed in ``.
     * @param string|null $alias  Optional alias for the result of the LOCATE() function in SQL.
     *                            If provided, formats the SQL as LOCATE(...) AS `$alias`.
     * @param int         $pos    Optional starting position for the search. Defaults to 0 (search from the beginning).
     *
     * @return static
     */
    public static function POSITION($substr, $str, $alias = null, $pos = 0)
    {
        // Adjust substrings if they are not field names to be SQL-compatible
        $substr = strpos($substr, '`') === false ? "'$substr'" : $substr;
        $str = strpos($str, '`') === false ? "'$str'" : $str;

        // Build the SQL expression for LOCATE() with optional alias and position
        $sql = "LOCATE($substr, $str".(empty($pos) ? ')' : ", $pos").($alias ? ' AS '.self::quoteIdentifier($alias) : '');

        // Assuming self::create() constructs or modifies a query or model object
        return self::create($sql);
    }

    /**
     * Generates a random number.
     *
     * @param string|null $alias Optional alias for the RAND() function result in SQL.
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function RAND($alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('RAND', [], $alias);
    }

    /**
     * Extracts the seconds from a DATETIME column.
     *
     * @param string      $column_name The name of the DATETIME column to extract seconds from.
     * @param string|null $alias       Optional alias for the SECOND() function result in SQL.
     *                                 If provided, formats the SQL as SECOND(...) AS `$alias`.
     *
     * @return static
     */
    public static function SECOND($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('SECOND', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Calculates the sum of values in a selected column.
     *
     * @param string      $column_name The name of the column to sum
     * @param string|null $alias       Optional alias for the SUM() function result in SQL
     * @param bool        $distinct    Optional. If true, sums only distinct values in the column
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function SUM($column_name, $alias = null, $distinct = false)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('SUM', [
            'column' => $column_name,
            'distinct' => $distinct
        ], $alias);
    }

    /**
     * Returns absolute value of a column
     *
     * @param string      $column_name
     * @param string|null $alias
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function ABS($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('ABS', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Returns the smallest integer greater than or equal to a number
     *
     * @param string      $column_name
     * @param string|null $alias
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function CEIL($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('CEIL', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Returns the largest integer less than or equal to a number
     *
     * @param string      $column_name
     * @param string|null $alias
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function FLOOR($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('FLOOR', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Rounds a number to a specified number of decimal places
     *
     * @param string      $column_name
     * @param int|null    $precision
     * @param string|null $alias
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function ROUND($column_name, $precision = null, $alias = null)
    {
        $precision = $precision === null ? null : (int) $precision;

        return new \Kotchasan\QueryBuilder\SqlFunction('ROUND', [
            'column' => $column_name,
            'precision' => $precision
        ], $alias);
    }

    /**
     * Calculates the time difference between two datetime columns or values.
     *
     * @param mixed       $column_name1 Column name (string), date literal (e.g. '2026-01-01'), or Sql/SqlFunction (e.g. Sql::NOW())
     * @param mixed       $column_name2 Column name (string), date literal, or Sql/SqlFunction
     * @param string|null $alias        Optional alias for the result
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function TIMEDIFF($column_name1, $column_name2, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('TIMEDIFF', [
            'column1' => $column_name1,
            'column2' => $column_name2
        ], $alias);
    }

    /**
     * Calculates the difference between two datetime columns or values in specified units.
     *
     * @param string      $unit         FRAC_SECOND, SECOND, MINUTE, HOUR, DAY, WEEK, MONTH, QUARTER, or YEAR
     * @param mixed       $column_name1 Column name (string), date literal (e.g. '2026-01-01'), or Sql/SqlFunction (e.g. Sql::NOW())
     * @param mixed       $column_name2 Column name (string), date literal, or Sql/SqlFunction
     * @param string|null $alias        Optional alias for the result
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function TIMESTAMPDIFF($unit, $column_name1, $column_name2, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('TIMESTAMPDIFF', [
            'unit' => $unit,
            'column1' => $column_name1,
            'column2' => $column_name2
        ], $alias);
    }

    /**
     * Add an interval to a date/datetime value
     *
     * @param mixed       $column_name Column, date literal (e.g. '2026-01-01'), or Sql/SqlFunction (e.g. Sql::NOW())
     * @param int         $interval    Number of units to add
     * @param string      $unit        SECOND, MINUTE, HOUR, DAY, WEEK, MONTH, QUARTER, YEAR
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function DATE_ADD($column_name, int $interval, string $unit, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DATE_ADD', [
            'column' => $column_name,
            'interval' => $interval,
            'unit' => strtoupper($unit)
        ], $alias);
    }

    /**
     * Subtract an interval from a date/datetime value
     *
     * @param mixed       $column_name Column, date literal, or Sql/SqlFunction (e.g. Sql::NOW())
     * @param int         $interval    Number of units to subtract
     * @param string      $unit        SECOND, MINUTE, HOUR, DAY, WEEK, MONTH, QUARTER, YEAR
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function DATE_SUB($column_name, int $interval, string $unit, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('DATE_SUB', [
            'column' => $column_name,
            'interval' => $interval,
            'unit' => strtoupper($unit)
        ], $alias);
    }

    /**
     * Find the position of a value in a comma-separated list (1-based; 0 if not found)
     *
     * MySQL-native; other drivers approximate using INSTR / CHARINDEX / ARRAY_POSITION.
     *
     * @param mixed       $value       Value to search for (column name, literal, or Sql/SqlFunction)
     * @param string      $column_name The column containing comma-separated values
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function FIND_IN_SET($value, $column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('FIND_IN_SET', [
            'value' => $value,
            'column' => $column_name
        ], $alias);
    }

    /**
     * Left-pad a string column to a target length with a pad character
     *
     * @param string      $column_name
     * @param int         $length
     * @param string      $pad_string  Padding character(s), default '0'
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function LPAD($column_name, int $length, string $pad_string = '0', $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('LPAD', [
            'column' => $column_name,
            'length' => $length,
            'pad' => $pad_string
        ], $alias);
    }

    /**
     * Return NULL if two expressions are equal, otherwise return the first expression
     *
     * Typical use: prevent division by zero — e.g. SUM(price) / NULLIF(SUM(qty), 0)
     *
     * @param mixed       $column_name1
     * @param mixed       $column_name2
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function NULLIF($column_name1, $column_name2, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('NULLIF', [
            'column1' => $column_name1,
            'column2' => $column_name2
        ], $alias);
    }

    /**
     * Extract the quarter (1–4) from a DATE or DATETIME column
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function QUARTER($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('QUARTER', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Right-pad a string column to a target length with a pad character
     *
     * @param string      $column_name
     * @param int         $length
     * @param string      $pad_string  Padding character(s), default ' '
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function RPAD($column_name, int $length, string $pad_string = ' ', $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('RPAD', [
            'column' => $column_name,
            'length' => $length,
            'pad' => $pad_string
        ], $alias);
    }

    /**
     * Convert a string to a DATE using a format pattern
     *
     * @param mixed       $value  String value, column name, or Sql/SqlFunction containing the date string
     * @param string      $format MySQL DATE_FORMAT-compatible format string (e.g. '%Y-%m-%d')
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function STR_TO_DATE($value, string $format, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('STR_TO_DATE', [
            'value' => $value,
            'format' => $format
        ], $alias);
    }

    /**
     * Extract the time portion (HH:MM:SS) from a DATETIME column
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function TIME($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('TIME', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Extract the ISO week number (1–53) from a DATE or DATETIME column
     *
     * @param string      $column_name
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function WEEK($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('WEEK', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * IF expression helper
     *
     * @param string $condition
     * @param mixed  $value_true
     * @param mixed  $value_false
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function IF_EXPR($condition, $value_true, $value_false, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('IF_EXPR', [
            'condition' => $condition,
            'value_true' => $value_true,
            'value_false' => $value_false
        ], $alias);
    }

    /**
     * CASE WHEN helper
     *
     * @param array       $cases Array of [condition, result]
     * @param mixed|null  $else
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function CASE_WHEN(array $cases, $else = null, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('CASE_WHEN', [
            'cases' => $cases,
            'else' => $else
        ], $alias);
    }

    /**
     * COALESCE helper
     *
     * @param array       $values
     * @param string|null $alias
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function COALESCE(array $values, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('COALESCE', [
            'values' => $values
        ], $alias);
    }

    /**
     * IN expression helper with bindings
     *
     * @param string $column_name
     * @param array  $values_list
     *
     * @return static
     */
    public static function IN($column_name, array $values_list)
    {
        $obj = new static();
        $values = [];

        if (empty($values_list)) {
            $obj->sql = '0=1';
            $obj->values = $values;
            return $obj;
        }

        $placeholders = [];
        foreach ($values_list as $value) {
            $placeholders[] = self::quoteValue($column_name, $value, $values);
        }

        $obj->sql = self::column($column_name).' IN ('.implode(', ', $placeholders).')';
        $obj->values = $values;
        return $obj;
    }

    /**
     * NOT IN expression helper with bindings
     *
     * @param string $column_name
     * @param array  $values_list
     *
     * @return static
     */
    public static function NOT_IN($column_name, array $values_list)
    {
        $obj = new static();
        $values = [];

        if (empty($values_list)) {
            $obj->sql = '1=1';
            $obj->values = $values;
            return $obj;
        }

        $placeholders = [];
        foreach ($values_list as $value) {
            $placeholders[] = self::quoteValue($column_name, $value, $values);
        }

        $obj->sql = self::column($column_name).' NOT IN ('.implode(', ', $placeholders).')';
        $obj->values = $values;
        return $obj;
    }

    /**
     * Extracts the year from a DATE or DATETIME column.
     *
     * @param string      $column_name The name of the DATE or DATETIME column
     * @param string|null $alias       Optional alias for the YEAR() function result in SQL
     *
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function YEAR($column_name, $alias = null)
    {
        return new \Kotchasan\QueryBuilder\SqlFunction('YEAR', [
            'column' => $column_name
        ], $alias);
    }

    /**
     * Class constructor
     *
     * @param string $sql
     */
    public function __construct($sql = null)
    {
        $this->sql = $sql;
        $this->values = [];
    }

    /**
     * Create Object Sql
     *
     * @param string $sql
     */
    public static function create($sql)
    {
        return new static($sql);
    }

    /**
     * Retrieves or merges bind parameters ($values) used for prepared statements in SQL queries.
     *
     * @param array $values Optional. An array of bind parameters to merge or retrieve.
     *
     * @return array
     */
    public function getValues($values = [])
    {
        // If no existing values provided, return this object's values directly
        if (empty($values)) {
            return $this->values;
        }

        // Merge values: preserve named placeholders (string keys), append numeric keys
        foreach ($this->values as $key => $value) {
            if (is_int($key)) {
                $values[] = $value;
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Merge bindings from a source into the target $values array.
     * Preserves named (string) keys and appends numeric keys in order.
     *
     * @param array $values  Reference to target values array.
     * @param array $bindings Source bindings to merge.
     * @return void
     */
    protected static function mergeBindings(array &$values, array $bindings)
    {
        foreach ($bindings as $k => $v) {
            // If binding is a QueryBuilder, expand its bindings recursively
            if ($v instanceof \Kotchasan\QueryBuilder\QueryBuilder) {
                $subOriginal = $v->toSql();
                $subFrag = $subOriginal;
                // first, extract any column subquery bindings inside this QueryBuilder
                self::extractQueryBuilderColumnBindings($v, $values, $subFrag);
                // then get its own bindings and merge them
                $subBindings = $v->getBindings();
                if (!empty($subBindings)) {
                    // merge positional bindings
                    $allBindings = [];
                    foreach ($subBindings as $b) {
                        $allBindings[] = $b;
                    }
                    // Also include namedBindings and embeddedBindings from QueryBuilder via reflection
                    try {
                        $refQB = new \ReflectionObject($v);
                        if ($refQB->hasProperty('namedBindings')) {
                            $p = $refQB->getProperty('namedBindings');
                            self::ensurePropertyAccessible($p);
                            $nb = $p->getValue($v);
                            if (!empty($nb)) {
                                foreach ($nb as $k => $vv) {
                                    // ensure key starts with ':'
                                    $name = is_string($k) && strpos($k, ':') === 0 ? $k : ':'.$k;
                                    $allBindings[$name] = $vv;
                                }
                            }
                        }
                        if ($refQB->hasProperty('embeddedBindings')) {
                            $p2 = $refQB->getProperty('embeddedBindings');
                            self::ensurePropertyAccessible($p2);
                            $eb = $p2->getValue($v);
                            if (!empty($eb)) {
                                foreach ($eb as $k => $vv) {
                                    $name = is_string($k) && strpos($k, ':') === 0 ? $k : ':'.$k;
                                    $allBindings[$name] = $vv;
                                }
                            }
                        }
                    } catch (\ReflectionException $e) {
                        // ignore
                    }

                    // rewrite placeholder names in $subFrag if necessary
                    $frag = $subFrag;
                    self::mergeBindingsWithNamespace($values, $allBindings, $frag);
                    $subFrag = $frag;
                }
                // Sql object binding: handled above (recursive merge) in the caller contexts
            }
            if (is_int($k)) {
                $values[] = $v;
            } else {
                $values[$k] = $v;
            }
        }
    }

    /**
     * Counter for generating unique placeholder namespaces
     *
     * @var int
     */
    protected static $placeholderCounter = 0;

    /**
     * Safely make a ReflectionProperty accessible when needed.
     *
     * @param \ReflectionProperty $property
     * @return void
     */
    protected static function ensurePropertyAccessible(\ReflectionProperty $property): void
    {
        if (!$property->isPublic() && method_exists($property, 'setAccessible')) {
            $property->setAccessible(true);
        }
    }

    /**
     * Merge bindings and optionally rename named placeholders in the provided SQL fragment to avoid collisions.
     * If $sqlFragment is provided, named placeholders appearing in $bindings will be replaced with namespaced ones
     * and the mapping will be applied to the fragment.
     *
     * Implementation notes:
     * - Numeric (positional) bindings are appended in order.
     * - Named placeholders are preserved where possible; when a name collision is detected we
     *   generate a stable namespaced replacement (eg. :s1_id) and rewrite the SQL fragment
     *   so the new placeholder name is used.
     * - When a binding value is itself an `Sql` object, we recursively extract its inner values and
     *   rewrite the parent fragment to substitute the embedded sub-fragment with its rewritten version.
     *
     * Caveats:
     * - Placeholder renaming relies on regex-based token replacement; extremely unusual token
     *   patterns may require additional tests.
     * - This routine is intentionally conservative: it only rewrites fragments when a mapping
     *   is required to avoid collisions.
     *
     * @param array $values Reference to target values
     * @param array $bindings Source bindings
     * @param string|null $sqlFragment Reference to SQL fragment string to rewrite (optional)
     * @return void
     */
    protected static function mergeBindingsWithNamespace(array &$values, array $bindings,  ? string &$sqlFragment = null)
    {
        // mapping oldName => newName for replacements
        $mapping = [];
        foreach ($bindings as $k => $v) {
            // If the binding itself is an Sql object, expand it: merge its inner values and
            // rewrite occurrences of the embedded fragment inside the parent fragment.
            if ($v instanceof self) {
                // original fragment produced by the Sql object
                $subOriginal = $v->toSql();
                $subFrag = $subOriginal;
                $subBindings = $v->getValues([]);
                if (!empty($subBindings)) {
                    // recursively merge inner bindings; this will populate $values and rewrite $subFrag
                    self::mergeBindingsWithNamespace($values, $subBindings, $subFrag);
                    // replace occurrences of the original subfragment in parent fragment with rewritten one
                    if ($sqlFragment !== null && $subFrag !== $subOriginal) {
                        $sqlFragment = str_replace($subOriginal, $subFrag, $sqlFragment);
                    }
                }
                // nothing else to append for the Sql object itself (its values were merged)
                continue;
            }

            if (is_int($k)) {
                $values[] = $v;
            } else {
                // ensure placeholder format starts with :
                $name = $k;
                if ($name[0] !== ':') {
                    $name = ':'.$name;
                }

                if (array_key_exists($name, $values)) {
                    // collision - generate a new namespaced name
                    $ns = 's'.(++self::$placeholderCounter).'_';
                    $newName = ':'.$ns.substr($name, 1);
                    // avoid collisions for newName as well
                    while (array_key_exists($newName, $values) || isset($mapping[$newName])) {
                        $ns = 's'.(++self::$placeholderCounter).'_';
                        $newName = ':'.$ns.substr($name, 1);
                    }
                    $mapping[$name] = $newName;
                    $values[$newName] = $v;
                } else {
                    // no collision
                    $values[$name] = $v;
                }
            }
        }

        // if we need to rewrite SQL fragment, apply mapping
        if ($sqlFragment !== null && !empty($mapping)) {
            // replace placeholders using regex to match token boundaries
            foreach ($mapping as $old => $new) {
                $pattern = '/'.preg_quote($old, '/').'(?![A-Za-z0-9_])/';
                $sqlFragment = preg_replace($pattern, $new, $sqlFragment);
            }
        }
    }

    /**
     * Inspect a QueryBuilder's columns for nested QueryBuilder or Sql objects and merge their bindings.
     * This does not modify the QueryBuilder itself; it only merges nested column bindings into $values
     * and rewrites $sqlFragment occurrences if needed.
     *
     * @param \Kotchasan\QueryBuilder\QueryBuilder $qb
     * @param array $values
     * @param string|null $sqlFragment
     * @return void
     */
    protected static function extractQueryBuilderColumnBindings(\Kotchasan\QueryBuilder\QueryBuilder $qb, array &$values,  ? string &$sqlFragment = null)
    {
        try {
            $ref = new \ReflectionObject($qb);
            if ($ref->hasProperty('columns')) {
                $prop = $ref->getProperty('columns');
                self::ensurePropertyAccessible($prop);
                $cols = $prop->getValue($qb);
                if (!empty($cols) && is_array($cols)) {
                    foreach ($cols as $col) {
                        $expr = is_array($col) && count($col) > 0 ? $col[0] : $col;
                        if (is_object($expr)) {
                            if (method_exists($expr, 'getBindings')) {
                                // Merge any bindings directly present on the expression
                                $subFrag = $expr->toSql();
                                $subBindings = $expr->getBindings();
                                // also include namedBindings and embeddedBindings from QueryBuilder/Sql-like objects
                                try {
                                    $refExpr = new \ReflectionObject($expr);
                                    if ($refExpr->hasProperty('namedBindings')) {
                                        $p = $refExpr->getProperty('namedBindings');
                                        self::ensurePropertyAccessible($p);
                                        $nb = $p->getValue($expr);
                                        if (!empty($nb)) {
                                            foreach ($nb as $k => $v) {
                                                $subBindings[$k] = $v;
                                            }
                                        }
                                    }
                                    if ($refExpr->hasProperty('embeddedBindings')) {
                                        $p2 = $refExpr->getProperty('embeddedBindings');
                                        self::ensurePropertyAccessible($p2);
                                        $eb = $p2->getValue($expr);
                                        if (!empty($eb)) {
                                            foreach ($eb as $k => $v) {
                                                $subBindings[$k] = $v;
                                            }
                                        }
                                    }
                                } catch (\ReflectionException $e) {
                                    // ignore
                                }
                                if (!empty($subBindings)) {
                                    // Iterate each binding item: expand nested QueryBuilder/Sql objects too
                                    foreach ($subBindings as $sbk => $sbv) {
                                        if ($sbv instanceof self) {
                                            // Sql object -> merge its values and rewrite subFrag
                                            $innerFrag = $sbv->toSql();
                                            $innerBindings = $sbv->getValues([]);
                                            if (!empty($innerBindings)) {
                                                self::mergeBindingsWithNamespace($values, $innerBindings, $innerFrag);
                                                if ($sqlFragment !== null && $innerFrag !== $expr->toSql()) {
                                                    $pattern = '/'.preg_quote($expr->toSql(), '/').'(?![A-Za-z0-9_])/';
                                                    $replacement = str_replace($innerFrag, $innerFrag, $expr->toSql());
                                                    $sqlFragment = preg_replace($pattern, $replacement, $sqlFragment);
                                                }
                                            }
                                        } elseif ($sbv instanceof \Kotchasan\QueryBuilder\QueryBuilder) {
                                            // QueryBuilder inside bindings: extract its columns and merge its bindings recursively
                                            self::extractQueryBuilderColumnBindings($sbv, $values, $subFrag);
                                            $innerBindings = $sbv->getBindings();
                                            if (!empty($innerBindings)) {
                                                self::mergeBindingsWithNamespace($values, $innerBindings, $subFrag);
                                            }
                                        } else {
                                            // primitive value -> merge directly
                                            if (is_int($sbk)) {
                                                $values[] = $sbv;
                                            } else {
                                                $name = $sbk;
                                                if ($name[0] !== ':') {
                                                    $name = ':'.$name;
                                                }
                                                $values[$name] = $sbv;
                                            }
                                        }
                                    }
                                    // after processing bindings, if subFrag changed, replace in parent SQL fragment
                                    if ($sqlFragment !== null && $subFrag !== $expr->toSql()) {
                                        $pattern = '/'.preg_quote($expr->toSql(), '/').'(?![A-Za-z0-9_])/';
                                        $sqlFragment = preg_replace($pattern, $subFrag, $sqlFragment);
                                    }
                                }
                            } elseif (method_exists($expr, 'getValues')) {
                                $subFrag = $expr->toSql();
                                $subBindings = $expr->getValues([]);
                                if (!empty($subBindings)) {
                                    self::mergeBindingsWithNamespace($values, $subBindings, $subFrag);
                                    if ($sqlFragment !== null && $subFrag !== $expr->toSql()) {
                                        $pattern = '/'.preg_quote($expr->toSql(), '/').'(?![A-Za-z0-9_])/';
                                        $sqlFragment = preg_replace($pattern, $subFrag, $sqlFragment);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // ignore reflection errors
        }
    }

    /**
     * Quotes and prepares a value for use in SQL queries, handling various data types and formats.
     *
     * @param string $column_name The column name or identifier to associate with the value.
     * @param mixed  $value       The value to quote and prepare for the query.
     * @param array  $values      Reference to an array to store bind parameters for prepared statements.
     *
     * @throws \InvalidArgumentException If the value format is invalid or not handled.
     *
     * @return string|int
     */
    public static function quoteValue($column_name, $value, &$values)
    {
        if (is_array($value)) {
            $qs = [];
            foreach ($value as $v) {
                $qs[] = self::quoteValue($column_name, $v, $values);
            }
            $sql = '('.implode(', ', $qs).')';
        } elseif ($value === null) {
            $sql = 'NULL';
        } elseif ($value === '') {
            $sql = "''";
        } elseif (is_string($value)) {
            if (preg_match('/^([0-9\s\r\n\t\.\_\-:]+)$/', $value)) {
                $sql = "'$value'";
            } elseif (preg_match('/0x[0-9]+/is', $value)) {
                $sql = ':'.strtolower(preg_replace('/[`\.\s\-_]+/', '', $column_name));
                if (empty($values) || !is_array($values)) {
                    $sql .= 0;
                } else {
                    $sql .= count($values);
                }
                $values[$sql] = $value;
            } else {
                if (preg_match('/^(([A-Z][0-9]{0,2})|`([a-zA-Z0-9_]+)`)\.`?([a-zA-Z0-9_]+)`?$/', $value, $match)) {
                    $sql = $match[3] == '' ? "$match[2].".self::column($match[4]) : self::column($match[3]).'.'.self::column($match[4]);
                } elseif (preg_match('/^([a-zA-Z0-9_]+)\.`([a-zA-Z0-9_]+)`$/', $value, $match)) {
                    $sql = self::column($match[1]).'.'.self::column($match[2]);
                } elseif (!preg_match('/[\s\r\n\t`;\(\)\*=<>\/\'\"]+/s', $value) && !preg_match('/(UNION|INSERT|DELETE|TRUNCATE|DROP|0x[0-9]+)/is', $value)) {
                    $sql = "'$value'";
                } else {
                    $sql = ':'.strtolower(preg_replace('/[`\.\s\-_]+/', '', $column_name));
                    if (empty($values) || !is_array($values)) {
                        $sql .= 0;
                    } else {
                        $sql .= count($values);
                    }
                    $values[$sql] = $value;
                }
            }
        } elseif (is_numeric($value)) {
            $sql = $value;
        } elseif ($value instanceof self) {
            $sql = $value->toSql($column_name);
            $frag = $sql;
            $bindings = $value->getValues([]);
            if (!empty($bindings)) {
                self::mergeBindingsWithNamespace($values, $bindings, $frag);
                $sql = $frag;
            }
        } elseif ($value instanceof QueryBuilder) {
            // Return parenthesized subquery SQL so quoteValue produces a SQL literal
            $sql = '('.$value->toSql().')';
            // For quoteValue we want the subquery's bindings as positional values
            $posBindings = $value->getBindings();
            if (!empty($posBindings)) {
                foreach ($posBindings as $b) {
                    $values[] = $b;
                }
            }
        } else {
            throw new \InvalidArgumentException('Invalid arguments in quoteValue');
        }

        return $sql;
    }

    /**
     * Creates a SQL string literal by wrapping the given value in single quotes.
     *
     * @param string $value The string value to be wrapped in single quotes.
     *
     * @return static
     */
    public static function _strValue($value)
    {
        return self::create("'$value'");
    }

    /**
     * Returns the SQL command as a string.
     * If $sql is null, returns :$key for binding purposes.
     *
     * @param string|null $key The key used for binding (optional).
     *
     * @return string
     *
     * @throws \InvalidArgumentException When $key is provided but empty.
     */
    public function toSql($key = null)
    {
        if ($this->sql === null) {
            if (is_string($key) && $key != '') {
                return ':'.preg_replace('/[\.`]/', '', strtolower($key));
            } else {
                throw new \InvalidArgumentException('$key must be a non-empty string');
            }
        } else {
            return $this->sql;
        }
    }

    /**
     * Constructs SQL WHERE command based on given conditions.
     *
     * @param mixed  $condition The condition(s) to build into WHERE clause.
     * @param array  $values    Array to collect values for parameter binding.
     * @param string $operator  Logical operator (e.g., AND, OR) to combine multiple conditions.
     * @param string $id        Field name used as key in conditions.
     *
     * @return string
     */
    private function buildWhere($condition, &$values, $operator, $id)
    {
        if (is_array($condition)) {
            $qs = [];

            if (is_array($condition[0])) {
                foreach ($condition as $item) {
                    if ($item instanceof QueryBuilder) {
                        // include potential bindings from columns inside the QueryBuilder before merging
                        // avoid adding an extra pair of parentheses here; let the outer builder decide grouping
                        $frag = $item->toSql();
                        self::extractQueryBuilderColumnBindings($item, $values, $frag);
                        $bindings = $item->getBindings();
                        // also include namedBindings and embeddedBindings via reflection and merge them explicitly
                        try {
                            $refItem = new \ReflectionObject($item);
                            if ($refItem->hasProperty('namedBindings')) {
                                $p = $refItem->getProperty('namedBindings');
                                self::ensurePropertyAccessible($p);
                                $nb = $p->getValue($item);
                                if (!empty($nb)) {
                                    // merge named bindings (may require placeholder renaming)
                                    self::mergeBindingsWithNamespace($values, $nb, $frag);
                                }
                            }
                            if ($refItem->hasProperty('embeddedBindings')) {
                                $p2 = $refItem->getProperty('embeddedBindings');
                                self::ensurePropertyAccessible($p2);
                                $eb = $p2->getValue($item);
                                if (!empty($eb)) {
                                    // merge embedded bindings as well
                                    self::mergeBindingsWithNamespace($values, $eb, $frag);
                                }
                            }
                        } catch (\ReflectionException $e) {
                            // ignore
                        }

                        // finally merge positional/named from getBindings()
                        if (!empty($bindings)) {
                            self::mergeBindingsWithNamespace($values, $bindings, $frag);
                            // update fragment in place for this item (no extra parentheses)
                            $qs[] = $frag;
                            continue;
                        }

                        // if only column-extracted changes made, replace fragment
                        if ($frag !== $item->toSql()) {
                            $qs[] = $frag;
                            continue;
                        }
                    } elseif ($item instanceof self) {
                        $frag = $item->toSql();
                        $bindings = $item->getValues([]);
                        if (!empty($bindings)) {
                            self::mergeBindingsWithNamespace($values, $bindings, $frag);
                        }
                        $qs[] = $frag;
                    } else {
                        $qs[] = $this->buildWhere($item, $values, $operator, $id);
                    }
                }
                $sql = count($qs) > 1 ? '('.implode(' '.$operator.' ', $qs).')' : implode(' '.$operator.' ', $qs);
            } else {
                if ($condition[0] instanceof QueryBuilder) {
                    $key = $condition[0]->toSql();
                    $frag = $condition[0]->toSql();
                    // extract bindings from any column subqueries inside this QueryBuilder
                    self::extractQueryBuilderColumnBindings($condition[0], $values, $frag);
                    $bindings = $condition[0]->getBindings();
                    // include named/embedded bindings from nested QueryBuilder
                    try {
                        $refC = new \ReflectionObject($condition[0]);
                        if ($refC->hasProperty('namedBindings')) {
                            $p = $refC->getProperty('namedBindings');
                            self::ensurePropertyAccessible($p);
                            $nb = $p->getValue($condition[0]);
                            if (!empty($nb)) {
                                foreach ($nb as $k => $v) {
                                    $bindings[$k] = $v;
                                }
                            }
                        }
                        if ($refC->hasProperty('embeddedBindings')) {
                            $p2 = $refC->getProperty('embeddedBindings');
                            self::ensurePropertyAccessible($p2);
                            $eb = $p2->getValue($condition[0]);
                            if (!empty($eb)) {
                                foreach ($eb as $k => $v) {
                                    $bindings[$k] = $v;
                                }
                            }
                        }
                    } catch (\ReflectionException $e) {
                        // ignore
                    }
                    if (!empty($bindings)) {
                        self::mergeBindingsWithNamespace($values, $bindings, $frag);
                        $key = $frag;
                    } else {
                        // if only column extractions rewrote the fragment
                        if ($frag !== $condition[0]->toSql()) {
                            $key = $frag;
                        }
                    }
                } elseif ($condition[0] instanceof self) {
                    $frag = $condition[0]->toSql();
                    $bindings = $condition[0]->getValues([]);
                    if (!empty($bindings)) {
                        self::mergeBindingsWithNamespace($values, $bindings, $frag);
                    }
                    $key = $frag;
                } elseif ($condition[0] instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                    // Handle SqlFunction objects (including our COLUMN function)
                    $key = $condition[0]->toSql();
                } else {
                    $key = self::column($condition[0])->toSql();
                }

                $c = count($condition);

                if ($c == 2) {
                    if ($condition[1] instanceof QueryBuilder) {
                        $operator = 'IN';
                        $value = $condition[1]->toSql();
                        $bindings = $condition[1]->getBindings();
                        try {
                            $refC = new \ReflectionObject($condition[1]);
                            if ($refC->hasProperty('namedBindings')) {
                                $p = $refC->getProperty('namedBindings');
                                self::ensurePropertyAccessible($p);
                                $nb = $p->getValue($condition[1]);
                                if (!empty($nb)) {
                                    foreach ($nb as $k => $v) {
                                        $bindings[$k] = $v;
                                    }
                                }
                            }
                            if ($refC->hasProperty('embeddedBindings')) {
                                $p2 = $refC->getProperty('embeddedBindings');
                                self::ensurePropertyAccessible($p2);
                                $eb = $p2->getValue($condition[1]);
                                if (!empty($eb)) {
                                    foreach ($eb as $k => $v) {
                                        $bindings[$k] = $v;
                                    }
                                }
                            }
                        } catch (\ReflectionException $e) {
                            // ignore
                        }
                        if (!empty($bindings)) {
                            $frag = $condition[1]->toSql();
                            self::mergeBindingsWithNamespace($values, $bindings, $frag);
                            $value = '('.$frag.')';
                        }
                    } elseif ($condition[1] instanceof self) {
                        $operator = '=';
                        $frag = $condition[1]->toSql();
                        $bindings = $condition[1]->getValues([]);
                        if (!empty($bindings)) {
                            self::mergeBindingsWithNamespace($values, $bindings, $frag);
                        }
                        $value = $frag;
                    } elseif ($condition[1] === null) {
                        $operator = 'IS';
                        $value = 'NULL';
                    } else {
                        $operator = '=';
                        if (is_array($condition[1]) && $operator == '=') {
                            $operator = 'IN';
                        }
                        $value = self::quoteValue($key, $condition[1], $values);
                    }
                } elseif ($c == 3) {
                    if ($condition[2] instanceof QueryBuilder) {
                        $operator = trim($condition[1]);
                        $value = $condition[2]->toSql();
                        $bindings = $condition[2]->getBindings();
                        try {
                            $refC = new \ReflectionObject($condition[2]);
                            if ($refC->hasProperty('namedBindings')) {
                                $p = $refC->getProperty('namedBindings');
                                self::ensurePropertyAccessible($p);
                                $nb = $p->getValue($condition[2]);
                                if (!empty($nb)) {
                                    foreach ($nb as $k => $v) {
                                        $bindings[$k] = $v;
                                    }
                                }
                            }
                            if ($refC->hasProperty('embeddedBindings')) {
                                $p2 = $refC->getProperty('embeddedBindings');
                                self::ensurePropertyAccessible($p2);
                                $eb = $p2->getValue($condition[2]);
                                if (!empty($eb)) {
                                    foreach ($eb as $k => $v) {
                                        $bindings[$k] = $v;
                                    }
                                }
                            }
                        } catch (\ReflectionException $e) {
                            // ignore
                        }
                        if (!empty($bindings)) {
                            $frag = $condition[2]->toSql();
                            self::mergeBindingsWithNamespace($values, $bindings, $frag);
                            $value = '('.$frag.')';
                        }
                    } elseif ($condition[2] instanceof self) {
                        $operator = trim($condition[1]);
                        $frag = $condition[2]->toSql();
                        $bindings = $condition[2]->getValues([]);
                        if (!empty($bindings)) {
                            self::mergeBindingsWithNamespace($values, $bindings, $frag);
                        }
                        $value = $frag;
                    } elseif ($condition[2] === null) {
                        $operator = trim($condition[1]);
                        if ($operator == '=') {
                            $operator = 'IS';
                        } elseif ($operator == '!=') {
                            $operator = 'IS NOT';
                        }
                        $value = 'NULL';
                    } else {
                        $operator = trim($condition[1]);
                        if (is_array($condition[2]) && $operator == '=') {
                            $operator = 'IN';
                        }
                        $value = self::quoteValue($key, $condition[2], $values);
                    }
                }

                if (isset($value)) {
                    $sql = $key.' '.$operator.' '.$value;
                } else {
                    $sql = $key;
                }
            }
        } elseif ($condition instanceof QueryBuilder) {
            $sql = $condition->toSql();
            $bindings = $condition->getBindings();
            // also include named and embedded bindings from the QueryBuilder instance
            try {
                $refC = new \ReflectionObject($condition);
                if ($refC->hasProperty('namedBindings')) {
                    $p = $refC->getProperty('namedBindings');
                    self::ensurePropertyAccessible($p);
                    $nb = $p->getValue($condition);
                    if (!empty($nb)) {
                        foreach ($nb as $k => $v) {
                            $bindings[$k] = $v;
                        }
                    }
                }
                if ($refC->hasProperty('embeddedBindings')) {
                    $p2 = $refC->getProperty('embeddedBindings');
                    self::ensurePropertyAccessible($p2);
                    $eb = $p2->getValue($condition);
                    if (!empty($eb)) {
                        foreach ($eb as $k => $v) {
                            $bindings[$k] = $v;
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
            if (!empty($bindings)) {
                // debug
                $frag = $condition->toSql();
                self::mergeBindingsWithNamespace($values, $bindings, $frag);
                $sql = '('.$frag.')';
            }
        } elseif ($condition instanceof self) {
            $frag = $condition->toSql();
            $bindings = $condition->getValues([]);
            if (!empty($bindings)) {
                self::mergeBindingsWithNamespace($values, $bindings, $frag);
            }
            $sql = $frag;
        } else {
            $sql = self::column($id).' = '.self::quoteValue($id, $condition, $values);
        }

        return $sql;
    }

    /**
     * @return mixed
     */
    public function __toString()
    {
        return $this->toSql();
    }

    /**
     * Indicates whether this SQL expression needs to be wrapped in parentheses
     * when used in SELECT clauses. SQL functions typically don't need parentheses,
     * while subqueries do.
     *
     * @return bool
     */
    public function needsParentheses() : bool
    {
        return false; // SQL functions don't need parentheses in SELECT
    }

    /**
     * Create a field name (column identifier) - alias for column()
     *
     * @param string $name The field/column name
     * @return \Kotchasan\QueryBuilder\SqlFunction
     */
    public static function fieldName($name)
    {
        return self::column($name);
    }
}
