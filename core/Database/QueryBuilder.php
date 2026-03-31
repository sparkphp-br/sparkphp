<?php

require_once __DIR__ . '/Query/SparkVectorMath.php';
require_once __DIR__ . '/Query/SparkQueryBuilderSql.php';
require_once __DIR__ . '/Query/SparkQueryBuilderClauses.php';
require_once __DIR__ . '/Query/SparkQueryBuilderReads.php';
require_once __DIR__ . '/Query/SparkQueryBuilderWrites.php';
require_once __DIR__ . '/Query/SparkQueryBuilderRelations.php';
require_once __DIR__ . '/Query/SparkQueryBuilderVector.php';

class QueryBuilder
{
    /**
     * QueryBuilder composes a fixed set of internal traits.
     * They depend on the builder's shared state and helper methods.
     */
    use SparkQueryBuilderSql;
    use SparkQueryBuilderClauses;
    use SparkQueryBuilderReads;
    use SparkQueryBuilderWrites;
    use SparkQueryBuilderRelations;
    use SparkQueryBuilderVector;

    private Database $db;
    private string $table;

    /** @var array<int, array{clause: string, connector: string}> */
    private array $wheres = [];
    private array $bindings = [];
    private ?string $orderBy = null;
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private array $selects = ['*'];
    private string $modelClass = '';

    /** @var array<int, string> */
    private array $joins = [];
    private array $joinBindings = [];
    private ?string $groupByClause = null;

    /** @var array<int, string> */
    private array $havings = [];
    private array $havingBindings = [];

    /** @var array<string, array{constraint: callable|null, nested: array}> */
    private array $eagerLoads = [];

    /** @var array<string, callable|null> */
    private array $withCounts = [];
    private ?array $vectorSearch = null;
    private ?array $vectorSelect = null;
    private ?array $vectorOrder = null;

    public function __construct(Database $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function setModel(string $class): static
    {
        $this->modelClass = $class;
        return $this;
    }

    private function hydrateAll(array $rows): array
    {
        if (!$this->modelClass || !class_exists($this->modelClass)) {
            return $rows;
        }

        return array_map(fn($row) => $this->hydrateOne($row), $rows);
    }

    private function hydrateOne(object $row): object
    {
        if (!$this->modelClass || !class_exists($this->modelClass)) {
            return $row;
        }

        /** @var Model $model */
        $model = new $this->modelClass();
        foreach ((array) $row as $key => $value) {
            $model->$key = $value;
        }
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }
}
