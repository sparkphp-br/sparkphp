<?php

/**
 * SparkPHP Model — Base class for database models.
 *
 * Features:
 * - Automatic table name resolution (User → users, OrderItem → order_items)
 * - Mass assignment protection via $fillable / $guarded
 * - Automatic timestamps (created_at, updated_at)
 * - Attribute casting (int, float, bool, array/json, string, datetime)
 * - Soft deletes (opt-in via $softDeletes = true)
 * - Query scopes (define scopeActive(), call User::active())
 * - Accessors & Mutators (getFullNameAttribute / setPasswordAttribute)
 * - Hidden attributes for serialization ($hidden)
 * - Relationships: belongsTo, hasMany, hasOne, belongsToMany
 * - Eager loading via with()
 * - Lifecycle events via EventEmitter
 */
abstract class Model
{
    // ─────────────────────────────────────────────
    // Configuration (override in subclass if needed)
    // ─────────────────────────────────────────────

    /** Custom table name. Empty = auto-resolved from class name. */
    protected string $table      = '';

    /** Primary key column. */
    protected string $primaryKey = 'id';

    /** Whether to auto-manage created_at / updated_at. */
    protected bool $timestamps   = true;

    /** Allowed fields for mass assignment. Empty = all columns allowed. */
    protected array $fillable    = [];

    /** Fields blocked from mass assignment. */
    protected array $guarded     = ['id'];

    /** Attribute type casts: column => type (int, float, bool, array, json, string, datetime). */
    protected array $casts       = [];

    /** Enable soft deletes (adds deleted_at column handling). */
    protected bool $softDeletes  = false;

    /** Attributes hidden from toArray() and toJson() serialization. */
    protected array $hidden      = [];

    // ─────────────────────────────────────────────
    // Runtime state
    // ─────────────────────────────────────────────

    /** Whether this model instance exists in the database. */
    public bool $exists          = false;

    /** Current attribute values. */
    private array $attributes    = [];

    /** Original attribute values (as loaded from DB). */
    private array $original      = [];

    /** Cached eager-loaded relations: relation name => result. */
    private array $relations     = [];

    // ─────────────────────────────────────────────
    // Table name resolution
    // ─────────────────────────────────────────────

    /**
     * Get the table name for this model.
     * Auto-resolves from class name: User → users, OrderItem → order_items.
     */
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

    /**
     * Get a query builder for this model.
     *
     * ```php
     * $users = User::query()->where('active', true)->get();
     * ```
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        $builder = db(static::resolveTable())->setModel(static::class);

        // Apply soft delete scope by default
        if ($instance->softDeletes) {
            $builder->whereNull('deleted_at');
        }

        return $builder;
    }

    /**
     * Get all records.
     *
     * ```php
     * $users = User::all();
     * ```
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Find a record by primary key.
     *
     * ```php
     * $user = User::find(1);
     * ```
     */
    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * Find a record by primary key or abort with 404.
     *
     * ```php
     * $user = User::findOrFail(1); // throws 404 if not found
     * ```
     */
    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);
        if (!$model) {
            abort(404, 'Resource not found');
        }
        return $model;
    }

    /**
     * Start a where clause on the model.
     *
     * ```php
     * $admins = User::where('role', 'admin')->get();
     * $adults = User::where('age', '>', 18)->get();
     * ```
     */
    public static function where(string $column, mixed $opOrValue, mixed $value = null): QueryBuilder
    {
        return $value !== null
            ? static::query()->where($column, $opOrValue, $value)
            : static::query()->where($column, $opOrValue);
    }

    /**
     * Eager-load relationships.
     *
     * ```php
     * $users = User::with('orders', 'profile')->get();
     * ```
     */
    public static function with(string ...$relations): QueryBuilder
    {
        return static::query()->with(...$relations);
    }

    /**
     * Create a new record.
     *
     * ```php
     * $user = User::create(['name' => 'João', 'email' => 'j@mail.com']);
     * ```
     */
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

    /**
     * Update the model's attributes.
     *
     * ```php
     * $user->update(['name' => 'Novo Nome']);
     * ```
     */
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

    /**
     * Delete the model from the database.
     * If soft deletes are enabled, sets deleted_at instead of actually deleting.
     *
     * ```php
     * $user->delete(); // soft delete if enabled, hard delete otherwise
     * ```
     */
    public function delete(): bool
    {
        EventEmitter::dispatch(static::resolveTable() . '.deleting', $this);

        if ($this->softDeletes) {
            $now = date('Y-m-d H:i:s');
            $this->setAttribute('deleted_at', $now);
            $affected = db(static::resolveTable())
                ->where($this->primaryKey, $this->{$this->primaryKey})
                ->update(['deleted_at' => $now]);
        } else {
            $affected = db(static::resolveTable())
                ->where($this->primaryKey, $this->{$this->primaryKey})
                ->delete();
        }

        $this->exists = false;

        EventEmitter::dispatch(static::resolveTable() . '.deleted', $this);

        return $affected > 0;
    }

    /**
     * Save the model (insert or update).
     *
     * ```php
     * $user = new User();
     * $user->name = 'João';
     * $user->save();
     * ```
     */
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
    // Soft Deletes
    // ─────────────────────────────────────────────

    /**
     * Permanently delete a soft-deleted model.
     *
     * ```php
     * $user->forceDelete(); // actually removes from database
     * ```
     */
    public function forceDelete(): bool
    {
        EventEmitter::dispatch(static::resolveTable() . '.deleting', $this);

        $affected = db(static::resolveTable())
            ->where($this->primaryKey, $this->{$this->primaryKey})
            ->delete();

        $this->exists = false;

        EventEmitter::dispatch(static::resolveTable() . '.deleted', $this);

        return $affected > 0;
    }

    /**
     * Restore a soft-deleted model.
     *
     * ```php
     * $user->restore(); // sets deleted_at back to NULL
     * ```
     */
    public function restore(): bool
    {
        if (!$this->softDeletes) {
            return false;
        }

        $this->setAttribute('deleted_at', null);

        $affected = db(static::resolveTable())
            ->where($this->primaryKey, $this->{$this->primaryKey})
            ->update(['deleted_at' => null]);

        $this->exists = true;

        return $affected > 0;
    }

    /**
     * Check if the model is soft-deleted.
     *
     * ```php
     * if ($user->trashed()) { ... }
     * ```
     */
    public function trashed(): bool
    {
        return $this->softDeletes && $this->getAttribute('deleted_at') !== null;
    }

    /**
     * Query including soft-deleted records.
     *
     * ```php
     * $allUsers = User::withTrashed()->get();
     * ```
     */
    public static function withTrashed(): QueryBuilder
    {
        // Skip the default soft-delete scope
        return db(static::resolveTable())->setModel(static::class);
    }

    /**
     * Query only soft-deleted records.
     *
     * ```php
     * $deleted = User::onlyTrashed()->get();
     * ```
     */
    public static function onlyTrashed(): QueryBuilder
    {
        return db(static::resolveTable())
            ->setModel(static::class)
            ->whereNotNull('deleted_at');
    }

    // ─────────────────────────────────────────────
    // Static convenience methods
    // ─────────────────────────────────────────────

    /**
     * Find the first matching record, or create it.
     *
     * ```php
     * $user = User::firstOrCreate(
     *     ['email' => 'j@mail.com'],
     *     ['name' => 'João']
     * );
     * ```
     */
    public static function firstOrCreate(array $conditions, array $values = []): static
    {
        $query = static::query();
        foreach ($conditions as $col => $val) {
            $query->where($col, $val);
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        return static::create(array_merge($conditions, $values));
    }

    /**
     * Update an existing record matching conditions, or create it.
     *
     * ```php
     * $setting = Setting::updateOrCreate(
     *     ['key' => 'theme'],
     *     ['value' => 'dark']
     * );
     * ```
     */
    public static function updateOrCreate(array $conditions, array $values = []): static
    {
        $query = static::query();
        foreach ($conditions as $col => $val) {
            $query->where($col, $val);
        }

        $existing = $query->first();

        if ($existing) {
            $existing->update($values);
            return $existing;
        }

        return static::create(array_merge($conditions, $values));
    }

    // ─────────────────────────────────────────────
    // Fresh / Replicate
    // ─────────────────────────────────────────────

    /**
     * Reload the model's attributes from the database.
     *
     * ```php
     * $user->update(['name' => 'Novo']);
     * $user = $user->fresh(); // re-reads from DB
     * ```
     */
    public function fresh(): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return static::withTrashed()->find($this->{$this->primaryKey});
    }

    /**
     * Clone the model into a new, unsaved instance (without the primary key).
     *
     * ```php
     * $copy = $post->replicate();
     * $copy->title = 'Cópia do post';
     * $copy->save();
     * ```
     */
    public function replicate(array $except = []): static
    {
        $clone = new static();
        $excluded = array_merge([$this->primaryKey], $except);

        if ($this->timestamps) {
            $excluded = array_merge($excluded, ['created_at', 'updated_at']);
        }

        if ($this->softDeletes) {
            $excluded[] = 'deleted_at';
        }

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $excluded, true)) {
                $clone->setAttribute($key, $value);
            }
        }

        return $clone;
    }

    // ─────────────────────────────────────────────
    // Fill / attributes
    // ─────────────────────────────────────────────

    /**
     * Mass-assign attributes respecting $fillable and $guarded.
     *
     * ```php
     * $user->fill(['name' => 'João', 'email' => 'j@mail.com']);
     * ```
     */
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

    /**
     * Set an attribute value (applies mutators and casts).
     */
    public function setAttribute(string $key, mixed $value): void
    {
        // Check for a mutator: setNameAttribute($value)
        $mutator = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $value = $this->$mutator($value);
        }

        $this->attributes[$key] = $this->castValue($key, $value);
    }

    /**
     * Get an attribute value (applies accessors).
     */
    public function getAttribute(string $key): mixed
    {
        // Check for an accessor: getNameAttribute()
        $accessor = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        return $this->attributes[$key] ?? null;
    }

    /**
     * Get all raw attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get attributes that have been changed since loading.
     */
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

    /**
     * Check if the model has unsaved changes.
     */
    public function isDirty(?string $attribute = null): bool
    {
        if ($attribute !== null) {
            return array_key_exists($attribute, $this->getDirty());
        }
        return !empty($this->getDirty());
    }

    /**
     * Check if the model has no unsaved changes.
     */
    public function isClean(?string $attribute = null): bool
    {
        return !$this->isDirty($attribute);
    }

    /**
     * Sync current attributes to the "original" snapshot.
     * Called internally after hydration from the database.
     */
    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    // ─────────────────────────────────────────────
    // Serialization
    // ─────────────────────────────────────────────

    /**
     * Convert the model to an array (respects $hidden).
     *
     * ```php
     * $data = $user->toArray();
     * // Does NOT include fields in $hidden (e.g., 'password')
     * ```
     */
    public function toArray(): array
    {
        $data = $this->attributes;

        // Apply accessors
        foreach ($this->attributes as $key => $value) {
            $accessor = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $data[$key] = $this->$accessor();
            }
        }

        // Include loaded relations
        foreach ($this->relations as $name => $relation) {
            if ($relation instanceof Model) {
                $data[$name] = $relation->toArray();
            } elseif (is_array($relation)) {
                $data[$name] = array_map(
                    fn($item) => $item instanceof Model ? $item->toArray() : (array) $item,
                    $relation
                );
            }
        }

        // Remove hidden fields
        foreach ($this->hidden as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * Convert the model to JSON (respects $hidden).
     *
     * ```php
     * echo $user->toJson();
     * ```
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    // ─────────────────────────────────────────────
    // Eager loading support
    // ─────────────────────────────────────────────

    /**
     * Set a loaded relation result on this model.
     * Used internally by the QueryBuilder eager loader.
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    /**
     * Get a loaded relation, or null if not loaded.
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Check if a relation has been loaded.
     */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
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
            'datetime'       => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value),
            default          => $value,
        };
    }

    // ─────────────────────────────────────────────
    // Magic accessors
    // ─────────────────────────────────────────────

    public function __get(string $name): mixed
    {
        // Check for accessor
        $accessor = 'get' . str_replace('_', '', ucwords($name, '_')) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        // Check attribute
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        // Check loaded relation
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // Check relationship method (lazy load)
        if (method_exists($this, $name)) {
            $result = $this->$name();
            $this->relations[$name] = $result;
            return $result;
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name])
            || isset($this->relations[$name])
            || method_exists($this, 'get' . str_replace('_', '', ucwords($name, '_')) . 'Attribute');
    }

    // ─────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────

    /**
     * Handle static calls to scope methods.
     * Allows calling User::active() if scopeActive() is defined.
     *
     * ```php
     * class User extends Model {
     *     public function scopeActive($query) {
     *         return $query->where('active', true);
     *     }
     * }
     *
     * // Usage:
     * User::active()->get();
     * User::active()->where('role', 'admin')->get();
     * ```
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        $instance = new static();
        $scopeMethod = 'scope' . ucfirst($method);

        if (method_exists($instance, $scopeMethod)) {
            $query = static::query();
            return $instance->$scopeMethod($query, ...$args) ?? $query;
        }

        throw new \BadMethodCallException("Method {$method}() does not exist on " . static::class);
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    /**
     * Define a belongs-to relationship.
     *
     * ```php
     * public function company() {
     *     return $this->belongsTo(Company::class);
     * }
     * // with custom FK:
     * public function supervisor() {
     *     return $this->belongsTo(User::class, 'supervisor_id');
     * }
     * ```
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, string $ownerKey = 'id'): mixed
    {
        $fk = $foreignKey ?? $this->guessFK($related);
        return (new $related())->query()->where($ownerKey, $this->getAttribute($fk))->first();
    }

    /**
     * Define a has-many relationship.
     *
     * ```php
     * public function orders() {
     *     return $this->hasMany(Order::class);
     * }
     * ```
     */
    protected function hasMany(string $related, ?string $foreignKey = null, string $localKey = 'id'): array
    {
        $fk = $foreignKey ?? $this->guessFKReverse();
        return (new $related())->query()->where($fk, $this->getAttribute($localKey))->get();
    }

    /**
     * Define a has-one relationship.
     *
     * ```php
     * public function profile() {
     *     return $this->hasOne(Profile::class);
     * }
     * ```
     */
    protected function hasOne(string $related, ?string $foreignKey = null, string $localKey = 'id'): mixed
    {
        $fk = $foreignKey ?? $this->guessFKReverse();
        return (new $related())->query()->where($fk, $this->getAttribute($localKey))->first();
    }

    /**
     * Define a many-to-many relationship via pivot table.
     *
     * ```php
     * public function roles() {
     *     return $this->belongsToMany(Role::class);
     * }
     * // Pivot table is auto-resolved: roles_users (alphabetical)
     * ```
     */
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
