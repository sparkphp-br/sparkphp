<?php

class Schema
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public static function connection(?Database $db = null): static
    {
        return new static($db);
    }

    public static function create(string $table, callable $callback): void
    {
        static::connection()->runCreate($table, $callback);
    }

    public static function table(string $table, callable $callback): void
    {
        static::connection()->runTable($table, $callback);
    }

    public static function drop(string $table): void
    {
        static::connection()->runDrop($table, false);
    }

    public static function dropIfExists(string $table): void
    {
        static::connection()->runDrop($table, true);
    }

    public static function rename(string $from, string $to): void
    {
        static::connection()->runRename($from, $to);
    }

    public function createTable(string $table, callable $callback): void
    {
        $this->runCreate($table, $callback);
    }

    public function alterTable(string $table, callable $callback): void
    {
        $this->runTable($table, $callback);
    }

    public function dropTable(string $table): void
    {
        $this->runDrop($table, false);
    }

    public function dropTableIfExists(string $table): void
    {
        $this->runDrop($table, true);
    }

    public function renameTable(string $from, string $to): void
    {
        $this->runRename($from, $to);
    }

    public function getGrammar(): SchemaGrammar
    {
        return SchemaGrammar::forDriver($this->db->driver());
    }

    private function runCreate(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, 'create');
        $callback($blueprint);
        $this->execute($this->getGrammar()->compile($blueprint));
    }

    private function runTable(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, 'table');
        $callback($blueprint);
        $this->execute($this->getGrammar()->compile($blueprint));
    }

    private function runDrop(string $table, bool $ifExists): void
    {
        $this->execute($this->getGrammar()->compileDrop($table, $ifExists));
    }

    private function runRename(string $from, string $to): void
    {
        $this->execute($this->getGrammar()->compileRename($from, $to));
    }

    private function execute(array $statements): void
    {
        foreach ($statements as $statement) {
            $sql = trim($statement);
            if ($sql === '') {
                continue;
            }

            $this->db->statement($sql);
        }
    }
}

class Blueprint
{
    private string $table;
    private string $mode;
    /** @var array<int, ColumnDefinition> */
    private array $columns = [];
    /** @var array<int, array{type: string, data: array}> DDL operations (drop, rename, etc.) */
    private array $operations = [];

    public function __construct(string $table, string $mode)
    {
        $this->table = $table;
        $this->mode = $mode;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return array<int, ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int, array{type: string, data: array}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    // ─── Column definitions ──────────────────────

    /**
     * Auto-incrementing BIGINT primary key.
     *
     * ```php
     * $table->id();
     * $table->id('user_id');
     * ```
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn('id', $name)->primary()->autoIncrement();
    }

    /**
     * VARCHAR column.
     *
     * ```php
     * $table->string('name');
     * $table->string('code', 10);
     * ```
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    /**
     * TEXT column.
     *
     * ```php
     * $table->text('description');
     * ```
     */
    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    /**
     * MEDIUMTEXT column (up to 16MB).
     *
     * ```php
     * $table->mediumText('content');
     * ```
     */
    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumText', $name);
    }

    /**
     * LONGTEXT column (up to 4GB).
     *
     * ```php
     * $table->longText('body');
     * ```
     */
    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    /**
     * BOOLEAN column.
     *
     * ```php
     * $table->boolean('active')->default(true);
     * ```
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    /**
     * TINYINT column (MySQL) / SMALLINT (PostgreSQL) / INTEGER (SQLite).
     *
     * ```php
     * $table->tinyInteger('priority');
     * ```
     */
    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    /**
     * SMALLINT column.
     *
     * ```php
     * $table->smallInteger('quantity');
     * ```
     */
    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    /**
     * INT column.
     *
     * ```php
     * $table->integer('age');
     * ```
     */
    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    /**
     * BIGINT column.
     *
     * ```php
     * $table->bigInteger('views');
     * ```
     */
    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    /**
     * FLOAT column.
     *
     * ```php
     * $table->float('latitude', 10, 7);
     * ```
     */
    public function float(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('float', $name, [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * DECIMAL column.
     *
     * ```php
     * $table->decimal('price', 10, 2);
     * ```
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * ENUM column (limited set of allowed values).
     *
     * ```php
     * $table->enum('status', ['active', 'inactive', 'suspended']);
     * ```
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        return $this->addColumn('enum', $name, ['values' => $values]);
    }

    /**
     * JSON / JSONB column.
     *
     * ```php
     * $table->json('metadata');
     * ```
     */
    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    /**
     * DATE column.
     *
     * ```php
     * $table->date('birthday');
     * ```
     */
    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    /**
     * DATETIME column.
     *
     * ```php
     * $table->datetime('published_at');
     * ```
     */
    public function datetime(string $name): ColumnDefinition
    {
        return $this->addColumn('datetime', $name);
    }

    /**
     * TIMESTAMP column.
     *
     * ```php
     * $table->timestamp('verified_at');
     * ```
     */
    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name);
    }

    /**
     * Add created_at and updated_at TIMESTAMP columns.
     *
     * ```php
     * $table->timestamps();
     * ```
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * Add a deleted_at TIMESTAMP column for soft deletes.
     *
     * ```php
     * $table->softDeletes();
     * ```
     */
    public function softDeletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($name)->nullable();
    }

    /**
     * UUID column (CHAR(36) for MySQL/SQLite, native UUID for PostgreSQL).
     *
     * ```php
     * $table->uuid('external_id');
     * ```
     */
    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn('uuid', $name);
    }

    /**
     * BLOB / BYTEA column for binary data.
     *
     * ```php
     * $table->binary('file_data');
     * ```
     */
    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn('binary', $name);
    }

    /**
     * Foreign key column (BIGINT UNSIGNED).
     *
     * ```php
     * $table->foreignId('user_id')->constrained()->cascadeOnDelete();
     * ```
     */
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->addColumn('foreignId', $name)->unsigned();
    }

    // ─── Schema operations (for Schema::table) ──

    /**
     * Drop a column from the table.
     *
     * ```php
     * Schema::table('users', function (Blueprint $table) {
     *     $table->dropColumn('legacy_field');
     * });
     * ```
     */
    public function dropColumn(string ...$columns): void
    {
        foreach ($columns as $column) {
            $this->operations[] = ['type' => 'dropColumn', 'data' => ['column' => $column]];
        }
    }

    /**
     * Rename a column.
     *
     * ```php
     * Schema::table('users', function (Blueprint $table) {
     *     $table->renameColumn('name', 'full_name');
     * });
     * ```
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->operations[] = ['type' => 'renameColumn', 'data' => ['from' => $from, 'to' => $to]];
    }

    /**
     * Drop an index.
     *
     * ```php
     * Schema::table('users', function (Blueprint $table) {
     *     $table->dropIndex('users_email_index');
     * });
     * ```
     */
    public function dropIndex(string $indexName): void
    {
        $this->operations[] = ['type' => 'dropIndex', 'data' => ['name' => $indexName]];
    }

    /**
     * Drop a unique constraint.
     *
     * ```php
     * Schema::table('users', function (Blueprint $table) {
     *     $table->dropUnique('users_email_unique');
     * });
     * ```
     */
    public function dropUnique(string $indexName): void
    {
        $this->operations[] = ['type' => 'dropIndex', 'data' => ['name' => $indexName]];
    }

    /**
     * Drop a foreign key constraint.
     *
     * ```php
     * Schema::table('users', function (Blueprint $table) {
     *     $table->dropForeign('posts_user_id_foreign');
     * });
     * ```
     */
    public function dropForeign(string $constraintName): void
    {
        $this->operations[] = ['type' => 'dropForeign', 'data' => ['name' => $constraintName]];
    }

    private function addColumn(string $type, string $name, array $attributes = []): ColumnDefinition
    {
        $column = new ColumnDefinition($this, $type, $name, $attributes);
        $this->columns[] = $column;
        return $column;
    }
}

class ColumnDefinition
{
    private Blueprint $blueprint;
    private string $type;
    private string $name;
    private array $attributes;
    private bool $nullable = false;
    private bool $unsigned = false;
    private bool $autoIncrement = false;
    private bool $primary = false;
    private bool $defaultSet = false;
    private mixed $default = null;
    private bool $index = false;
    private ?string $indexName = null;
    private bool $unique = false;
    private ?string $uniqueName = null;
    private ?string $references = null;
    private ?string $onTable = null;
    private ?string $onDelete = null;
    private ?string $onUpdate = null;
    private ?string $after = null;
    private ?string $comment = null;

    public function __construct(Blueprint $blueprint, string $type, string $name, array $attributes = [])
    {
        $this->blueprint = $blueprint;
        $this->type = $type;
        $this->name = $name;
        $this->attributes = $attributes;
    }

    public function blueprint(): Blueprint
    {
        return $this->blueprint;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    /** Mark the column as nullable. */
    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;
        return $this;
    }

    /** Set a default value for the column. */
    public function default(mixed $value): static
    {
        $this->defaultSet = true;
        $this->default = $value;
        return $this;
    }

    /** Add a UNIQUE constraint. */
    public function unique(?string $name = null): static
    {
        $this->unique = true;
        $this->uniqueName = $name;
        return $this;
    }

    /** Add an INDEX. */
    public function index(?string $name = null): static
    {
        $this->index = true;
        $this->indexName = $name;
        return $this;
    }

    /** Mark as PRIMARY KEY. */
    public function primary(bool $value = true): static
    {
        $this->primary = $value;
        return $this;
    }

    /** Mark as UNSIGNED (MySQL). */
    public function unsigned(bool $value = true): static
    {
        $this->unsigned = $value;
        return $this;
    }

    /** Mark as AUTO_INCREMENT / SERIAL. */
    public function autoIncrement(bool $value = true): static
    {
        $this->autoIncrement = $value;
        return $this;
    }

    /** Set the column this references (foreign key). */
    public function references(string $column): static
    {
        $this->references = $column;
        return $this;
    }

    /** Set the table this column references. */
    public function on(string $table): static
    {
        $this->onTable = $table;
        return $this;
    }

    /**
     * Shorthand to set foreign key reference and table.
     *
     * ```php
     * $table->foreignId('user_id')->constrained()->cascadeOnDelete();
     * ```
     */
    public function constrained(?string $table = null, string $column = 'id'): static
    {
        $table ??= $this->inferForeignTable();

        return $this->references($column)->on($table);
    }

    /** Set ON DELETE action. */
    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /** Set ON UPDATE action. */
    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /** Shorthand: ON DELETE CASCADE. */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('cascade');
    }

    /** Shorthand: ON DELETE SET NULL. */
    public function nullOnDelete(): static
    {
        return $this->onDelete('set null');
    }

    /**
     * Position this column after another (MySQL only, ignored on other drivers).
     *
     * ```php
     * $table->string('phone')->after('email');
     * ```
     */
    public function after(string $column): static
    {
        $this->after = $column;
        return $this;
    }

    /**
     * Add a comment to the column (MySQL only).
     *
     * ```php
     * $table->string('code')->comment('ISO country code');
     * ```
     */
    public function comment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    // ─── Introspection ───────────────────────────

    public function isNullable(): bool { return $this->nullable; }
    public function isUnsigned(): bool { return $this->unsigned; }
    public function isAutoIncrement(): bool { return $this->autoIncrement; }
    public function isPrimary(): bool { return $this->primary; }
    public function hasDefault(): bool { return $this->defaultSet; }
    public function defaultValue(): mixed { return $this->default; }
    public function hasIndex(): bool { return $this->index; }
    public function hasUnique(): bool { return $this->unique; }
    public function hasForeign(): bool { return $this->references !== null && $this->onTable !== null; }
    public function hasAfter(): bool { return $this->after !== null; }
    public function afterColumn(): ?string { return $this->after; }
    public function hasComment(): bool { return $this->comment !== null; }
    public function commentText(): ?string { return $this->comment; }
    public function referencesColumn(): ?string { return $this->references; }
    public function onTable(): ?string { return $this->onTable; }
    public function onDeleteAction(): ?string { return $this->onDelete; }
    public function onUpdateAction(): ?string { return $this->onUpdate; }

    public function indexName(string $table): ?string
    {
        if (!$this->hasIndex()) { return null; }
        return $this->indexName ?: "{$table}_{$this->name}_index";
    }

    public function uniqueName(string $table): ?string
    {
        if (!$this->hasUnique()) { return null; }
        return $this->uniqueName ?: "{$table}_{$this->name}_unique";
    }

    public function foreignName(string $table): ?string
    {
        if (!$this->hasForeign()) { return null; }
        return "{$table}_{$this->name}_foreign";
    }

    private function inferForeignTable(): string
    {
        $base = preg_replace('/_id$/', '', $this->name);
        if ($base === $this->name) {
            throw new RuntimeException("Cannot infer foreign table from column [{$this->name}]");
        }

        if (str_ends_with($base, 'y')) {
            return substr($base, 0, -1) . 'ies';
        }

        if (str_ends_with($base, 's')) {
            return $base;
        }

        return $base . 's';
    }
}

abstract class SchemaGrammar
{
    public static function forDriver(string $driver): static
    {
        return match ($driver) {
            'mysql' => new MySqlSchemaGrammar(),
            'pgsql' => new PgsqlSchemaGrammar(),
            'sqlite' => new SqliteSchemaGrammar(),
            default => throw new RuntimeException("Unsupported DB driver for schema: {$driver}"),
        };
    }

    /**
     * @return array<int, string>
     */
    public function compile(Blueprint $blueprint): array
    {
        return match ($blueprint->mode()) {
            'create' => $this->compileCreate($blueprint),
            'table' => $this->compileTable($blueprint),
            default => throw new RuntimeException("Unsupported blueprint mode: {$blueprint->mode()}"),
        };
    }

    /**
     * @return array<int, string>
     */
    public function compileDrop(string $table, bool $ifExists): array
    {
        $prefix = $ifExists ? 'DROP TABLE IF EXISTS ' : 'DROP TABLE ';
        return [$prefix . $this->wrapTable($table)];
    }

    /**
     * @return array<int, string>
     */
    public function compileRename(string $from, string $to): array
    {
        return ["ALTER TABLE {$this->wrapTable($from)} RENAME TO {$this->wrapTable($to)}"];
    }

    /**
     * @return array<int, string>
     */
    protected function compileCreate(Blueprint $blueprint): array
    {
        $columns = [];
        $constraints = [];
        $indexes = [];

        foreach ($blueprint->columns() as $column) {
            $columns[] = $this->compileColumn($column, true);

            if ($column->hasForeign()) {
                $constraints[] = $this->compileForeignConstraint($blueprint->table(), $column);
            }

            foreach ($this->compileIndexes($blueprint->table(), $column) as $statement) {
                $indexes[] = $statement;
            }
        }

        $body = implode(",\n    ", array_merge($columns, $constraints));
        $create = "CREATE TABLE {$this->wrapTable($blueprint->table())} (\n    {$body}\n)";
        $suffix = $this->compileCreateTableSuffix();

        if ($suffix !== '') {
            $create .= ' ' . $suffix;
        }

        return array_merge([$create], $indexes);
    }

    /**
     * @return array<int, string>
     */
    protected function compileTable(Blueprint $blueprint): array
    {
        $statements = [];

        // Process column additions
        foreach ($blueprint->columns() as $column) {
            if ($column->hasForeign()) {
                throw new RuntimeException('Adding foreign keys to existing tables is not supported in Database v2.');
            }

            if ($column->isPrimary()) {
                throw new RuntimeException('Adding primary keys through Schema::table() is not supported in Database v2.');
            }

            $definition = $this->compileColumn($column, false);
            $addSql = "ALTER TABLE {$this->wrapTable($blueprint->table())} ADD COLUMN {$definition}";

            // MySQL AFTER clause
            if ($column->hasAfter()) {
                $addSql .= ' AFTER ' . $this->wrapColumn($column->afterColumn());
            }

            $statements[] = $addSql;

            foreach ($this->compileIndexes($blueprint->table(), $column) as $statement) {
                $statements[] = $statement;
            }
        }

        // Process DDL operations (drop, rename, drop index, etc.)
        foreach ($blueprint->operations() as $op) {
            $statements = array_merge($statements, $this->compileOperation($blueprint->table(), $op));
        }

        return $statements;
    }

    /**
     * @return array<int, string>
     */
    protected function compileOperation(string $table, array $op): array
    {
        return match ($op['type']) {
            'dropColumn'    => $this->compileDropColumn($table, $op['data']['column']),
            'renameColumn'  => $this->compileRenameColumn($table, $op['data']['from'], $op['data']['to']),
            'dropIndex'     => $this->compileDropIndex($op['data']['name']),
            'dropForeign'   => $this->compileDropForeign($table, $op['data']['name']),
            default         => throw new RuntimeException("Unsupported operation: {$op['type']}"),
        };
    }

    /** @return array<int, string> */
    protected function compileDropColumn(string $table, string $column): array
    {
        return ["ALTER TABLE {$this->wrapTable($table)} DROP COLUMN {$this->wrapColumn($column)}"];
    }

    /** @return array<int, string> */
    protected function compileRenameColumn(string $table, string $from, string $to): array
    {
        return ["ALTER TABLE {$this->wrapTable($table)} RENAME COLUMN {$this->wrapColumn($from)} TO {$this->wrapColumn($to)}"];
    }

    /** @return array<int, string> */
    protected function compileDropIndex(string $indexName): array
    {
        return ["DROP INDEX {$this->wrapIdentifier($indexName)}"];
    }

    /** @return array<int, string> */
    protected function compileDropForeign(string $table, string $constraintName): array
    {
        return ["ALTER TABLE {$this->wrapTable($table)} DROP CONSTRAINT {$this->wrapIdentifier($constraintName)}"];
    }

    protected function compileColumn(ColumnDefinition $column, bool $allowPrimary): string
    {
        if ($column->type() === 'id') {
            return $this->compileIdColumn($column);
        }

        $sql = $this->wrapColumn($column->name()) . ' ' . $this->columnType($column);

        if (!$column->isNullable()) {
            $sql .= ' NOT NULL';
        }

        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . $this->quoteValue($column->defaultValue());
        }

        if ($allowPrimary && $column->isPrimary()) {
            $sql .= ' PRIMARY KEY';
        }

        return $sql;
    }

    protected function compileIdColumn(ColumnDefinition $column): string
    {
        return $this->wrapColumn($column->name()) . ' ' . $this->idColumnSql();
    }

    protected function compileForeignConstraint(string $table, ColumnDefinition $column): string
    {
        $sql = 'CONSTRAINT ' . $this->wrapIdentifier((string) $column->foreignName($table))
            . ' FOREIGN KEY (' . $this->wrapColumn($column->name()) . ')'
            . ' REFERENCES ' . $this->wrapTable((string) $column->onTable())
            . ' (' . $this->wrapColumn((string) $column->referencesColumn()) . ')';

        if ($column->onDeleteAction()) {
            $sql .= ' ON DELETE ' . $column->onDeleteAction();
        }

        if ($column->onUpdateAction()) {
            $sql .= ' ON UPDATE ' . $column->onUpdateAction();
        }

        return $sql;
    }

    /**
     * @return array<int, string>
     */
    protected function compileIndexes(string $table, ColumnDefinition $column): array
    {
        $statements = [];

        if ($column->hasUnique()) {
            $statements[] = 'CREATE UNIQUE INDEX '
                . $this->wrapIdentifier((string) $column->uniqueName($table))
                . ' ON ' . $this->wrapTable($table)
                . ' (' . $this->wrapColumn($column->name()) . ')';
        }

        if ($column->hasIndex()) {
            $statements[] = 'CREATE INDEX '
                . $this->wrapIdentifier((string) $column->indexName($table))
                . ' ON ' . $this->wrapTable($table)
                . ' (' . $this->wrapColumn($column->name()) . ')';
        }

        return $statements;
    }

    protected function compileCreateTableSuffix(): string
    {
        return '';
    }

    protected function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? $this->booleanTrue() : $this->booleanFalse();
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    protected function booleanTrue(): string
    {
        return '1';
    }

    protected function booleanFalse(): string
    {
        return '0';
    }

    protected function wrapTable(string $table): string
    {
        return $this->wrapIdentifier($table);
    }

    protected function wrapColumn(string $column): string
    {
        return $this->wrapIdentifier($column);
    }

    abstract protected function wrapIdentifier(string $value): string;

    abstract protected function idColumnSql(): string;

    abstract protected function columnType(ColumnDefinition $column): string;
}

class MySqlSchemaGrammar extends SchemaGrammar
{
    protected function wrapIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    protected function idColumnSql(): string
    {
        return 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'string'      => 'VARCHAR(' . ($column->attributes()['length'] ?? 255) . ')',
            'text'        => 'TEXT',
            'mediumText'  => 'MEDIUMTEXT',
            'longText'    => 'LONGTEXT',
            'boolean'     => 'BOOLEAN',
            'tinyInteger' => 'TINYINT' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'smallInteger'=> 'SMALLINT' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'integer'     => 'INT' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'bigInteger'  => 'BIGINT' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'float'       => 'FLOAT(' . ($column->attributes()['precision'] ?? 8) . ', ' . ($column->attributes()['scale'] ?? 2) . ')' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'decimal'     => 'DECIMAL(' . ($column->attributes()['precision'] ?? 10) . ', ' . ($column->attributes()['scale'] ?? 2) . ')' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'enum'        => "ENUM('" . implode("','", $column->attributes()['values'] ?? []) . "')",
            'json'        => 'JSON',
            'date'        => 'DATE',
            'datetime'    => 'DATETIME',
            'timestamp'   => 'TIMESTAMP',
            'uuid'        => 'CHAR(36)',
            'binary'      => 'BLOB',
            'foreignId'   => 'BIGINT UNSIGNED',
            default       => throw new RuntimeException("Unsupported column type [{$column->type()}] for mysql"),
        };
    }

    protected function compileDropForeign(string $table, string $constraintName): array
    {
        // MySQL uses DROP FOREIGN KEY instead of DROP CONSTRAINT
        return ["ALTER TABLE {$this->wrapTable($table)} DROP FOREIGN KEY {$this->wrapIdentifier($constraintName)}"];
    }

    protected function compileCreateTableSuffix(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }
}

class PgsqlSchemaGrammar extends SchemaGrammar
{
    protected function wrapIdentifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    protected function idColumnSql(): string
    {
        return 'BIGSERIAL PRIMARY KEY';
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'string'      => 'VARCHAR(' . ($column->attributes()['length'] ?? 255) . ')',
            'text'        => 'TEXT',
            'mediumText'  => 'TEXT',
            'longText'    => 'TEXT',
            'boolean'     => 'BOOLEAN',
            'tinyInteger' => 'SMALLINT',
            'smallInteger'=> 'SMALLINT',
            'integer'     => 'INTEGER',
            'bigInteger'  => 'BIGINT',
            'float'       => 'DOUBLE PRECISION',
            'decimal'     => 'NUMERIC(' . ($column->attributes()['precision'] ?? 10) . ', ' . ($column->attributes()['scale'] ?? 2) . ')',
            'enum'        => 'VARCHAR(255)',
            'json'        => 'JSONB',
            'date'        => 'DATE',
            'datetime'    => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'timestamp'   => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'uuid'        => 'UUID',
            'binary'      => 'BYTEA',
            'foreignId'   => 'BIGINT',
            default       => throw new RuntimeException("Unsupported column type [{$column->type()}] for pgsql"),
        };
    }

    protected function booleanTrue(): string
    {
        return 'TRUE';
    }

    protected function booleanFalse(): string
    {
        return 'FALSE';
    }
}

class SqliteSchemaGrammar extends SchemaGrammar
{
    protected function wrapIdentifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    protected function idColumnSql(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'string'      => 'VARCHAR(' . ($column->attributes()['length'] ?? 255) . ')',
            'text'        => 'TEXT',
            'mediumText'  => 'TEXT',
            'longText'    => 'TEXT',
            'boolean'     => 'INTEGER',
            'tinyInteger' => 'INTEGER',
            'smallInteger'=> 'INTEGER',
            'integer'     => 'INTEGER',
            'bigInteger'  => 'BIGINT',
            'float'       => 'REAL',
            'decimal'     => 'NUMERIC(' . ($column->attributes()['precision'] ?? 10) . ', ' . ($column->attributes()['scale'] ?? 2) . ')',
            'enum'        => 'TEXT',
            'json'        => 'TEXT',
            'date'        => 'TEXT',
            'datetime'    => 'TEXT',
            'timestamp'   => 'TEXT',
            'uuid'        => 'TEXT',
            'binary'      => 'BLOB',
            'foreignId'   => 'INTEGER',
            default       => throw new RuntimeException("Unsupported column type [{$column->type()}] for sqlite"),
        };
    }
}
