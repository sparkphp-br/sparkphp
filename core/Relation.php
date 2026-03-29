<?php

// ─────────────────────────────────────────────────────
// Relationship Attributes (PHP 8+)
// ─────────────────────────────────────────────────────

/**
 * Base for all relationship attributes.
 */
abstract class RelationAttribute
{
    public readonly string $name;

    abstract public function buildRelation(Model $parent): Relation;

    /**
     * Derive the relation name from the related class.
     * Post → posts, Profile → profile, OrderItem → order_items
     */
    protected function guessName(string $related, bool $plural): string
    {
        $short = (new \ReflectionClass($related))->getShortName();
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        return $plural ? $snake . 's' : $snake;
    }
}

/**
 * Declare a has-many relationship via class attribute.
 *
 * ```php
 * #[HasMany(Post::class)]
 * #[HasMany(Comment::class, foreignKey: 'author_id')]
 * #[HasMany(Order::class, as: 'purchases')]
 * class User extends Model {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class HasMany extends RelationAttribute
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public string $localKey = 'id',
        ?string $as = null,
    ) {
        $this->name = $as ?? $this->guessName($related, plural: true);
    }

    public function buildRelation(Model $parent): HasManyRelation
    {
        $fk    = $this->foreignKey ?? $parent->guessFKReverse();
        $query = (new $this->related())::query();
        return new HasManyRelation($query, $parent, $this->related, $fk, $this->localKey);
    }
}

/**
 * Declare a has-one relationship via class attribute.
 *
 * ```php
 * #[HasOne(Profile::class)]
 * #[HasOne(Phone::class, foreignKey: 'owner_id')]
 * class User extends Model {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class HasOne extends RelationAttribute
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public string $localKey = 'id',
        ?string $as = null,
    ) {
        $this->name = $as ?? $this->guessName($related, plural: false);
    }

    public function buildRelation(Model $parent): HasOneRelation
    {
        $fk    = $this->foreignKey ?? $parent->guessFKReverse();
        $query = (new $this->related())::query();
        return new HasOneRelation($query, $parent, $this->related, $fk, $this->localKey);
    }
}

/**
 * Declare a belongs-to relationship via class attribute.
 *
 * ```php
 * #[BelongsTo(User::class)]
 * #[BelongsTo(User::class, as: 'author', foreignKey: 'author_id')]
 * class Post extends Model {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class BelongsTo extends RelationAttribute
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public string $ownerKey = 'id',
        ?string $as = null,
    ) {
        $this->name = $as ?? $this->guessName($related, plural: false);
    }

    public function buildRelation(Model $parent): BelongsToRelation
    {
        $fk    = $this->foreignKey ?? $parent->guessFK($this->related);
        $query = (new $this->related())::query();
        return new BelongsToRelation($query, $parent, $this->related, $fk, $this->ownerKey);
    }
}

/**
 * Declare a many-to-many relationship via class attribute.
 *
 * ```php
 * #[BelongsToMany(Role::class)]
 * #[BelongsToMany(Role::class, pivot: 'user_roles')]
 * #[BelongsToMany(Tag::class, as: 'tags', pivot: 'taggables')]
 * class User extends Model {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class BelongsToMany extends RelationAttribute
{
    public function __construct(
        public string $related,
        public ?string $pivot = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        ?string $as = null,
    ) {
        $this->name = $as ?? $this->guessName($related, plural: true);
    }

    public function buildRelation(Model $parent): BelongsToManyRelation
    {
        $thisTable    = $parent->getTable();
        $relatedTable = (new $this->related())->getTable();

        $tables = [$thisTable, $relatedTable];
        sort($tables);
        $pivotTable = $this->pivot ?? implode('_', $tables);

        $fk  = $this->foreignPivotKey ?? rtrim($thisTable, 's') . '_id';
        $rfk = $this->relatedPivotKey ?? rtrim($relatedTable, 's') . '_id';

        $query = (new $this->related())::query();

        return new BelongsToManyRelation($query, $parent, $this->related, $pivotTable, $fk, $rfk, $parent->getPrimaryKey());
    }
}

// ─────────────────────────────────────────────────────
// Runtime Relation classes
// ─────────────────────────────────────────────────────

/**
 * SparkPHP Relation — Base class for model relationships.
 *
 * Wraps a QueryBuilder so relationships can be chained with extra
 * constraints before executing, and supports efficient eager loading.
 */
abstract class Relation
{
    protected QueryBuilder $query;
    protected Model $parent;
    protected string $related;

    public function __construct(QueryBuilder $query, Model $parent, string $related)
    {
        $this->query   = $query;
        $this->parent  = $parent;
        $this->related = $related;
    }

    /**
     * Execute the relationship query and return results.
     */
    abstract public function getResults(): mixed;

    /**
     * Add eager-loading constraints for a batch of parent models.
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Match eager-loaded results back to their parent models.
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * Get the underlying QueryBuilder.
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    // ─────────────────────────────────────────────
    // Delegate common QueryBuilder methods
    // ─────────────────────────────────────────────

    public function where(string $column, mixed $opOrValue, mixed $value = null): static
    {
        $this->query->where($column, $opOrValue, $value);
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $this->query->whereIn($column, $values);
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->query->whereNull($column);
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->query->whereNotNull($column);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy($column, $direction);
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        $this->query->orderByDesc($column);
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        $this->query->latest($column);
        return $this;
    }

    public function oldest(string $column = 'created_at'): static
    {
        $this->query->oldest($column);
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->query->limit($limit);
        return $this;
    }

    public function select(string ...$columns): static
    {
        $this->query->select(...$columns);
        return $this;
    }

    public function get(): array
    {
        return $this->query->get();
    }

    public function first(): mixed
    {
        return $this->query->first();
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function update(array $data): int
    {
        return $this->query->update($data);
    }

    public function delete(): int
    {
        return $this->query->delete();
    }

    public function paginate(int $perPage = 15, ?int $page = null): array
    {
        return $this->query->paginate($perPage, $page);
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Collect a specific attribute value from an array of models.
     */
    protected function getKeys(array $models, string $key): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn(Model $m) => $m->getAttribute($key), $models)
        )));
    }
}

// ─────────────────────────────────────────────────────
// HasOne
// ─────────────────────────────────────────────────────

class HasOneRelation extends Relation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(QueryBuilder $query, Model $parent, string $related, string $foreignKey, string $localKey)
    {
        parent::__construct($query, $parent, $related);
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;

        // Apply default constraint for lazy loading
        $this->query->where($this->foreignKey, $this->parent->getAttribute($this->localKey));
    }

    public function getResults(): mixed
    {
        return $this->query->first();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);
        $this->query = db((new $this->related())->getTable())->setModel($this->related);
        $this->query->whereIn($this->foreignKey, $keys);
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }

        return $models;
    }
}

// ─────────────────────────────────────────────────────
// HasMany
// ─────────────────────────────────────────────────────

class HasManyRelation extends Relation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(QueryBuilder $query, Model $parent, string $related, string $foreignKey, string $localKey)
    {
        parent::__construct($query, $parent, $related);
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;

        $this->query->where($this->foreignKey, $this->parent->getAttribute($this->localKey));
    }

    public function getResults(): mixed
    {
        return $this->query->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);
        $this->query = db((new $this->related())->getTable())->setModel($this->related);
        $this->query->whereIn($this->foreignKey, $keys);
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $dictionary[$key] ?? []);
        }

        return $models;
    }
}

// ─────────────────────────────────────────────────────
// BelongsTo
// ─────────────────────────────────────────────────────

class BelongsToRelation extends Relation
{
    protected string $foreignKey;
    protected string $ownerKey;

    public function __construct(QueryBuilder $query, Model $parent, string $related, string $foreignKey, string $ownerKey)
    {
        parent::__construct($query, $parent, $related);
        $this->foreignKey = $foreignKey;
        $this->ownerKey   = $ownerKey;

        $this->query->where($this->ownerKey, $this->parent->getAttribute($this->foreignKey));
    }

    public function getResults(): mixed
    {
        return $this->query->first();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->foreignKey);
        $this->query = db((new $this->related())->getTable())->setModel($this->related);
        $this->query->whereIn($this->ownerKey, $keys);
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->ownerKey);
            $dictionary[$key] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }

        return $models;
    }
}

// ─────────────────────────────────────────────────────
// BelongsToMany
// ─────────────────────────────────────────────────────

class BelongsToManyRelation extends Relation
{
    protected string $pivot;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected string $parentKey;

    public function __construct(
        QueryBuilder $query,
        Model $parent,
        string $related,
        string $pivot,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id'
    ) {
        parent::__construct($query, $parent, $related);
        $this->pivot           = $pivot;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey       = $parentKey;
    }

    public function getResults(): mixed
    {
        $parentId = $this->parent->getAttribute($this->parentKey);

        $rows = db($this->pivot)->where($this->foreignPivotKey, $parentId)->get();
        $ids  = array_column((array) $rows, $this->relatedPivotKey);

        if (empty($ids)) {
            return [];
        }

        return $this->query->whereIn('id', $ids)->get();
    }

    public function addEagerConstraints(array $models): void
    {
        // Handled in match() since it requires pivot lookup
    }

    public function match(array $models, array $results, string $relation): array
    {
        $parentKeys = $this->getKeys($models, $this->parentKey);

        if (empty($parentKeys)) {
            foreach ($models as $model) {
                $model->setRelation($relation, []);
            }
            return $models;
        }

        // One query for all pivot rows
        $pivotRows = db($this->pivot)->whereIn($this->foreignPivotKey, $parentKeys)->get();

        // Collect all related IDs
        $allRelatedIds = array_values(array_unique(
            array_column((array) $pivotRows, $this->relatedPivotKey)
        ));

        // One query for all related models
        $relatedModels = [];
        if (!empty($allRelatedIds)) {
            $relatedQuery = db((new $this->related())->getTable())->setModel($this->related);
            $relatedModels = $relatedQuery->whereIn('id', $allRelatedIds)->get();
        }

        // Build dictionary: related id => model
        $relatedDict = [];
        foreach ($relatedModels as $rm) {
            $relatedDict[$rm->getAttribute('id')] = $rm;
        }

        // Build dictionary: parent id => [related models]
        $parentDict = [];
        foreach ($pivotRows as $row) {
            $fk = is_object($row) ? $row->{$this->foreignPivotKey} : $row[$this->foreignPivotKey];
            $rk = is_object($row) ? $row->{$this->relatedPivotKey} : $row[$this->relatedPivotKey];
            if (isset($relatedDict[$rk])) {
                $parentDict[$fk][] = $relatedDict[$rk];
            }
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->parentKey);
            $model->setRelation($relation, $parentDict[$key] ?? []);
        }

        return $models;
    }
}
