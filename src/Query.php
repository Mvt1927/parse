<?php

namespace Parziphal\Parse;

use Closure;
use Traversable;
use Parse\ParseQuery;
use Parse\ParseObject;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class Query
{
    const OPERATORS = [
        '='  => 'equalTo',
        '!=' => 'notEqualTo',
        '>'  => 'greaterThan',
        '>=' => 'greaterThanOrEqualTo',
        '<'  => 'lessThan',
        '<=' => 'lessThanOrEqualTo',
        'in' => 'containedIn',
    ];

    /**
     * @var array
     */
    protected $includeKeys = [];

    /**
     * @var ParseQuery
     */
    protected $parseQuery;

    /**
     * @var string
     */
    protected $fullClassName;

    /**
     * @var string
     */
    protected $parseClassName;

    /**
     * @var bool
     */
    protected $useMasterKey;

    /**
     * Pass Query, ParseQuery or Closure, as params or in an
     * array. If Closure is passed, a new Query will be passed
     * as parameter.
     * First element must be an instance of Query.
     *
     * ```
     * Query::orQueries($query, $parseQuery);
     * Query::orQueries([$query, $parseQuery]);
     * Query::orQueries($query, function(Query $query) { $query->where(...); });
     * ```
     *
     * @param mixed $queries
     *
     * @return static
     */
    public static function orQueries()
    {
        $queries = func_get_args();

        if (is_array($queries[0])) {
            $queries = $queries[0];
        }

        $q = $queries[0];
        $parseQueries = [];

        foreach ($queries as $query) {
            if ($query instanceof Closure) {
                $closure = $query;

                $query = new static($q->parseClassName, $q->fullClassName, $q->useMasterKey);

                $closure($query);

                $parseQueries[] = $query;
            } else {
                $parseQueries[] = $q->parseQueryFromQuery($query);
            }
        }

        $orQuery = new static(
            $queries[0]->parseClassName,
            $queries[0]->fullClassName,
            $queries[0]->useMasterKey
        );

        $orQuery->parseQuery = ParseQuery::orQueries($parseQueries);

        return $orQuery;
    }

    public function __construct($parseClassName, $fullClassName, $useMasterKey = false)
    {
        $this->parseClassName = $parseClassName;
        $this->parseQuery     = new ParseQuery($parseClassName);
        $this->fullClassName  = $fullClassName;
        $this->useMasterKey   = $useMasterKey;
    }

    /**
     * Instance calls are passed to the Parse Query.
     *
     * @return $this
     */
    public function __call($method, array $params)
    {
        $ret = call_user_func_array([$this->parseQuery, $method], $params);

        if ($ret === $this->parseQuery) {
            return $this;
        }

        return $ret;
    }

    public function __clone()
    {
        $this->parseQuery = clone $this->parseQuery;
    }

    public function useMasterKey($value)
    {
        $this->useMasterKey = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getFullClassName()
    {
        return $this->fullClassName;
    }

    /**
     * @param mixed $queries
     *
     * @return static
     */
    public function orQuery()
    {
        $queries = func_get_args();

        if (is_array($queries[0])) {
            $queries = $queries[0];
        }

        array_unshift($queries, $this);

        return static::orQueries($queries);
    }

    /**
     * ```
     * $query->where($key, '=', $value);
     * $query->where([$key => $value]);
     * $query->where($key, $value);
     * ```
     *
     * @return $this
     */
    public function where($key, $operator = null, $value = null)
    {
        if (is_array($key)) {
            $where = $key;

            foreach ($where as $key => $value) {
                if ($value instanceof ObjectModel) {
                    $value = $value->getParseObject();
                }

                $this->parseQuery->equalTo($key, $value);
            }
        } elseif (func_num_args() == 2) {
            if ($operator instanceof ObjectModel) {
                $operator = $operator->getParseObject();
            }

            $this->parseQuery->equalTo($key, $operator);
        } else {
            if (!array_key_exists($operator, self::OPERATORS)) {
                throw new Exception("Invalid operator: " . $operator);
            }

            call_user_func([$this, self::OPERATORS[$operator]], $key, $value);
        }

        return $this;
    }

    /**
     * Alias for containedIn.
     *
     * @param  string $key
     * @param  mixed  $values
     *
     * @return $this
     */
    public function whereIn($key, $values)
    {
        return $this->containedIn($key, $values);
    }

    public function whereNotExists($key)
    {
        $this->parseQuery->doesNotExist($key);

        return $this;
    }

    /**
     * Find a record by Object ID.
     *
     * @param string $objectId
     * @param mixed  $selectKeys
     *
     * @return ObjectModel|null
     */
    public function find($objectId, $selectKeys = null)
    {
        $this->parseQuery->equalTo('objectId', $objectId);

        return $this->first($selectKeys);
    }

    /**
     * Find a record by Object ID or throw an
     * exception otherwise.
     *
     * @param string $objectId
     * @param mixed  $selectKeys
     *
     * @return ObjectModel
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail($objectId, $selectKeys = null)
    {
        $this->parseQuery->equalTo('objectId', $objectId);

        return $this->firstOrFail($selectKeys);
    }

    /**
     * Find a record by Object ID or return a new
     * instance otherwise.
     *
     * @param string $objectId
     * @param mixed  $selectKeys
     *
     * @return ObjectModel
     */
    public function findOrNew($objectId, $selectKeys = null)
    {
        $record = $this->find($objectId, $selectKeys);

        if (!$record) {
            $class = $this->fullClassName;

            $record = new $class(null, $this->useMasterKey);
        }

        return $record;
    }

    /**
     * Get the first record that matches the query.
     *
     * @param mixed $selectKeys
     *
     * @return ObjectModel|null
     */
    public function first($selectKeys = null)
    {
        // Treat '*' or ['*'] as a wildcard (no-op) to avoid passing it to Parse SDK
        if ($selectKeys !== null && $selectKeys !== '*' && $selectKeys !== ['*']) {
            $this->parseQuery->select($selectKeys);
        }

        $data = $this->parseQuery->first($this->useMasterKey);

        if ($data) {
            return $this->createModel($data);
        }
    }

    /**
     * Get the first record that matches the query
     * or throw an exception otherwise.
     *
     * @param string $objectId
     * @param mixed  $selectKeys
     *
     * @return ObjectModel
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail($selectKeys = null)
    {
        $first = $this->first($selectKeys);

        if (!$first) {
            $e = new ModelNotFoundException();

            $e->setModel($this->fullClassName);

            throw $e;
        }

        return $first;
    }

    /**
     * Get the first record that matches the query
     * or return a new instance otherwise.
     *
     * @param array $data
     *
     * @return ObjectModel
     */
    public function firstOrNew(array $data)
    {
        $record = $this->where($data)->first();

        if ($record) {
            return $record;
        }

        $class = $this->fullClassName;

        return new $class($data, $this->useMasterKey);
    }

    /**
     * Get the first record that matches the query
     * or create it otherwise.
     *
     * @param array $data
     *
     * @return ObjectModel
     */
    public function firstOrCreate(array $data)
    {
        $record = $this->firstOrNew($data);

        if (!$record->id) {
            $record->save();
        }

        return $record;
    }

    /**
     * Executes the query and returns its results.
     *
     * @param string|string[] $selectKeys
     *
     * @return Collection
     */
    public function get($selectKeys = null)
    {
        // Treat '*' or ['*'] as a wildcard (no-op) to avoid passing it to Parse SDK
        if ($selectKeys !== null && $selectKeys !== '*' && $selectKeys !== ['*']) {
            $this->select($selectKeys);
        }

        return $this->createModels($this->parseQuery->find($this->useMasterKey));
    }

    /**
     * Allow to pass instances of either Query or ParseQuery.
     *
     * @param string           $key
     * @param Query|ParseQuery $query
     *
     * @return $this
     */
    public function matchesQuery($key, $query)
    {
        $this->parseQuery->matchesQuery($key, $this->parseQueryFromQuery($query));

        return $this;
    }

    /**
     * Allow to pass instances of either Query or ParseQuery.
     *
     * @param string           $key
     * @param Query|ParseQuery $query
     *
     * @return $this
     */
    public function doesNotMatchQuery($key, $query)
    {
        $this->parseQuery->doesNotMatchQuery($key, $this->parseQueryFromQuery($query));

        return $this;
    }

    /**
     * Allow to pass instances of either Query or ParseQuery.
     *
     * @param string           $key
     * @param Query|ParseQuery $query
     *
     * @return $this
     */
    public function matchesKeyInQuery($key, $queryKey, $query)
    {
        $this->parseQuery->matchesKeyInQuery($key, $queryKey, $this->parseQueryFromQuery($query));

        return $this;
    }

    /**
     * Allow to pass instances of either Query or ParseQuery.
     *
     * @param string           $key
     * @param Query|ParseQuery $query
     *
     * @return $this
     */
    public function doesNotMatchKeyInQuery($key, $queryKey, $query)
    {
        $this->parseQuery->doesNotMatchKeyInQuery($key, $queryKey, $this->parseQueryFromQuery($query));

        return $this;
    }

    public function orderBy($key, $order = 1)
    {
        $check = is_string($order) ? strtolower($order) : $order;

        match ($check) {
            1, 'asc', 'ascending' => $this->parseQuery->ascending($key),
            0, 'desc', 'descending' => $this->parseQuery->descending($key),
            default => null, // Hoặc log lỗi nếu muốn
        };

        return $this;
    }

    /**
     * Apply the given callback if the value is truthy.
     *
     * @param mixed $value
     * @param \Closure $callback
     * @param \Closure|null $default
     * @return $this
     */
    public function when($value, Closure $callback, ?Closure $default = null)
    {
        if ($value) {
            $callback($this, $value);
        } elseif ($default) {
            $default($this, $value);
        }

        return $this;
    }

    /**
     * ObjectModels are replaced for their ParseObjects. It also accepts any kind
     * of traversable variable.
     *
     * @param  string $key
     * @param  mixed  $values
     *
     * @return $this
     */
    public function containedIn($key, $values)
    {
        if (!is_array($values) && !$values instanceof Traversable) {
            $values = [ $values ];
        }

        foreach ($values as $k => $value) {
            if ($value instanceof ObjectModel) {
                $values[$k] = $value->getParseObject();
            }
        }

        $this->parseQuery->containedIn($key, $values);

        return $this;
    }

    public function count()
    {
        return $this->parseQuery->count($this->useMasterKey);
    }

    /**
     * Alias for ParseQuery's includeKey.
     *
     * @param string|array $keys
     *
     * @return $this
     */
    public function with(...$keys)
    {
        // Allow passing a single array or multiple string arguments
        if (count($keys) === 1 && is_array($keys[0])) {
            $keys = $keys[0];
        }

        $this->includeKeys = array_merge($this->includeKeys, $keys);

        // ParseQuery::includeKey expects a string; call once per key
        if (is_array($keys)) {
            foreach ($keys as $k) {
                $this->parseQuery->includeKey($k);
            }
        } else {
            $this->parseQuery->includeKey($keys);
        }

        return $this;
    }

    /**
     * @return ParseQuery
     */
    public function getParseQuery()
    {
        return $this->parseQuery;
    }

    /**
     * Paginate the query results with a length-aware paginator.
     *
     * @param int $perPage
     * @param mixed $selectKeys
     * @param string $pageName
     * @param int|null $page
     * @return LengthAwarePaginator
     */
    public function paginate($perPage = 15, $selectKeys = null, $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        // Clone parseQuery for counting so we don't affect the main query
        $countQuery = clone $this->parseQuery;

        $total = $countQuery->count($this->useMasterKey);

        $itemsQuery = $this->parseQuery;
        $itemsQuery->limit($perPage);
        $itemsQuery->skip(($page - 1) * $perPage);

        // Treat '*' or ['*'] as a wildcard (no-op) to avoid passing it to Parse SDK
        if ($selectKeys !== null && $selectKeys !== '*' && $selectKeys !== ['*']) {
            $itemsQuery->select($selectKeys);
        }

        $objects = $itemsQuery->find($this->useMasterKey);

        $items = $this->createModels($objects);

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);

        return $paginator;
    }

    /**
     * Simple pagination without total count.
     *
     * @param int $perPage
     * @param mixed $selectKeys
     * @param string $pageName
     * @param int|null $page
     * @return Paginator
     */
    public function simplePaginate($perPage = 15, $selectKeys = null, $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $itemsQuery = clone $this->parseQuery;
        $itemsQuery->limit($perPage);
        $itemsQuery->skip(($page - 1) * $perPage);

        // Treat '*' or ['*'] as a wildcard (no-op) to avoid passing it to Parse SDK
        if ($selectKeys !== null && $selectKeys !== '*' && $selectKeys !== ['*']) {
            $itemsQuery->select($selectKeys);
        }

        $objects = $itemsQuery->find($this->useMasterKey);

        $items = $this->createModels($objects);

        $paginator = new Paginator($items, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);

        return $paginator;
    }

    /**
     * Chunk the results by primary key to avoid loading too much data into memory.
     *
     * @param int $count
     * @param \Closure $callback
     * @param string $column
     * @param string|null $alias
     * @return bool
     */
    public function chunkById($count, Closure $callback, $column = 'objectId', $alias = null)
    {
        $lastId = null;

        do {
            $query = clone $this->parseQuery;

            // apply ordering by the id column
            $query->ascending($column);

            if ($lastId) {
                $query->greaterThan($column, $lastId);
            }

            // If an alias is provided, ensure the underlying column is selected
            // so we can read its value even if the developer used a select earlier.
            if ($alias !== null && $alias !== $column) {
                try {
                    $query->select([$column]);
                } catch (\Throwable $e) {
                    // ignore: Parse SDK may throw if select is incompatible; rely on existing selection
                }
            }

            $query->limit($count);

            $objects = $query->find($this->useMasterKey);

            $models = $this->createModels($objects);

            if ($models->isEmpty()) {
                break;
            }

            // Call the callback with the current chunk. If it returns false, stop.
            $continue = $callback($models);

            if ($continue === false) {
                return false;
            }

            // Set lastId to the last model's id (respecting alias) for next iteration
            $last = $models->last();

            $lastId = null;

            if ($alias !== null) {
                // Try reading the alias attribute from the model
                if (is_object($last) && (property_exists($last, $alias) || method_exists($last, '__get') || method_exists($last, $alias))) {
                    try {
                        $val = $last->{$alias};
                        if ($val !== null) {
                            $lastId = $val;
                        }
                    } catch (\Throwable $e) {
                        // ignore and fallback
                    }
                }
            }

            if ($lastId === null) {
                // Fallbacks: Prefer object id when ordering by objectId
                if ($column === 'objectId' && is_object($last) && method_exists($last, 'id')) {
                    $lastId = $last->id();
                } else {
                    // try reading the column as attribute
                    try {
                        $lastId = is_object($last) ? $last->{$column} : null;
                    } catch (\Throwable $e) {
                        $lastId = null;
                    }
                }
            }

        } while ($models->count() == $count);

        return true;
    }

    protected function createModel(ParseObject $data)
    {
        $className = $this->fullClassName;

        $model = new $className($data, $this->useMasterKey);

        // Poke relations
        foreach ($this->includeKeys as $relationName) {
            if (strpos($relationName, '.') > 0) {
                $this->pokeNestedRelations($model, explode('.', $relationName));
            } else {
                $model->{$relationName};
            }
        }

        return $model;
    }

    protected function pokeNestedRelations(ObjectModel $parent, array $relations)
    {
        $max = count($relations);

        for ($i = 0; $i < $max; $i++) {
            if ($parent instanceof Collection) {
                $relationName = array_shift($relations);

                foreach ($parent as $member) {
                    $member->{$relationName};
                    $this->pokeNestedRelations($member, $relations);
                }

                break;
            } else {
                $parent = $parent->{$relations[$i]};
                array_shift($relations);
            }
        }
    }

    /**
     * @param array $objects ParseObject[]
     *
     * @return Collection
     */
    protected function createModels(array $objects)
    {
        $className = $this->fullClassName;
        $models    = [];

        foreach ($objects as $object) {
            $models[] = $this->createModel($object);
        }

        return new Collection($models);
    }

    /**
     * @param  Query|ParseQuery $query
     *
     * @return ParseQuery
     */
    protected function parseQueryFromQuery($query)
    {
        return $query instanceof self ? $query->parseQuery : $query;
    }
}
