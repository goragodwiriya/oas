<?php
namespace Kotchasan\QueryBuilder;

use Kotchasan\Connection\ConnectionInterface;
use Kotchasan\Exception\DatabaseException;
use Kotchasan\QueryBuilder\Factory\SqlBuilderFactory;
use Kotchasan\QueryBuilder\SqlBuilder\SqlBuilderInterface;
use Kotchasan\Result\ResultInterface;

/**
 * Abstract class QueryBuilder
 *
 * Base implementation of the QueryBuilderInterface.
 *
 * @package Kotchasan\QueryBuilder
 */
abstract class QueryBuilder implements QueryBuilderInterface
{
    /**
     * The database connection.
     *
     * @var ConnectionInterface
     */
    protected ConnectionInterface $connection;

    /**
     * The SQL builder for this connection.
     *
     * @var SqlBuilderInterface|null
     */
    protected ?SqlBuilderInterface $sqlBuilder = null;

    /**
     * Stores the last built SQL query.
     *
     * @var string|null
     */
    protected ?string $lastQuery = null;

    /**
     * The query type (SELECT, INSERT, UPDATE, DELETE).
     *
     * @var string
     */
    protected string $type = '';

    /**
     * The table to query.
     *
     * @var string
     */
    protected string $table = '';

    /**
     * The table alias.
     *
     * @var string|null
     */
    protected ?string $alias = null;

    /**
     * The columns to select.
     *
     * @var array
     */
    protected array $columns = [];

    /**
     * The WHERE conditions.
     *
     * @var array
     */
    protected array $wheres = [];

    /**
     * The JOIN clauses.
     *
     * @var array
     */
    protected array $joins = [];

    /**
     * The ORDER BY clauses.
     *
     * @var array
     */
    protected array $orders = [];

    /**
     * The GROUP BY clauses.
     *
     * @var array
     */
    protected array $groups = [];

    /**
     * The HAVING conditions.
     *
     * @var array
     */
    protected array $havings = [];

    /**
     * The UNION clauses.
     *
     * @var array Each entry: ['query' => QueryBuilderInterface, 'all' => bool]
     */
    protected array $unions = [];

    /**
     * The LIMIT value.
     *
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * The OFFSET value.
     *
     * @var int|null
     */
    protected ?int $offset = null;

    /**
     * The values for INSERT or UPDATE queries.
     *
     * @var array
     */
    protected array $values = [];

    /**
     * The parameter bindings.
     *
     * @var array
     */
    protected array $bindings = [];

    /**
     * Named parameter bindings.
     *
     * @var array
     */
    protected array $namedBindings = [];

    /**
     * Bindings from embedded subqueries (used only for debug substitution, not returned by getBindings()).
     *
     * @var array
     */
    protected array $embeddedBindings = [];

    /**
     * Counter for generating unique named parameter keys.
     *
     * @var int
     */
    protected int $paramCounter = 0;

    /**
     * Flag to indicate if using named parameters.
     * Default to true to avoid positional binding issues when merging subqueries.
     *
     * @var bool
     */
    protected bool $useNamedParameters = true;
    /**
     * Placeholder prefix for generated named parameters to avoid collisions with user placeholders
     *
     * @var string
     */
    protected string $placeholderPrefix = ':qb_p';

    /**
     * Whether to use caching for this query.
     *
     * @var bool
     */
    protected bool $useCache = false;

    /**
     * The cache TTL in seconds.
     *
     * @var int|null
     */
    protected ?int $cacheTtl = null;

    /**
     * Whether to automatically save cache after query execution.
     *
     * @var bool
     */
    protected bool $autoSaveCache = true;

    /**
     * Whether to explain the query.
     *
     * @var bool
     */
    protected bool $explain = false;

    /**
     * QueryBuilder constructor.
     *
     * @param ConnectionInterface $connection The database connection.
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->sqlBuilder = SqlBuilderFactory::create($connection);
    }

    /**
     * Get the SQL builder for this connection.
     *
     * @return SqlBuilderInterface
     */
    protected function getSqlBuilder(): SqlBuilderInterface
    {
        if ($this->sqlBuilder === null) {
            $this->sqlBuilder = SqlBuilderFactory::create($this->connection);
        }
        return $this->sqlBuilder;
    }

    /**
     * Safely quote an identifier (table or column) for SQL output.
     * Delegates to the appropriate SQL builder.
     *
     * @param string $id
     * @return string
     */
    protected function quoteIdentifier(string $id): string
    {
        return $this->getSqlBuilder()->quoteIdentifier($id);
    }

    /**
     * {@inheritdoc}
     */
    public function select(...$columns): QueryBuilderInterface
    {
        if ((empty($columns) || (count($columns) === 1 && empty($columns[0]))) && !empty($this->columns)) {
            // If no columns specified, use previously set columns
            return $this;
        }

        $args = empty($columns) ? ['*'] : $columns;
        $selectColumns = [];

        foreach ($args as $arg) {
            $this->normalizeSelectColumns($selectColumns, $arg, false);
        }

        if (empty($selectColumns)) {
            $selectColumns = ['*'];
        }

        $this->type = 'SELECT';
        $this->columns = $selectColumns;

        return $this;
    }

    /**
     * Normalize select() arguments into a flat columns array.
     *
     * @param array $columns
     * @param mixed $arg
     * @param bool $allowAliasPair
     * @return void
     */
    protected function normalizeSelectColumns(array &$columns, $arg, bool $allowAliasPair): void
    {
        if (is_array($arg)) {
            if (empty($arg)) {
                return;
            }

            $isList = array_keys($arg) === range(0, count($arg) - 1);

            // If this is a direct alias pair or subquery pair, keep it intact
            if ($allowAliasPair && $isList && count($arg) === 2) {
                $columns[] = $arg;
                return;
            }

            // If this is a subquery pair passed directly (e.g. [$subquery, 'alias'])
            if ($isList && count($arg) === 2 && is_object($arg[0]) && method_exists($arg[0], 'toSql')) {
                $columns[] = $arg;
                return;
            }

            if ($isList) {
                foreach ($arg as $item) {
                    $this->normalizeSelectColumns($columns, $item, true);
                }
                return;
            }

            // Treat associative arrays as column => alias mapping
            foreach ($arg as $col => $alias) {
                $columns[] = [$col, $alias];
            }
            return;
        }

        $columns[] = $arg;
    }

    /**
     * Merge subquery bindings into parent with unique names to avoid conflicts
     *
     * @param QueryBuilder $subquery The subquery to merge bindings from
     * @param string $sql The SQL string to update placeholders in
     * @return string Updated SQL with renamed placeholders
     */
    protected function mergeSubqueryBindings(QueryBuilder $subquery, string $sql): string
    {
        // Collect all rename mappings first, then apply with strtr to avoid prefix collision
        $renameMap = [];

        // Merge namedBindings with unique keys
        foreach ($subquery->namedBindings as $oldKey => $value) {
            // Generate new unique key using parent's counter
            $newKey = $oldKey.'_'.$this->paramCounter++;
            $oldToken = (is_string($oldKey) && strpos($oldKey, ':') === 0) ? $oldKey : ':'.$oldKey;
            $renameMap[$oldToken] = ':'.$newKey;
            // Add to parent bindings
            $this->namedBindings[$newKey] = $value;
        }

        // Merge embeddedBindings with unique keys
        foreach ($subquery->embeddedBindings as $oldKey => $value) {
            // Generate new unique key using parent's counter
            $newKey = $oldKey.'_'.$this->paramCounter++;
            $oldToken = (is_string($oldKey) && strpos($oldKey, ':') === 0) ? $oldKey : ':'.$oldKey;
            $renameMap[$oldToken] = ':'.$newKey;
            // Add to parent bindings
            $this->embeddedBindings[$newKey] = $value;
        }

        // Apply all renames at once using strtr (longest match first, no prefix collision)
        if (!empty($renameMap)) {
            $sql = strtr($sql, $renameMap);
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function selectRaw(string $expression, array $bindings = []): QueryBuilderInterface
    {
        $this->type = 'SELECT';
        $this->columns[] = $expression;
        $this->addBindings($bindings);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function selectCount(string $column = '*'): QueryBuilderInterface
    {
        $this->type = 'SELECT';
        $this->columns = ["COUNT($column) as count"];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function insert(string $table): QueryBuilderInterface
    {
        // Create a dedicated InsertBuilder and copy relevant state
        $builder = new InsertBuilder($this->connection);
        $builder->type = 'INSERT';
        $builder->table = \Kotchasan\Database::create()->getTableName($table);

        // copy shared state
        $builder->wheres = $this->wheres;
        $builder->joins = $this->joins;
        $builder->orders = $this->orders;
        $builder->groups = $this->groups;
        $builder->havings = $this->havings;
        $builder->limit = $this->limit;
        $builder->offset = $this->offset;
        $builder->values = $this->values;
        $builder->namedBindings = $this->namedBindings;
        $builder->embeddedBindings = $this->embeddedBindings;
        $builder->paramCounter = $this->paramCounter;
        $builder->useNamedParameters = $this->useNamedParameters;
        $builder->placeholderPrefix = $this->placeholderPrefix;

        return $builder;
    }

    /**
     * {@inheritdoc}
     */
    public function update(string $table): QueryBuilderInterface
    {
        // Create a dedicated UpdateBuilder and copy relevant state
        $builder = new UpdateBuilder($this->connection);
        $builder->type = 'UPDATE';
        $builder->table = \Kotchasan\Database::create()->getTableName($table);

        // copy shared state
        $builder->wheres = $this->wheres;
        $builder->joins = $this->joins;
        $builder->orders = $this->orders;
        $builder->groups = $this->groups;
        $builder->havings = $this->havings;
        $builder->limit = $this->limit;
        $builder->offset = $this->offset;
        $builder->values = $this->values;
        $builder->namedBindings = $this->namedBindings;
        $builder->embeddedBindings = $this->embeddedBindings;
        $builder->paramCounter = $this->paramCounter;
        $builder->useNamedParameters = $this->useNamedParameters;
        $builder->placeholderPrefix = $this->placeholderPrefix;

        return $builder;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $table): QueryBuilderInterface
    {
        // Create a dedicated DeleteBuilder and copy relevant state
        $builder = new DeleteBuilder($this->connection);
        $builder->type = 'DELETE';
        $builder->table = \Kotchasan\Database::create()->getTableName($table);

        // copy shared state
        $builder->wheres = $this->wheres;
        $builder->joins = $this->joins;
        $builder->orders = $this->orders;
        $builder->groups = $this->groups;
        $builder->havings = $this->havings;
        $builder->limit = $this->limit;
        $builder->offset = $this->offset;
        $builder->values = $this->values;
        $builder->namedBindings = $this->namedBindings;
        $builder->embeddedBindings = $this->embeddedBindings;
        $builder->paramCounter = $this->paramCounter;
        $builder->useNamedParameters = $this->useNamedParameters;
        $builder->placeholderPrefix = $this->placeholderPrefix;

        return $builder;
    }

    /**
     * {@inheritdoc}
     */
    public function from($table, ?string $alias = null): QueryBuilderInterface
    {
        if (is_array($table) && $table[0] instanceof \Kotchasan\QueryBuilder\QueryBuilder) {
            // subquery
            $subquery = $table[0];
            $subquerySql = '('.$subquery->toSql().')';
            // Rename subquery bindings to avoid conflicts
            $subquerySql = $this->mergeSubqueryBindings($subquery, $subquerySql);
            $this->table = $subquerySql;
            $this->alias = $table[1] ?? null;
        } elseif (preg_match('/^([a-z0-9A-Z_]+)(\s+(as|AS))?\s+([a-zA-Z0-9]{1,})$/', $table, $match)) {
            // case 'category AS C' or 'category C'
            $this->table = \Kotchasan\Database::create()->getTableName($match[1]);
            $this->alias = $match[4];
        } else {
            // Normal case 'category'
            $this->table = \Kotchasan\Database::create()->getTableName($table);
            $this->alias = $alias;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function where($where, string $condition = 'AND'): QueryBuilderInterface
    {
        // Ignore empty parameters completely.
        if ($where === null) {
            return $this;
        }

        // Handle closure nested
        if ($where instanceof \Closure) {
            return $this->whereNested($where);
        }

        // If an empty array is passed, treat as a no-op (do not add any where)
        if (is_array($where) && empty($where)) {
            return $this;
        }

        // If string provided, treat as raw where condition
        if (is_string($where)) {
            if (trim($where) === '') {
                return $this;
            }

            $this->wheres[] = [
                'type' => 'raw',
                'sql' => $where,
                'boolean' => $condition
            ];

            return $this;
        }

        // Support raw-expression objects such as \Kotchasan\Database\Sql::create(...)
        if (is_object($where) && method_exists($where, 'toSql')) {
            return $this->whereRaw($where, $condition);
        }

        // Ensure array for structured where
        if (!is_array($where)) {
            throw new \InvalidArgumentException('where() accepts string, array, Closure or raw-expression objects only.');
        }

        // If this is an associative array of multiple conditions, convert to nested group
        $isAssoc = array_keys($where) !== range(0, count($where) - 1);
        if ($isAssoc) {
            // treat as single condition map: key => value
            return $this->whereNested(function ($q) use ($where, $condition) {
                $first = true;
                foreach ($where as $k => $v) {
                    if ($first) {
                        $q->where([$k, $v]);
                        $first = false;
                    } else {
                        if (strtoupper($condition) === 'OR') {
                            $q->orWhere([$k, $v]);
                        } else {
                            $q->where([$k, $v]);
                        }
                    }
                }
            });
        }

        // If array of conditions (numeric array of arrays) e.g. [['col','v'], ['col2','v2']]
        if (isset($where[0]) && is_array($where[0])) {
            return $this->whereNested(function ($q) use ($where, $condition) {
                $first = true;
                foreach ($where as $cond) {
                    if ($first) {
                        $q->where($cond);
                        $first = false;
                    } else {
                        if (strtoupper($condition) === 'OR') {
                            $q->orWhere($cond);
                        } else {
                            $q->where($cond);
                        }
                    }
                }
            });
        }

        // Now handle single condition array
        // Normalize to [col, op, value]
        if (count($where) === 2) {
            [$col, $val] = $where;
            $op = '=';
        } elseif (count($where) === 3) {
            [$col, $op, $val] = $where;
        } else {
            throw new \InvalidArgumentException('Invalid where() array format.');
        }

        // Process column using unified approach - support both strings and SqlFunction objects
        $processedCol = $this->processColumnReference($col);

        // Handle NULL values - ['col', null] or ['col', '=', null] -> IS NULL
        //                       ['col', '!=', null] or ['col', '<>', null] -> IS NOT NULL
        if ($val === null) {
            $nullOperator = ($op === '!=' || $op === '<>') ? 'IS NOT NULL' : 'IS NULL';
            $this->wheres[] = [
                'type' => 'raw',
                'sql' => $processedCol.' '.$nullOperator,
                'boolean' => 'AND'
            ];
            return $this;
        }

        // Handle array values (IN clause)
        if (is_array($val)) {
            if (empty($val)) {
                // Empty array - add impossible condition
                $this->wheres[] = [
                    'type' => 'raw',
                    'sql' => '1 = 0',
                    'boolean' => 'AND'
                ];
            } else {
                // Create IN clause
                $placeholders = [];
                foreach ($val as $item) {
                    // use unique qb prefix
                    $paramName = $this->placeholderPrefix.$this->paramCounter++;
                    $placeholders[] = $paramName;
                    $k = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                    $this->namedBindings[$k] = $item;
                    $this->useNamedParameters = true;
                }

                $inOperator = ($op === '!=' || $op === '<>') ? 'NOT IN' : 'IN';
                $this->wheres[] = [
                    'type' => 'raw',
                    'sql' => $processedCol.' '.$inOperator.' ('.implode(', ', $placeholders).')',
                    'boolean' => 'AND'
                ];
            }
            return $this;
        }

        // Decide if the passed value is a user-provided named placeholder (eg. ':id')
        // Accept only strictly-formed named placeholders (no spaces, only alnum and _).
        // Treat colon-prefixed strings containing whitespace as literal values (eg. ':one tow rhree').
        $paramName = null;
        if (is_string($val) && preg_match('/^:[A-Za-z0-9_]+$/', $val)) {
            // user provided a named placeholder like ':id'
            $this->useNamedParameters = true;
            $paramName = $val;
        } elseif (is_string($val) && $val === '?') {
            // user provided positional placeholder
            $paramName = '?';
        }

        // If value is a QueryBuilder/Sql-like object, treat as subquery/expression
        if (is_object($val) && method_exists($val, 'toSql')) {
            // Check if this is a simple Sql::column() used as value (column-to-column comparison)
            $isSimpleColumn = ($val instanceof \Kotchasan\QueryBuilder\SqlFunction) &&
            !method_exists($val, 'getValues') &&
            !property_exists($val, 'namedBindings') &&
            !property_exists($val, 'embeddedBindings') &&
            !method_exists($val, 'getBindings');

            if ($isSimpleColumn) {
                // Simple column reference for column-to-column comparison
                $processedVal = $this->processColumnReference($val);
                $this->wheres[] = [
                    'type' => 'raw',
                    'sql' => $processedCol.' '.$op.' '.$processedVal,
                    'boolean' => 'AND'
                ];
                return $this;
            }

            // For complex subqueries and expressions, handle parameter bindings
            // Extract subquery SQL and its bindings
            $subSql = $val->toSql();

            // First, handle any named placeholders in the subquery (Sql objects) to avoid collisions
            // Sql objects expose getValues(), QueryBuilder may have namedBindings property
            $handled = false;
            if (method_exists($val, 'getValues')) {
                $subVals = $val->getValues([]);
                if (!empty($subVals)) {
                    foreach ($subVals as $k => $v) {
                        if (is_int($k)) {
                            // positional binding -> replace first ? with unique named placeholder
                            $paramName = $this->placeholderPrefix.$this->paramCounter++;
                            $subSql = preg_replace('/\?/', $paramName, $subSql, 1);
                            $k = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                            $this->namedBindings[$k] = $v;
                        } else {
                            // named binding key (may or may not start with ':')
                            $origName = (strpos($k, ':') === 0) ? $k : ':'.$k;
                            $newName = $this->placeholderPrefix.$this->paramCounter++;
                            // replace token boundaries
                            $subSql = preg_replace('/'.preg_quote($origName, '/').'(?![A-Za-z0-9_])/', $newName, $subSql);
                            $k = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                            $this->namedBindings[$k] = $v;
                        }
                    }
                    $this->useNamedParameters = true;
                    $handled = true;
                }
            }

            // Also handle QueryBuilder-like namedBindings and embeddedBindings via getter methods
            if (!$handled) {
                // Collect all rename mappings first, then apply with strtr to avoid prefix collision
                $renameMap = [];

                // Try getNamedBindings() method first (preferred for QueryBuilder objects)
                if (method_exists($val, 'getNamedBindings')) {
                    $subNamed = $val->getNamedBindings();
                    if (!empty($subNamed)) {
                        foreach ($subNamed as $origName => $origVal) {
                            $origPlaceholder = ':'.$origName;
                            $newName = $this->placeholderPrefix.$this->paramCounter++;
                            $renameMap[$origPlaceholder] = $newName;
                            $k = ltrim($newName, ':');
                            $this->namedBindings[$k] = $origVal;
                        }
                        $this->useNamedParameters = true;
                        $handled = true;
                    }
                }
                // Fallback to property access (for non-QueryBuilder objects)
                elseif (property_exists($val, 'namedBindings')) {
                    $subNamed = $val->namedBindings;
                    if (!empty($subNamed)) {
                        foreach ($subNamed as $origName => $origVal) {
                            $origToken = (is_string($origName) && strpos($origName, ':') === 0) ? $origName : ':'.$origName;
                            $newName = $this->placeholderPrefix.$this->paramCounter++;
                            $renameMap[$origToken] = $newName;
                            $k = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                            $this->namedBindings[$k] = $origVal;
                        }
                        $this->useNamedParameters = true;
                        $handled = true;
                    }
                }

                // Also handle embedded bindings
                if (method_exists($val, 'getEmbeddedBindings')) {
                    $subEmbedded = $val->getEmbeddedBindings();
                    if (!empty($subEmbedded)) {
                        foreach ($subEmbedded as $origName => $origVal) {
                            $origPlaceholder = ':'.$origName;
                            $newName = $this->placeholderPrefix.$this->paramCounter++;
                            $renameMap[$origPlaceholder] = $newName;
                            $k = ltrim($newName, ':');
                            $this->namedBindings[$k] = $origVal;
                        }
                        $this->useNamedParameters = true;
                        $handled = true;
                    }
                } elseif (property_exists($val, 'embeddedBindings')) {
                    $subEmbedded = $val->embeddedBindings;
                    if (!empty($subEmbedded)) {
                        foreach ($subEmbedded as $origName => $origVal) {
                            $origToken = (is_string($origName) && strpos($origName, ':') === 0) ? $origName : ':'.$origName;
                            $newName = $this->placeholderPrefix.$this->paramCounter++;
                            $renameMap[$origToken] = $newName;
                            $k = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                            $this->namedBindings[$k] = $origVal;
                        }
                        $this->useNamedParameters = true;
                        $handled = true;
                    }
                }

                // Apply all renames at once using strtr (longest match first, no prefix collision)
                if (!empty($renameMap)) {
                    $subSql = strtr($subSql, $renameMap);
                }
            }

            // Only handle positional bindings via getBindings() if NOT already handled via named bindings
            // This prevents double-processing of the same bindings
            if (!$handled && method_exists($val, 'getBindings')) {
                $subBindings = $val->getBindings();
                if (!empty($subBindings)) {
                    foreach ($subBindings as $bindVal) {
                        $paramName = $this->placeholderPrefix.$this->paramCounter++;
                        // replace first occurrence of ? with named param
                        $subSql = preg_replace('/\?/', $paramName, $subSql, 1);
                        $k = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                        $this->namedBindings[$k] = $bindVal;
                    }
                    $this->useNamedParameters = true;
                }
            }

            $this->wheres[] = [
                'type' => 'raw',
                'sql' => $processedCol.' '.$op.' ('.$subSql.')',
                'boolean' => 'AND'
            ];

            return $this;
        }

        // For scalar values, allocate a generated named parameter only when using named
        // parameters and the paramName has not already been set (i.e. user did not supply
        // a named or positional placeholder). This preserves user-supplied placeholders
        // like ':status' and avoids remapping collisions.
        if ($this->useNamedParameters && $paramName === null) {
            $paramName = $this->placeholderPrefix.$this->paramCounter++;
            $k = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
            $this->namedBindings[$k] = $val;
        }

        // If the value looks like a colon-prefixed string but contains whitespace
        // (eg. ':one tow rhree'), force it to be treated as a literal value by
        // allocating a generated placeholder or positional binding here.
        if (is_string($val) && strpos($val, ':') === 0 && preg_match('/\s/', $val)) {
            if ($this->useNamedParameters) {
                $paramName = $this->placeholderPrefix.$this->paramCounter++;
                $k = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                $this->namedBindings[$k] = $val;
            } else {
                $paramName = '?';
                $this->addBinding($val, 'where');
            }
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $processedCol,
            'operator' => $op,
            'value' => $val,
            'boolean' => 'AND',
            'param_name' => $paramName
        ];

        // Add to positional bindings if not using named parameters
        if (!$this->useNamedParameters) {
            $this->addBinding($val, 'where');
        }

        return $this;
    }

    /**
     * Add a raw WHERE clause with optional bindings.
     *
     * @param string|object $sql Raw SQL string or object exposing toSql()/getValues()
     * @param string $condition AND/OR
     * @param array $bindings Optional positional or named bindings
     * @return QueryBuilderInterface
     */
    public function whereRaw($sql, string $condition = 'AND', array $bindings = []): QueryBuilderInterface
    {
        // Support Sql/RawExpression-like objects
        if (is_object($sql) && method_exists($sql, 'toSql')) {
            $bindings = array_merge($bindings, method_exists($sql, 'getValues') ? $sql->getValues([]) : []);
            $sql = $sql->toSql();
        }

        $this->wheres[] = [
            'type' => 'raw',
            'sql' => (string) $sql,
            'boolean' => $condition
        ];

        if (!empty($bindings)) {
            foreach ($bindings as $k => $v) {
                if (is_string($k)) {
                    $name = $k[0] === ':' ? $k : ':'.$k;
                    $this->namedBindings[$name] = $v;
                    $this->useNamedParameters = true;
                } else {
                    $this->addBinding($v, 'where');
                }
            }
        }

        return $this;
    }

    /**
     * Add a raw OR WHERE clause with optional bindings.
     *
     * @param string|object $sql Raw SQL string or object exposing toSql()/getValues()
     * @param array $bindings Optional positional or named bindings
     * @return QueryBuilderInterface
     */
    public function orWhereRaw($sql, array $bindings = []): QueryBuilderInterface
    {
        return $this->whereRaw($sql, 'OR', $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function orWhere($where, $condition = 'OR'): QueryBuilderInterface
    {
        if ($where instanceof \Closure) {
            return $this->orWhereNested($where);
        }

        if (is_string($where)) {
            $this->wheres[] = [
                'type' => 'raw',
                'sql' => $where,
                'boolean' => 'OR'
            ];

            return $this;
        }

        // reuse where logic but set boolean to OR for the last added clause
        $beforeCount = count($this->wheres);
        $this->where($where, $condition);
        $afterCount = count($this->wheres);
        if ($afterCount > $beforeCount) {
            $this->wheres[$afterCount - 1]['boolean'] = 'OR';
        }

        return $this;
    }

    /**
     * Adds a nested WHERE clause to the query.
     *
     * @param \Closure $callback The callback function that builds the nested condition.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function whereNested(\Closure $callback): QueryBuilderInterface
    {
        $query = new static($this->connection);
        $callback($query);

        if (count($query->wheres) > 0) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => 'AND'
            ];
        }

        return $this;
    }

    /**
     * Remap placeholder names inside a wheres array (in-place).
     * This updates 'param_name' entries and any occurrences inside raw SQL strings.
     * If nested groups exist, it recurses.
     *
     * @param array $wheres
     * @param array $mapping oldName => newName
     * @return void
     */
    protected function remapPlaceholdersInWheres(array &$wheres, array $mapping): void
    {
        if (empty($mapping)) {
            return;
        }
        foreach ($wheres as &$w) {
            if (!empty($w['param_name']) && is_string($w['param_name'])) {
                $pn = $w['param_name'];
                if (isset($mapping[$pn])) {
                    $w['param_name'] = $mapping[$pn];
                }
            }
            if ($w['type'] === 'raw' && !empty($w['sql'])) {
                foreach ($mapping as $old => $new) {
                    // replace only token occurrences of the old placeholder
                    $w['sql'] = preg_replace('/'.preg_quote($old, '/').'(?![A-Za-z0-9_])/', $new, $w['sql']);
                }
            }
            if ($w['type'] === 'nested' && isset($w['query']) && is_object($w['query'])) {
                // nested query: remap in its wheres recursively
                $this->remapPlaceholdersInWheres($w['query']->wheres, $mapping);
            }
        }
        unset($w);
    }

    /**
     * Adds a nested OR WHERE clause to the query.
     *
     * @param \Closure $callback The callback function that builds the nested condition.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhereNested(\Closure $callback): QueryBuilderInterface
    {
        $query = new static($this->connection);
        $callback($query);

        if (count($query->wheres) > 0) {
            // Store the nested query object; bindings will be remapped when building SQL
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => 'OR'
            ];
        }

        return $this;
    }

    /**
     * Adds a WHERE EXISTS clause to the query.
     *
     * Usage similar to join():
     *  ->whereExists('product_details D', [['D.product_id', 'P.id']])
     *  ->whereExists('product_details D', [['D.product_id', 'P.id'], ['D.status', 1]])
     *
     * With additional joins inside EXISTS:
     *  ->whereExists(['product_details D', 'product_select S'], [
     *      ['D.product_id', 'P.id'],
     *      ['S.select_id', 'D.category_id'],
     *      ['S.type', 'category_id']
     *  ])
     *
     * @param string|array $table Table name(s) with optional alias. Array for multiple tables.
     * @param array $condition The conditions (similar to join condition format).
     * @param string $boolean The boolean connector (AND/OR).
     * @param bool $not Whether to use NOT EXISTS instead of EXISTS.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function whereExists($table, array $condition = [], string $boolean = 'AND', bool $not = false): QueryBuilderInterface
    {
        if (!is_array($table)) {
            $table = [$table];
        }
        foreach ($table as $idx => $tbl) {
            $table[$idx] = $this->processTableNameForJoin($tbl);
        }

        $this->wheres[] = [
            'type' => 'exists',
            'table' => $table,
            'condition' => $condition,
            'boolean' => $boolean,
            'not' => $not
        ];

        return $this;
    }

    /**
     * Adds a WHERE NOT EXISTS clause to the query.
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions.
     * @param string $boolean The boolean connector (AND/OR).
     * @return QueryBuilderInterface The query builder instance.
     */
    public function whereNotExists($table, array $condition = [], string $boolean = 'AND'): QueryBuilderInterface
    {
        return $this->whereExists($table, $condition, $boolean, true);
    }

    /**
     * Adds an OR WHERE EXISTS clause to the query.
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhereExists($table, array $condition = []): QueryBuilderInterface
    {
        return $this->whereExists($table, $condition, 'OR', false);
    }

    /**
     * Adds an OR WHERE NOT EXISTS clause to the query.
     *
     * @param string|array $table Table name(s) with optional alias.
     * @param array $condition The conditions.
     * @return QueryBuilderInterface The query builder instance.
     */
    public function orWhereNotExists($table, array $condition = []): QueryBuilderInterface
    {
        return $this->whereExists($table, $condition, 'OR', true);
    }

    /**
     * {@inheritdoc}
     */
    public function join($table, $condition, string $type = 'INNER'): QueryBuilderInterface
    {
        if (is_array($table) && $table[0] instanceof \Kotchasan\QueryBuilder\QueryBuilder) {
            // Support subquery in JOIN
            $subquery = $table[0];
            $alias = $table[1] ?? null;
            $subquerySql = '('.$subquery->toSql().')';
            // Rename subquery bindings to avoid conflicts
            $subquerySql = $this->mergeSubqueryBindings($subquery, $subquerySql);
            $processedTable = $subquerySql.($alias ? ' AS '.$this->quoteIdentifier($alias) : '');
        } else {
            // Automatically process table name in JOIN
            $processedTable = $this->processTableNameForJoin($table);
        }

        // Support condition as array, string, or Closure
        if ($condition instanceof \Closure) {
            // Build a temporary QueryBuilder to capture nested conditions, then extract its wheres
            $temp = new static($this->connection);
            $condition($temp);
            // If no where was added, skip adding this join
            if (empty($temp->wheres)) {
                return $this;
            }
            // Convert temp wheres to a single condition string by reusing processJoinCondition on an array
            $processedCondition = $this->processJoinCondition($temp->wheres);
        } else {
            $processedCondition = $this->processJoinCondition($condition);
        }

        $this->joins[] = [
            'table' => $processedTable,
            'condition' => $processedCondition,
            'type' => $type
        ];

        return $this;
    }

    /**
     * Process condition for JOIN or WHERE - shared logic with different binding behavior
     *
     * @param array|string|\Closure $condition The condition
     * @param bool $createBindings Whether to create parameter bindings (true for WHERE, false for JOIN)
     * @return string|array The processed condition (string for JOIN, array for WHERE internal use)
     */
    protected function processCondition($condition, bool $createBindings = true)
    {
        // Handle Closure by building temporary query and extracting conditions
        if ($condition instanceof \Closure) {
            $temp = new static($this->connection);
            $condition($temp);
            if (empty($temp->wheres)) {
                return $createBindings ? [] : '';
            }

            // Convert temp wheres into normalized format
            $conditions = [];
            foreach ($temp->wheres as $w) {
                if ($w['type'] === 'basic') {
                    $conditions[] = [$w['column'], $w['operator'], $w['value']];
                } elseif ($w['type'] === 'raw') {
                    $conditions[] = $w['sql'];
                }
            }
            $condition = $conditions;
        }

        // Handle string condition (raw SQL)
        if (is_string($condition)) {
            return $condition;
        }

        // Handle array conditions
        if (is_array($condition)) {
            if (empty($condition)) {
                return $createBindings ? [] : '';
            }

            // Simple 2-element array: ['u.id', 'p.user_id'] -> u.id = p.user_id
            if (count($condition) === 2 && !is_array($condition[0])) {
                if ($createBindings) {
                    // For WHERE: return structured format for further processing
                    return [[$condition[0], '=', $condition[1]]];
                } else {
                    // For JOIN: return direct SQL string
                    return $this->formatConditionClause($condition[0], '=', $condition[1], false);
                }
            }

            // Complex array format: multiple conditions
            $results = [];
            foreach ($condition as $cond) {
                if (is_string($cond)) {
                    // Raw SQL condition
                    $results[] = $cond;
                } elseif (is_array($cond)) {
                    if (count($cond) === 2) {
                        // ['column', 'value'] -> column = value
                        if ($createBindings) {
                            $results[] = [$cond[0], '=', $cond[1]];
                        } else {
                            $results[] = $this->formatConditionClause($cond[0], '=', $cond[1], false);
                        }
                    } elseif (count($cond) === 3) {
                        // ['column', 'operator', 'value']
                        if ($createBindings) {
                            $results[] = $cond;
                        } else {
                            $results[] = $this->formatConditionClause($cond[0], $cond[1], $cond[2], false);
                        }
                    } else {
                        throw new \InvalidArgumentException('Condition array element must have 2 or 3 elements');
                    }
                } else {
                    throw new \InvalidArgumentException('Invalid condition format');
                }
            }

            return $createBindings ? $results : implode(' AND ', $results);
        }

        throw new \InvalidArgumentException('Condition must be array, string, or Closure');
    }

    /**
     * Format a single condition clause (column operator value)
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param bool $createBinding Whether to create parameter binding
     * @return string
     */
    protected function formatConditionClause(string $column, string $operator, $value, bool $createBinding = true): string
    {
        // Use unified column processing
        $quotedColumn = $this->processColumnReference($column);

        // Handle NULL values - always emit IS NULL / IS NOT NULL
        if ($value === null) {
            $nullOperator = ($operator === '!=' || $operator === '<>') ? 'IS NOT NULL' : 'IS NULL';
            return $quotedColumn.' '.$nullOperator;
        }

        // Handle objects with toSql() method (includes SqlFunction)
        if (is_object($value) && method_exists($value, 'toSql')) {
            return $quotedColumn.' '.$operator.' '.$this->extractExpressionSql($value);
        }

        // For JOIN conditions (no binding), determine if value is column or literal
        if (!$createBinding) {
            // Handle array values: convert = to IN (...)
            if (is_array($value)) {
                $quotedValues = array_map(function ($v) {
                    if (is_string($v) && $this->looksLikeColumnReference($v)) {
                        return $this->quoteIdentifier($v);
                    }
                    return $this->quoteValue($v);
                }, $value);
                $inOperator = strtoupper($operator) === '!=' || strtoupper($operator) === '<>' ? 'NOT IN' : 'IN';
                return $quotedColumn.' '.$inOperator.' ('.implode(', ', $quotedValues).')';
            }
            if (is_string($value) && $this->looksLikeColumnReference($value)) {
                // Value looks like a column reference, quote as identifier
                $quotedValue = $this->quoteIdentifier($value);
            } else {
                // Value is a literal, quote as string
                $quotedValue = $this->quoteValue($value);
            }
            return $quotedColumn.' '.$operator.' '.$quotedValue;
        }

        // For WHERE conditions, this should be handled by the where() method's binding logic
        // This method shouldn't be called directly for WHERE with createBinding=true
        throw new \InvalidArgumentException('Use where() method for conditions that require parameter binding');
    }

    /**
     * Check if a string looks like a column reference
     *
     * @param string $value
     * @return bool
     */
    protected function looksLikeColumnReference(string $value): bool
    {
        // Check for patterns that indicate a column reference:
        // - Contains dot (table.column) - this is a strong indicator
        // - Starts with backtick (already quoted identifier)
        // - Contains function call patterns with dot notation

        // Strong indicators of column references:
        if (strpos($value, '.') !== false) {
            // Must be table.column format
            return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $value);
        }

        // Already quoted identifiers
        if (strpos($value, '`') !== false) {
            return true;
        }

        // Single word identifiers are ambiguous - default to literal for safety
        // This prevents treating strings like 'department', 'status' as columns
        // Use Sql::column() explicitly for single column references in JOIN
        return false;
    }

    /**
     * Process JOIN condition - now uses shared condition processor
     *
     * @param array|string|\Closure $condition The join condition
     * @return string The processed condition string
     */
    protected function processJoinCondition($condition): string
    {
        return $this->processCondition($condition, false);
    }

    /**
     * Quote value for SQL (simple implementation)
     * Only objects (like SqlFunction) are treated as column references.
     * All string values are quoted as literals for security.
     */
    protected function quoteValue($value): string
    {
        if (is_string($value)) {
            // ALL strings are quoted as literals - no exceptions
            // This prevents SQL injection and ensures predictable behavior
            return "'".str_replace("'", "''", $value)."'";
        } elseif (is_numeric($value)) {
            return (string) $value; // Numeric, no quotes
        } elseif (is_bool($value)) {
            return $value ? '1' : '0'; // Boolean to 1/0
        } elseif ($value === null) {
            return 'NULL';
        } else {
            // If it's an object that can provide SQL, extract it
            if (is_object($value) && method_exists($value, 'toSql')) {
                return $this->extractExpressionSql($value);
            }
            return "'".str_replace("'", "''", (string) $value)."'";
        }
    }

    /**
     * Extract SQL string from expression-like objects and merge their bindings into embeddedBindings.
     * Supports Kotchasan\Database\Sql, RawExpression, QueryBuilder, or objects exposing toSql()/getValues().
     *
     * @param object $expr
     * @return string
     */
    protected function extractExpressionSql($expr): string
    {
        // If it's a SqlFunction, use format() with connection for proper database-specific generation
        if ($expr instanceof \Kotchasan\QueryBuilder\SqlFunction) {
            return $expr->format($this->connection);
        }

        // Get fragment
        if (method_exists($expr, 'toSql')) {
            $frag = $expr->toSql();
        } else {
            return (string) $expr;
        }

        // Merge any positional or named values into embeddedBindings and remap placeholders
        // Handle getValues() if available
        if (method_exists($expr, 'getValues')) {
            $vals = $expr->getValues([]);
            if (!empty($vals)) {
                foreach ($vals as $k => $v) {
                    if (is_int($k)) {
                        // replace first ? with new param
                        $param = $this->placeholderPrefix.$this->paramCounter++;
                        $frag = preg_replace('/\?/', $param, $frag, 1);
                        $p = is_string($param) && strpos($param, ':') === 0 ? substr($param, 1) : $param;
                        $this->embeddedBindings[$p] = $v;
                    } else {
                        $origName = (strpos($k, ':') === 0) ? $k : ':'.$k;
                        $newName = $this->placeholderPrefix.$this->paramCounter++;
                        $frag = preg_replace('/'.preg_quote($origName, '/').'(?![A-Za-z0-9_])/', $newName, $frag);
                        $p = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                        $this->embeddedBindings[$p] = $v;
                    }
                }
            }
        }

        // Also handle QueryBuilder-like namedBindings/embeddedBindings if present
        try {
            $ref = new \ReflectionObject($expr);
            $renameMap = [];

            if ($ref->hasProperty('namedBindings')) {
                $p = $ref->getProperty('namedBindings');
                $p->setAccessible(true);
                $nb = $p->getValue($expr);
                if (!empty($nb)) {
                    foreach ($nb as $orig => $val) {
                        $new = $this->placeholderPrefix.$this->paramCounter++;
                        $origToken = (is_string($orig) && strpos($orig, ':') === 0) ? $orig : ':'.$orig;
                        $renameMap[$origToken] = $new;
                        $p2 = is_string($new) && strpos($new, ':') === 0 ? substr($new, 1) : $new;
                        $this->embeddedBindings[$p2] = $val;
                    }
                }
            }
            if ($ref->hasProperty('embeddedBindings')) {
                $p3 = $ref->getProperty('embeddedBindings');
                $p3->setAccessible(true);
                $eb = $p3->getValue($expr);
                if (!empty($eb)) {
                    foreach ($eb as $orig => $val) {
                        $new = $this->placeholderPrefix.$this->paramCounter++;
                        $origToken = (is_string($orig) && strpos($orig, ':') === 0) ? $orig : ':'.$orig;
                        $renameMap[$origToken] = $new;
                        $p4 = is_string($new) && strpos($new, ':') === 0 ? substr($new, 1) : $new;
                        $this->embeddedBindings[$p4] = $val;
                    }
                }
            }

            // Apply all renames at once using strtr (avoids prefix collision)
            if (!empty($renameMap)) {
                $frag = strtr($frag, $renameMap);
            }
        } catch (\ReflectionException $e) {
            // ignore
        }

        // Finally, if the expression exposes getBindings(), remap positional bindings
        if (method_exists($expr, 'getBindings')) {
            $b = $expr->getBindings();
            if (!empty($b)) {
                foreach ($b as $bv) {
                    $param = $this->placeholderPrefix.$this->paramCounter++;
                    $frag = preg_replace('/\?/', $param, $frag, 1);
                    $p = is_string($param) && strpos($param, ':') === 0 ? substr($param, 1) : $param;
                    $this->embeddedBindings[$p] = $bv;
                }
            }
        }

        if ($expr instanceof \Kotchasan\QueryBuilder\QueryBuilderInterface) {
            return '('.$frag.')';
        }

        return $frag;
    }

    /**
     * Process table name for JOIN clause
     *
     * @param string $table The table name with optional alias
     * @return string The processed table name with prefix
     */
    protected function processTableNameForJoin(string $table): string
    {
        $table = trim($table);

        if ($table !== '' && $table[0] === '(') {
            if (preg_match('/^(\(.*\))(\s+(?:as|AS)\s+|\s+)([a-zA-Z0-9_]{1,})$/s', $table, $match)) {
                return $match[1].' AS '.$this->quoteIdentifier($match[3]);
            }

            return $table;
        }

        // Separate table names and alias
        if (preg_match('/^([a-z0-9A-Z_]+)(\s+(as|AS))?\s+([a-zA-Z0-9]{1,})$/', $table, $match)) {
            // In the case of 'Category As C' or 'Category C'
            $tableName = \Kotchasan\Database::create()->getTableName($match[1]);
            return $this->quoteIdentifier($tableName).' AS '.$this->quoteIdentifier($match[4]);
        } else {
            // In the case of 'category' (no Alias)
            $tableName = \Kotchasan\Database::create()->getTableName($table);
            return $this->quoteIdentifier($tableName);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function leftJoin($table, $condition): QueryBuilderInterface
    {
        return $this->join($table, $condition, 'LEFT');
    }

    /**
     * {@inheritdoc}
     */
    public function rightJoin($table, $condition): QueryBuilderInterface
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    /**
     * {@inheritdoc}
     */
    public function union(QueryBuilderInterface $query, bool $all = false): QueryBuilderInterface
    {
        $this->unions[] = ['query' => $query, 'all' => $all];
        $this->lastQuery = null; // invalidate cached SQL
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function unionAll(QueryBuilderInterface $query): QueryBuilderInterface
    {
        return $this->union($query, true);
    }

    /**
     * {@inheritdoc}
     */
    public function orderBy(string $column, string $direction = 'ASC'): QueryBuilderInterface
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        $this->orders[] = [
            'column' => $column,
            'direction' => $direction
        ];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function groupBy($columns): QueryBuilderInterface
    {
        if (is_array($columns)) {
            $this->groups = array_merge($this->groups, $columns);
        } else {
            $this->groups[] = $columns;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function having($column, $operator = null, $value = null): QueryBuilderInterface
    {
        // If only two parameters are provided, assume equals operator
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND'
        ];

        // Add to bindings
        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function limit(int $limit, int $offset = 0): QueryBuilderInterface
    {
        if (!empty($limit)) {
            $this->limit = max(0, $limit);
        }

        if (!empty($offset)) {
            $this->offset = max(0, $offset);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function set(array $values): QueryBuilderInterface
    {
        foreach ($values as $key => $val) {
            $this->values[$key] = $val;
            if (!$this->useNamedParameters) {
                $this->addBinding($val, 'set');
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function values(array $values): QueryBuilderInterface
    {
        foreach ($values as $key => $val) {
            $this->values[$key] = $val;
            if (!$this->useNamedParameters) {
                $this->addBinding($val, 'values');
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(?array $params = null, ?string $resultFormat = null): ResultInterface
    {
        // Build the SQL query first so that named/embedded bindings are generated
        // by toSql() (it mutates namedBindings/embeddedBindings and paramCounter).
        // Only call toSql() if we haven't built it already to avoid regenerating
        // placeholders and incrementing paramCounter multiple times.
        if ($this->lastQuery === null) {
            $sql = $this->toSql();
            $this->lastQuery = $sql;
        } else {
            $sql = $this->lastQuery;
        }

        // Process parameters based on whether we're using named parameters
        $processedBindings = $this->processNamedParameters($params);

        // Check if we need to use caching and if a cache instance is available
        $queryCache = null;

        if ($this->useCache && $this->type === 'SELECT') {
            try {
                $queryCache = $this->connection->getQueryCache();

                if ($queryCache !== null) {
                    // Try to get from cache
                    $cachedData = $queryCache->get($this);

                    if ($cachedData !== null && is_array($cachedData)) {
                        // Return cached result wrapped in ArrayResult
                        return new \Kotchasan\Result\ArrayResult(
                            $cachedData['data'] ?? [],
                            $cachedData['format'] ?? ($resultFormat ?? 'default')
                        );
                    }
                }
            } catch (\Exception $e) {
                // If there's an error with the cache, just continue with the query
                // The error will be logged if a logger is available
                $logger = $this->connection->getLogger();
                if ($logger !== null) {
                    if ($logger instanceof \Kotchasan\Logger\QueryLoggerInterface) {
                        $logger->error('Cache error', [
                            'exception' => get_class($e),
                            'message' => $e->getMessage()
                        ]);
                    } else {
                        $logger->error('Cache error', [
                            'exception' => get_class($e),
                            'message' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        // (sql already built above)

        // Log the query if a logger is available
        $startTime = null;
        $logger = $this->connection->getLogger();

        if ($logger !== null) {
            try {
                if ($logger instanceof \Kotchasan\Logger\QueryLoggerInterface) {
                    $startTime = $logger->logQuery($sql, $this->getBindings());
                } else {
                    $logger->debug('Executing query', [
                        'query' => $sql,
                        'bindings' => $this->getBindings()
                    ]);
                    $startTime = microtime(true);
                }
            } catch (\Exception $e) {
                // If logging fails, we still want to execute the query
                // No need to do anything here
            }
        }

        // Filter processed named bindings to only those placeholders present in SQL
        if (!empty($processedBindings) && is_array($processedBindings)) {
            $placeholderNames = [];
            if (preg_match_all('/:([A-Za-z0-9_]+)/', $sql, $matches)) {
                foreach ($matches[1] as $ph) {
                    $placeholderNames[$ph] = true;
                }
            }
            if (!empty($placeholderNames)) {
                $processedBindings = array_intersect_key($processedBindings, $placeholderNames);
            }
        }

        // Prepare the statement
        $statement = $this->connection->getDriver()->prepare($sql);

        // Execute the statement with processed bindings and optional result format
        $result = $statement->execute($processedBindings, $resultFormat ?? 'default');

        // Log the result if a logger is available
        if ($logger !== null && $startTime !== null) {
            try {
                if ($logger instanceof \Kotchasan\Logger\QueryLoggerInterface) {
                    $logger->logQueryResult($sql, $this->getBindings(), $startTime, $result->count());
                } else {
                    $executionTime = microtime(true) - $startTime;
                    $timeMs = number_format($executionTime * 1000, 2);
                    $logger->debug('Query executed', [
                        'query' => $sql,
                        'bindings' => $this->getBindings(),
                        'time' => "{$timeMs}ms",
                        'rows' => $result->count()
                    ]);
                }
            } catch (\Exception $e) {
                // If logging fails, we still want to return the result
                // No need to do anything here
            }
        }

        // Cache the result if needed (only if auto-save is enabled)
        if ($this->useCache && $this->autoSaveCache && $queryCache !== null && $this->type === 'SELECT') {
            try {
                // Convert result to cacheable array format
                $cacheData = [
                    'data' => $result->fetchAll(),
                    'format' => $resultFormat ?? 'default'
                ];
                // Reset result for consumer (fetchAll moves the pointer)
                if (method_exists($result, 'reset')) {
                    $result->reset();
                }
                $queryCache->set($this, $cacheData, $this->cacheTtl);
            } catch (\Exception $e) {
                // If caching fails, we still want to return the result
                // The error will be logged if a logger is available
                if ($logger !== null) {
                    $logger->error('Cache set error', [
                        'exception' => get_class($e),
                        'message' => $e->getMessage()
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function toSql(): string
    {
        // If SQL already built, return cached value to avoid re-generating
        if ($this->lastQuery !== null) {
            return $this->lastQuery;
        }

        // This method should be implemented by specific query builder types
        throw new DatabaseException('Method toSql() must be implemented by specific query builder classes.');
    }

    /**
     * Adds a binding to the query.
     *
     * @param mixed $value The value to bind.
     * @param string $type The binding type (where, join, set, etc.).
     * @return QueryBuilder The query builder instance.
     */
    protected function addBinding($value, string $type = 'where'): self
    {
        if ($this->useNamedParameters) {
            $paramName = ':p'.$this->paramCounter++;
            $this->namedBindings[$paramName] = $value;
            return $this;
        }

        if (!array_key_exists($type, $this->bindings)) {
            $this->bindings[$type] = [];
        }

        $this->bindings[$type][] = $value;

        return $this;
    }

    /**
     * Adds multiple bindings to the query.
     *
     * @param array $values The values to bind.
     * @param string $type The binding type (where, join, set, etc.).
     * @return QueryBuilder The query builder instance.
     */
    protected function addBindings(array $values, string $type = 'where'): self
    {
        foreach ($values as $value) {
            $this->addBinding($value, $type);
        }

        return $this;
    }

    /**
     * Gets the named bindings (associative array with placeholder names as keys)
     *
     * @return array The named bindings
     */
    public function getNamedBindings(): array
    {
        return $this->namedBindings;
    }

    /**
     * Gets the embedded bindings (associative array with placeholder names as keys)
     *
     * @return array The embedded bindings
     */
    public function getEmbeddedBindings(): array
    {
        return $this->embeddedBindings;
    }

    /**
     * Gets the query bindings.
     *
     * @param string|null $type The binding type to retrieve. If null, all bindings are returned.
     * @return array The query bindings.
     */
    public function getBindings(?string $type = null): array
    {
        if ($type !== null) {
            return $this->bindings[$type] ?? [];
        }

        // Start with positional bindings grouped by type
        $result = [];
        foreach ($this->bindings as $bindings) {
            $result = array_merge($result, $bindings);
        }

        // Also merge bindings from nested where queries so that nested values
        // appear in the top-level getBindings() (tests expect this behaviour)
        if (!empty($this->wheres)) {
            foreach ($this->wheres as $w) {
                if ($w['type'] === 'nested' && isset($w['query']) && is_object($w['query'])) {
                    // Merge positional bindings from nested query
                    $nestedBindings = $w['query']->getBindings();
                    if (!empty($nestedBindings)) {
                        $result = array_merge($result, $nestedBindings);
                    }
                }
            }
        }

        // Then append named binding values to preserve expected ordering in tests
        if ($this->useNamedParameters && !empty($this->namedBindings)) {
            $result = array_merge($result, array_values($this->namedBindings));
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function cache(?int $ttl = null): QueryBuilderInterface
    {
        $this->useCache = true;
        $this->cacheTtl = $ttl;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function noCache(): QueryBuilderInterface
    {
        $this->useCache = false;

        return $this;
    }

    /**
     * Enable caching with default TTL (convenience method)
     *
     * When enabled, cache will be checked before querying data from database.
     *
     * @param bool $auto_save (optional) Whether to automatically save cache results.
     *                        - true: Read from cache first, if not found query DB and save cache automatically
     *                        - false: Read from cache first, if not found query DB but do NOT save cache automatically
     *                        Default is true.
     * @param int $ttl (optional) Cache time-to-live in seconds. Default is 3600 (1 hour).
     * @return QueryBuilderInterface
     */
    public function cacheOn(bool $auto_save = true, int $ttl = 3600): QueryBuilderInterface
    {
        // Use CACHE_TTL constant if exists
        if (defined('CACHE_TTL')) {
            $ttl = CACHE_TTL;
        }

        $this->useCache = true;
        $this->cacheTtl = $ttl;
        $this->autoSaveCache = $auto_save;

        return $this;
    }

    /**
     * Disable caching (convenience method)
     *
     * @return QueryBuilderInterface
     */
    public function cacheOff(): QueryBuilderInterface
    {
        $this->useCache = false;
        $this->cacheTtl = null;
        $this->autoSaveCache = true; // Reset to default

        return $this;
    }

    /**
     * Manually save query result to cache
     *
     * This method is used when cacheOn(false) is called to enable manual cache saving.
     * Call this method after executing a query to save the result to cache.
     *
     * @param mixed $result The query result to save to cache
     * @param array|null $params The parameter bindings used in the query
     * @return bool True if cache was saved successfully, false otherwise
     */
    public function saveCache($result, ?array $params = null): bool
    {
        try {
            $queryCache = $this->connection->getQueryCache();

            if ($queryCache === null || $this->type !== 'SELECT') {
                return false;
            }

            // Merge any additional parameters provided
            $bindings = $this->getBindings();
            if ($params !== null) {
                $bindings = array_merge($bindings, $params);
            }

            // Save to cache
            return $queryCache->set($this, $result, $this->cacheTtl);
        } catch (\Exception $e) {
            // Log error if logger is available
            $logger = $this->connection->getLogger();
            if ($logger !== null) {
                $logger->error('Manual cache save failed', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
            }
            return false;
        }
    }

    /**
     * Set result format to array (PDO::FETCH_ASSOC)
     *
     * @return QueryBuilderInterface
     */

    /**
     * Execute query and return first result
     *
     * Works exactly like execute() but returns only the first row
     *
     * @param array|null $params Optional parameters for execution (same as execute())
     * @return mixed The first result or null if not found
     */
    public function first($paramsOrToArray = null)
    {
        // Support both legacy first(?array $params) and new first(bool $toArray = false, ?array $params = null)
        $toArray = false;
        $params = null;

        if (is_bool($paramsOrToArray)) {
            $toArray = $paramsOrToArray;
        } elseif (is_array($paramsOrToArray)) {
            $params = $paramsOrToArray;
        } elseif ($paramsOrToArray === null) {
            // nothing
        } else {
            // unexpected type, treat as params
            $params = $paramsOrToArray;
        }

        // Set limit to 1 for efficiency
        $this->limit(1);

        // Execute with parameters (forward result format if requested)
        $result = $this->execute($params, $toArray ? 'array' : 'object');

        if ($result === null) {
            return null;
        }

        // Get the single row
        if (method_exists($result, 'fetch')) {
            $row = $result->fetch();
            if ($toArray && is_object($row)) {
                return (array) $row;
            }
            return $row;
        }

        // Fallback: fetch all and return first
        $rows = $this->fetchAll($toArray, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Default implementation of fetchAll. Calls execute() and returns rows according to the builder's result format.
     *
     * Backwards-compatible signature includes $toArray flag. If $toArray === true, returns associative arrays.
     *
     * @param bool $toArray
     * @param array|null $params
     * @return array
     */
    public function fetchAll(bool $toArray = false, ?array $params = null): array
    {
        $resultFormat = $toArray ? 'array' : 'object';
        $result = $this->execute($params, $resultFormat);

        if ($result === null) {
            return [];
        }

        if (method_exists($result, 'fetchAll')) {
            return $result->fetchAll() ?: [];
        }

        if (is_array($result)) {
            return $result;
        }

        if (is_iterable($result)) {
            return iterator_to_array($result);
        }

        return [];
    }

    /**
     * Process column reference with consistent logic
     * Uses toSql() interface for any object that supports it
     */
    protected function processColumnReference($column): string
    {
        // Handle any object with toSql() method (includes SqlFunction)
        if (is_object($column) && method_exists($column, 'toSql')) {
            return $this->extractExpressionSql($column);
        }

        // Handle string columns with consistent quoting rules
        if (is_string($column)) {
            // Don't quote if already quoted or contains expressions
            if (!preg_match('/[()\s\*\+\-\/]/', $column) && strpos($column, '`') === false) {
                return $this->quoteIdentifier($column);
            }
        }

        return (string) $column;
    }

    /**
     * Check if an array is associative
     *
     * @param array $array The array to check
     * @return bool True if associative, false if indexed
     */
    protected function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if an array contains named parameter keys
     *
     * @param array $array The array to check
     * @return bool True if contains named parameter keys
     */
    protected function hasNamedParameterPlaceholders(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (is_string($key) && (strpos($key, ':') === 0 || !is_numeric($key))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a value is a named parameter placeholder
     *
     * @param mixed $value The value to check
     * @return bool True if it's a named parameter placeholder
     */
    protected function isNamedParameter($value): bool
    {
        return is_string($value) && strpos($value, ':') === 0;
    }

    /**
     * Unified WHERE builder used by all concrete builders.
     * Uses named parameters only and handles RawExpression/nested queries.
     *
     * @return string
     */
    protected function buildWhereClauses(): string
    {
        $parts = [];
        $first = true;

        foreach ($this->wheres as $where) {
            if ($where['type'] === 'basic') {
                // Use unified column processing
                $colDisplay = $this->processColumnReference($where['column']);

                // Safety net: null value should always become IS NULL / IS NOT NULL
                if ($where['value'] === null) {
                    $nullOperator = ($where['operator'] === '!=' || $where['operator'] === '<>') ? 'IS NOT NULL' : 'IS NULL';
                    $expr = $colDisplay.' '.$nullOperator;
                    $wrapped = '('.$expr.')';
                    $parts[] = ['boolean' => $first ? '' : $where['boolean'], 'expr' => $wrapped];
                    $first = false;
                    continue;
                }

                if (is_object($where['value']) && $where['value'] instanceof \Kotchasan\QueryBuilder\SqlFunction) {
                    // Handle SqlFunction objects by formatting them with connection
                    $rhs = $this->extractExpressionSql($where['value']);
                    $expr = $colDisplay.' '.$where['operator'].' '.$rhs;
                } elseif (is_object($where['value']) && $where['value'] instanceof \Kotchasan\QueryBuilder\RawExpression) {
                    $expr = $colDisplay.' '.$where['operator'].' '.(string) $where['value'];
                } else {
                    // Prefer existing param_name if present
                    if (isset($where['param_name']) && $where['param_name'] !== null) {
                        $placeholder = $where['param_name'];
                    } else {
                        // Always use named parameters
                        $paramName = $this->placeholderPrefix.$this->paramCounter++;
                        $this->namedBindings[$paramName] = $where['value'];
                        $placeholder = $paramName;
                        $this->useNamedParameters = true;
                    }

                    $expr = $colDisplay.' '.$where['operator'].' '.$placeholder;
                }

                $wrapped = '('.$expr.')';
                $parts[] = ['boolean' => $first ? '' : $where['boolean'], 'expr' => $wrapped];
                $first = false;
            } elseif ($where['type'] === 'raw') {
                $wrapped = '('.$where['sql'].')';
                $parts[] = ['boolean' => empty($parts) ? '' : $where['boolean'], 'expr' => $wrapped];
                $first = false;
            } elseif ($where['type'] === 'exists') {
                // Build an EXISTS(...) subquery from table + condition (join-like syntax)
                $not = !empty($where['not']) ? 'NOT ' : '';
                $sqlBuilder = $this->getSqlBuilder();

                $table = $where['table'] ?? '';
                $condition = $where['condition'] ?? [];

                if (!empty($table)) {
                    if (is_array($table)) {
                        $tables = $table;
                        $fromTable = array_shift($tables);
                        $fromQuoted = $sqlBuilder->quoteIdentifier($fromTable);

                        $sub = 'SELECT 1 FROM '.$fromQuoted;
                        foreach ($tables as $joinTable) {
                            $sub .= ', '.$sqlBuilder->quoteIdentifier($joinTable);
                        }
                    } else {
                        $fromQuoted = $sqlBuilder->quoteIdentifier($table);
                        $sub = 'SELECT 1 FROM '.$fromQuoted;
                    }

                    if (!empty($condition)) {
                        $whereParts = [];
                        foreach ($condition as $cond) {
                            if (is_array($cond)) {
                                if (count($cond) === 2) {
                                    // ['colA', 'colB'] -> colA = colB (column-to-column) or colA = ? (literal)
                                    $left = $this->processColumnReference($cond[0]);
                                    $rightVal = $cond[1];
                                    if ($rightVal === null) {
                                        $whereParts[] = $left.' IS NULL';
                                        continue;
                                    }
                                    if (is_array($rightVal)) {
                                        if (empty($rightVal)) {
                                            $whereParts[] = '1 = 0';
                                            continue;
                                        }
                                        $placeholders = [];
                                        foreach ($rightVal as $item) {
                                            $paramName = $this->placeholderPrefix.$this->paramCounter++;
                                            $p = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                                            $this->embeddedBindings[$p] = $item;
                                            $this->useNamedParameters = true;
                                            $placeholders[] = $paramName;
                                        }
                                        $whereParts[] = $left.' IN ('.implode(', ', $placeholders).')';
                                        continue;
                                    }
                                    if (is_string($rightVal) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $rightVal)) {
                                        $right = $this->processColumnReference($rightVal);
                                    } else {
                                        $paramName = $this->placeholderPrefix.$this->paramCounter++;
                                        $p = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                                        $this->embeddedBindings[$p] = $rightVal;
                                        $this->useNamedParameters = true;
                                        $right = $paramName;
                                    }
                                    $whereParts[] = $left.' = '.$right;
                                } elseif (count($cond) === 3) {
                                    // ['col', 'op', value]
                                    $left = $this->processColumnReference($cond[0]);
                                    $operator = $cond[1];
                                    $val = $cond[2];
                                    if ($val === null) {
                                        $nullOperator = ($operator === '!=' || $operator === '<>') ? 'IS NOT NULL' : 'IS NULL';
                                        $whereParts[] = $left.' '.$nullOperator;
                                        continue;
                                    }
                                    if (is_array($val)) {
                                        if (empty($val)) {
                                            $whereParts[] = '1 = 0';
                                            continue;
                                        }
                                        $placeholders = [];
                                        foreach ($val as $item) {
                                            $paramName = $this->placeholderPrefix.$this->paramCounter++;
                                            $p = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                                            $this->embeddedBindings[$p] = $item;
                                            $this->useNamedParameters = true;
                                            $placeholders[] = $paramName;
                                        }
                                        $inOperator = (strtoupper($operator) === 'NOT IN' || $operator === '!=' || $operator === '<>') ? 'NOT IN' : 'IN';
                                        $whereParts[] = $left.' '.$inOperator.' ('.implode(', ', $placeholders).')';
                                        continue;
                                    }
                                    if (is_string($val) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $val)) {
                                        $right = $this->processColumnReference($val);
                                    } else {
                                        $paramName = $this->placeholderPrefix.$this->paramCounter++;
                                        $p = is_string($paramName) && strpos($paramName, ':') === 0 ? substr($paramName, 1) : $paramName;
                                        $this->embeddedBindings[$p] = $val;
                                        $this->useNamedParameters = true;
                                        $right = $paramName;
                                    }
                                    $whereParts[] = $left.' '.$operator.' '.$right;
                                }
                            } elseif (is_string($cond)) {
                                $whereParts[] = $cond;
                            }
                        }
                        if (!empty($whereParts)) {
                            $sub .= ' WHERE '.implode(' AND ', $whereParts);
                        }
                    }

                    $expr = $not.'EXISTS ('.$sub.')';
                    $parts[] = ['boolean' => empty($parts) ? '' : $where['boolean'], 'expr' => '('.$expr.')'];
                    $first = false;
                }
            } elseif ($where['type'] === 'nested') {
                $nestedQueryObj = $where['query'];
                $nestedClone = clone $nestedQueryObj;
                if (property_exists($nestedClone, 'wheres') && is_array($nestedClone->wheres)) {
                    $filtered = [];
                    foreach ($nestedClone->wheres as $w) {
                        if ($w['type'] === 'basic' && ($w['operator'] === '=' || $w['operator'] === '==') && $w['value'] === '') {
                            continue;
                        }
                        $filtered[] = $w;
                    }
                    $nestedClone->wheres = $filtered;
                }

                // Build nested SQL using same unified method (recursive)
                $nestedSql = $nestedClone->buildWhereClauses();
                if (trim($nestedSql) === '') {
                    continue;
                }

                // Move named bindings from nested query into parent with new unique names
                // IMPORTANT: Read from $nestedClone (the clone that actually processed buildWhereClauses),
                // not from $nestedQueryObj (the original which may not have the bindings yet)
                $renameMap = [];

                $nestedNamed = property_exists($nestedClone, 'namedBindings') ? $nestedClone->namedBindings : [];
                if (!empty($nestedNamed)) {
                    foreach ($nestedNamed as $origName => $origVal) {
                        $newName = $this->placeholderPrefix.$this->paramCounter++;
                        $origToken = (is_string($origName) && strpos($origName, ':') === 0) ? $origName : ':'.$origName;
                        $renameMap[$origToken] = $newName;
                        $p = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                        $this->embeddedBindings[$p] = $origVal;
                    }
                    $this->useNamedParameters = true;
                }

                // Also handle embeddedBindings from the nested clone (from deeper nested WHEREs)
                $nestedEmbedded = property_exists($nestedClone, 'embeddedBindings') ? $nestedClone->embeddedBindings : [];
                if (!empty($nestedEmbedded)) {
                    foreach ($nestedEmbedded as $origName => $origVal) {
                        $newName = $this->placeholderPrefix.$this->paramCounter++;
                        $origToken = (is_string($origName) && strpos($origName, ':') === 0) ? $origName : ':'.$origName;
                        $renameMap[$origToken] = $newName;
                        $p = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                        $this->embeddedBindings[$p] = $origVal;
                    }
                    $this->useNamedParameters = true;
                }

                // Apply all renames at once using strtr (avoids prefix collision like :p1 matching :p10)
                if (!empty($renameMap)) {
                    $nestedSql = strtr($nestedSql, $renameMap);
                }

                // Handle positional bindings from nested (map ? -> named param)
                if (method_exists($nestedClone, 'getBindings')) {
                    $posBindings = $nestedClone->getBindings('where');
                    if (!empty($posBindings)) {
                        foreach ($posBindings as $bindVal) {
                            $newName = $this->placeholderPrefix.$this->paramCounter++;
                            $nestedSql = preg_replace('/\?/', $newName, $nestedSql, 1);
                            $p = is_string($newName) && strpos($newName, ':') === 0 ? substr($newName, 1) : $newName;
                            $this->embeddedBindings[$p] = $bindVal;
                        }
                        $this->useNamedParameters = true;
                    }
                }

                $nestedWrapped = '('.trim($nestedSql).')';
                $parts[] = ['boolean' => empty($parts) ? '' : $where['boolean'], 'expr' => $nestedWrapped];
                $first = false;
            }
        }

        if (empty($parts)) {
            return '';
        }

        $nested = $parts[0]['expr'];
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
            $op = strtoupper(trim($parts[$i]['boolean']));
            if ($op === '') {
                $op = 'AND';
            }
            $nested = '('.$nested.' '.$op.' '.$parts[$i]['expr'].')';
        }

        return $nested;
    }

    /**
     * Process named parameters in execute method
     *
     * @param array|null $params Named parameters provided at execution time
     * @return array Processed bindings for PDO
     */
    protected function processNamedParameters(?array $params = null): array
    {
        if (!$this->useNamedParameters) {
            // Use regular positional parameters
            $bindings = $this->getBindings();
            if ($params !== null) {
                $bindings = array_merge($bindings, array_values($params));
            }
            return $bindings;
        }

        // Start with our built named bindings
        $processedBindings = [];
        foreach ($this->namedBindings as $k => $v) {
            if (is_string($k) && strpos($k, ':') === 0) {
                $processedBindings[substr($k, 1)] = $v;
            } else {
                $processedBindings[$k] = $v;
            }
        }

        // Also include embeddedBindings (bindings coming from embedded subqueries or expressions)
        // These are stored separately during SQL generation and must be provided to PDO as well.
        if (!empty($this->embeddedBindings)) {
            foreach ($this->embeddedBindings as $name => $val) {
                if (is_string($name) && strpos($name, ':') === 0) {
                    $processedBindings[substr($name, 1)] = $val;
                } else {
                    $processedBindings[$name] = $val;
                }
            }
        }

        // Merge/override with runtime-provided named params
        if ($params !== null) {
            foreach ($params as $name => $value) {
                // Accept either ':p0' or 'p0' keys from runtime params, normalize to no-colon keys
                $key = is_string($name) && strpos($name, ':') === 0 ? substr($name, 1) : $name;
                $processedBindings[$key] = $value;
            }
        }

        return $processedBindings;
    }

    /**
     * Debug the current query
     *
     * @param bool $return Whether to return the SQL instead of echoing
     * @return QueryBuilderInterface|string
     */
    public function debug(bool $return = false)
    {
        $sql = $this->toSql();
        $this->lastQuery = $sql;
        // Prefer namedBindings if in use
        if ($this->useNamedParameters && !empty($this->namedBindings)) {
            $bindingsToUse = $this->namedBindings;
        } else {
            $bindingsToUse = $this->getBindings();
        }

        // Replace parameter bindings in SQL
        // Merge embeddedBindings for debug-time substitution (do not expose via getBindings())
        $debugBindings = [];
        if (!empty($bindingsToUse)) {
            $debugBindings = $bindingsToUse;
        }
        if (!empty($this->embeddedBindings)) {
            $debugBindings = array_merge($debugBindings, $this->embeddedBindings);
        }

        if (!empty($debugBindings)) {
            $bindTypes = [
                'integer' => \PDO::PARAM_INT,
                'boolean' => \PDO::PARAM_BOOL,
                'NULL' => \PDO::PARAM_NULL
            ];

            // Sort named bindings by key length descending to prevent prefix collision
            // e.g. :p1 must not be replaced before :p10, :p11, :p12, etc.
            uksort($debugBindings, function ($a, $b) {
                return strlen((string) $b) - strlen((string) $a);
            });

            // Separate named and positional bindings
            $namedReplacements = [];
            $positionalValues = [];

            foreach ($debugBindings as $key => $value) {
                $type = gettype($value);
                $param = isset($bindTypes[$type]) ? $bindTypes[$type] : \PDO::PARAM_STR;

                if ($param === \PDO::PARAM_STR) {
                    $value = "'".addslashes($value)."'";
                } elseif ($param === \PDO::PARAM_NULL) {
                    $value = 'NULL';
                } elseif ($param === \PDO::PARAM_BOOL) {
                    $value = $value ? '1' : '0';
                }

                if (is_string($key)) {
                    // Support keys stored either with or without leading ':'
                    if (strpos($key, ':') === 0) {
                        $token = $key;
                    } else {
                        $token = ':'.$key;
                    }

                    $namedReplacements[$token] = $value;
                } else {
                    $positionalValues[] = $value;
                }
            }

            // Replace all named parameters at once using strtr (longest match first, no overlap)
            if (!empty($namedReplacements)) {
                $sql = strtr($sql, $namedReplacements);
            }

            // Replace positional parameters (?)
            foreach ($positionalValues as $value) {
                $sql = preg_replace('/\?/', $value, $sql, 1);
            }
        }

        if ($return) {
            return $sql;
        }

        echo $sql."\n";

        return $this;
    }

    /**
     * Enable query explanation
     *
     * This method sets the explain flag to true, which can be used to get query execution plans.
     *
     * @return static The query builder instance.
     */
    public function explain(): QueryBuilderInterface
    {
        $this->explain = true;
        return $this;
    }

    /**
     * Get the last built SQL query.
     *
     * @return string|null
     */
    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    /**
     * {@inheritdoc}
     */
    public function copy(): QueryBuilderInterface
    {
        return clone $this;
    }
}
