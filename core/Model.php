<?php

abstract class Model
{
    // ─────────────────────────────────────────────
    // Configuration (override in subclass if needed)
    // ─────────────────────────────────────────────

    protected string $table      = '';
    protected string $primaryKey = 'id';
    protected bool $timestamps   = true;
    protected array $fillable    = [];  // empty = all columns allowed
    protected array $guarded     = ['id'];
    protected array $casts       = [];

    // Runtime state
    public bool $exists          = false;
    private array $attributes    = [];
    private array $original      = [];

    // ─────────────────────────────────────────────
    // Table name resolution
    // ─────────────────────────────────────────────

    public function getTable(): string
    {
        if ($this->table) {
            return $this->table;
        }
        // User → users, OrderItem → order_items
        $class = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class)) . 's';
    }

    // ─────────────────────────────────────────────
    // Static factory methods
    // ─────────────────────────────────────────────

    public static function query(): QueryBuilder
    {
        return db(static::resolveTable())->setModel(static::class);
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);
        if (!$model) {
            abort(404, 'Resource not found');
        }
        return $model;
    }

    public static function where(string $column, mixed $opOrValue, mixed $value = null): QueryBuilder
    {
        return $value !== null
            ? static::query()->where($column, $opOrValue, $value)
            : static::query()->where($column, $opOrValue);
    }

    public static function create(array $data): static
    {
        $model = new static();
        $model->fill($data);

        if ($model->timestamps) {
            $now = date('Y-m-d H:i:s');
            $model->setAttribute('created_at', $now);
            $model->setAttribute('updated_at', $now);
        }

        $record = db(static::resolveTable())->create($model->getAttributes());

        // Copy back generated id + attributes
        foreach ((array) $record as $k => $v) {
            $model->setAttribute($k, $v);
        }
        $model->exists   = true;
        $model->original = $model->attributes;

        // Dispatch lifecycle event
        EventEmitter::dispatch(static::resolveTable() . '.created', $model);

        return $model;
    }

    public function update(array $data = []): bool
    {
        if ($data) {
            $this->fill($data);
        }

        if ($this->timestamps) {
            $this->setAttribute('updated_at', date('Y-m-d H:i:s'));
        }

        EventEmitter::dispatch(static::resolveTable() . '.updating', $this);

        $affected = db(static::resolveTable())
            ->where($this->primaryKey, $this->{$this->primaryKey})
            ->update($this->getDirty());

        $this->original = $this->attributes;

        EventEmitter::dispatch(static::resolveTable() . '.updated', $this);

        return $affected > 0;
    }

    public function delete(): bool
    {
        EventEmitter::dispatch(static::resolveTable() . '.deleting', $this);

        $affected = db(static::resolveTable())
            ->where($this->primaryKey, $this->{$this->primaryKey})
            ->delete();

        $this->exists = false;

        EventEmitter::dispatch(static::resolveTable() . '.deleted', $this);

        return $affected > 0;
    }

    public function save(): bool
    {
        if ($this->exists) {
            return $this->update();
        }

        $created = static::create($this->attributes);
        $this->attributes = $created->attributes;
        $this->exists     = true;
        return true;
    }

    // ─────────────────────────────────────────────
    // Fill / attributes
    // ─────────────────────────────────────────────

    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $this->guarded, true)) {
                continue;
            }
            if (!empty($this->fillable) && !in_array($key, $this->fillable, true)) {
                continue;
            }
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $this->castValue($key, $value);
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function toJson(): string
    {
        return json_encode($this->attributes, JSON_UNESCAPED_UNICODE);
    }

    // ─────────────────────────────────────────────
    // Casts
    // ─────────────────────────────────────────────

    private function castValue(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }
        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double'=> (float) $value,
            'bool', 'boolean'=> (bool) $value,
            'array', 'json'  => is_string($value) ? json_decode($value, true) : $value,
            'string'         => (string) $value,
            default          => $value,
        };
    }

    // ─────────────────────────────────────────────
    // Magic accessors
    // ─────────────────────────────────────────────

    public function __get(string $name): mixed
    {
        // Check attribute
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        // Check relationship method
        if (method_exists($this, $name)) {
            return $this->$name();
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    protected function belongsTo(string $related, ?string $foreignKey = null, string $ownerKey = 'id'): mixed
    {
        $fk = $foreignKey ?? $this->guessFK($related);
        return (new $related())->query()->where($ownerKey, $this->getAttribute($fk))->first();
    }

    protected function hasMany(string $related, ?string $foreignKey = null, string $localKey = 'id'): array
    {
        $fk = $foreignKey ?? $this->guessFKReverse();
        return (new $related())->query()->where($fk, $this->getAttribute($localKey))->get();
    }

    protected function hasOne(string $related, ?string $foreignKey = null, string $localKey = 'id'): mixed
    {
        $fk = $foreignKey ?? $this->guessFKReverse();
        return (new $related())->query()->where($fk, $this->getAttribute($localKey))->first();
    }

    protected function belongsToMany(string $related, ?string $pivot = null, ?string $fk = null, ?string $rfk = null): array
    {
        $thisTable    = static::resolveTable();
        $relatedTable = (new $related())->getTable();

        // Pivot table: alphabetical order
        $tables    = [$thisTable, $relatedTable];
        sort($tables);
        $pivot = $pivot ?? implode('_', $tables);

        $thisFk    = $fk  ?? rtrim($thisTable, 's') . '_id';
        $relatedFk = $rfk ?? rtrim($relatedTable, 's') . '_id';

        $primaryKey = $this->getAttribute($this->primaryKey);

        $rows = db($pivot)->where($thisFk, $primaryKey)->get();
        $ids  = array_column((array) $rows, $relatedFk);

        if (empty($ids)) {
            return [];
        }

        return (new $related())->query()->whereIn('id', $ids)->get();
    }

    private function guessFK(string $relatedClass): string
    {
        $short = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0',
            (new \ReflectionClass($relatedClass))->getShortName()
        ));
        return $short . '_id';
    }

    private function guessFKReverse(): string
    {
        $short = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0',
            (new \ReflectionClass($this))->getShortName()
        ));
        return $short . '_id';
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    protected static function resolveTable(): string
    {
        return (new static())->getTable();
    }
}
