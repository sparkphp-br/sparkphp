<?php

declare(strict_types=1);

function sparkBootDatabaseCore(): void
{
    require_once SPARK_BASE . '/core/Database.php';
    require_once SPARK_BASE . '/core/Schema.php';
    require_once SPARK_BASE . '/core/Migration.php';
    require_once SPARK_BASE . '/core/Seeder.php';
}

function sparkMigrationTable(): string
{
    return 'spark_migrations';
}

function sparkMigrationFiles(): array
{
    $dir = SPARK_BASE . '/database/migrations';
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.php') ?: [];
    sort($files);
    return $files;
}

function sparkMigrationRepositoryExists(): bool
{
    sparkBootDatabaseCore();
    $db = db();
    $table = sparkMigrationTable();

    return match ($db->driver()) {
        'mysql' => (int) (($db->raw(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
            [$_ENV['DB_NAME'] ?? '', $table]
        )[0]->cnt ?? 0)) > 0,
        'pgsql' => (int) (($db->raw(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?",
            [$table]
        )[0]->cnt ?? 0)) > 0,
        'sqlite' => (int) (($db->raw(
            "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        )[0]->cnt ?? 0)) > 0,
        default => false,
    };
}

function sparkEnsureMigrationRepository(): void
{
    if (sparkMigrationRepositoryExists()) {
        return;
    }

    Schema::create(sparkMigrationTable(), function (Blueprint $table) {
        $table->id();
        $table->string('migration')->unique();
        $table->integer('batch');
        $table->datetime('ran_at');
    });
}

function sparkMigrationRecords(): array
{
    sparkBootDatabaseCore();

    if (!sparkMigrationRepositoryExists()) {
        return [];
    }

    $rows = db()->raw(
        'SELECT migration, batch, ran_at FROM ' . sparkMigrationTable() . ' ORDER BY batch ASC, migration ASC'
    );

    return array_map(fn(object $row) => (array) $row, $rows);
}

function sparkInsertMigrationRecord(string $migration, int $batch): void
{
    db()->statement(
        'INSERT INTO ' . sparkMigrationTable() . ' (migration, batch, ran_at) VALUES (?, ?, ?)',
        [$migration, $batch, date('Y-m-d H:i:s')]
    );
}

function sparkDeleteMigrationRecord(string $migration): void
{
    db()->statement(
        'DELETE FROM ' . sparkMigrationTable() . ' WHERE migration = ?',
        [$migration]
    );
}

function sparkMigrationBatchMax(): int
{
    $records = sparkMigrationRecords();
    if ($records === []) {
        return 0;
    }

    return (int) max(array_column($records, 'batch'));
}

function sparkResolveMigrationClassName(string $file): string
{
    $contents = (string) file_get_contents($file);
    if (!preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+Migration\b/', $contents, $matches)) {
        throw new RuntimeException(
            'Legacy migration format detected in ' . basename($file) . '. Use a class that extends Migration with up()/down().'
        );
    }

    return $matches[1];
}

function sparkMigrationClassLabel(string $file): string
{
    $contents = (string) file_get_contents($file);
    if (!preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+Migration\b/', $contents, $matches)) {
        return 'legacy-format';
    }

    return $matches[1];
}

function sparkInstantiateMigration(string $file): Migration
{
    sparkBootDatabaseCore();

    $class = sparkResolveMigrationClassName($file);
    require_once $file;

    if (!class_exists($class)) {
        throw new RuntimeException("Migration class [{$class}] not found in " . basename($file));
    }

    $migration = new $class();
    if (!$migration instanceof Migration) {
        throw new RuntimeException("Migration class [{$class}] must extend Migration.");
    }

    return $migration;
}

function sparkRunMigrationFile(string $file, string $direction = 'up'): void
{
    $migration = sparkInstantiateMigration($file);
    $method = $direction === 'down' ? 'down' : 'up';
    $migration->{$method}();
}

function sparkMigrate(array $args = []): void
{
    $shouldSeed = in_array('--seed', $args, true);
    $files = sparkMigrationFiles();

    sparkBootDatabaseCore();
    sparkEnsureMigrationRepository();

    if ($files === []) {
        out(color('  No migrations found.', 'yellow'));
        if ($shouldSeed) {
            sparkSeed();
        }
        return;
    }

    $ran = array_column(sparkMigrationRecords(), null, 'migration');
    $pending = array_values(array_filter($files, fn(string $file) => !isset($ran[basename($file)])));

    if ($pending === []) {
        out(color('  Nothing to migrate.', 'dim'));
        if ($shouldSeed) {
            sparkSeed();
        }
        return;
    }

    $batch = sparkMigrationBatchMax() + 1;

    foreach ($pending as $file) {
        $name = basename($file);
        out('  ' . color('Migrating ', 'dim') . color($name, 'cyan'));
        sparkRunMigrationFile($file, 'up');
        sparkInsertMigrationRecord($name, $batch);
    }

    success("Batch {$batch} — " . count($pending) . ' migration(s) complete.');

    if ($shouldSeed) {
        sparkSeed();
    }
}

function sparkMigrateRollback(int $batches = 1): void
{
    sparkBootDatabaseCore();

    $records = sparkMigrationRecords();
    if ($records === []) {
        out(color('  Nothing to rollback.', 'dim'));
        return;
    }

    $maxBatch = sparkMigrationBatchMax();
    $minBatch = max(1, $maxBatch - max(1, $batches) + 1);
    $toRollback = array_values(array_filter($records, fn(array $record) => (int) $record['batch'] >= $minBatch));
    usort($toRollback, function (array $a, array $b): int {
        if ((int) $a['batch'] === (int) $b['batch']) {
            return strcmp((string) $b['migration'], (string) $a['migration']);
        }

        return (int) $b['batch'] <=> (int) $a['batch'];
    });

    foreach ($toRollback as $record) {
        $file = SPARK_BASE . '/database/migrations/' . $record['migration'];
        if (!file_exists($file)) {
            throw new RuntimeException("Migration file not found for rollback: {$record['migration']}");
        }

        out('  ' . color('Rolling back ', 'dim') . color($record['migration'], 'yellow'));
        sparkRunMigrationFile($file, 'down');
        sparkDeleteMigrationRecord((string) $record['migration']);
    }

    success('Rolled back ' . count($toRollback) . ' migration(s).');
}

function sparkDropAllTables(): void
{
    sparkBootDatabaseCore();

    $db = db();
    $pdo = $db->pdo();
    $driver = $db->driver();

    if ($driver === 'mysql') {
        $databaseName = $_ENV['DB_NAME'] ?? '';
        $rows = $db->raw(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$databaseName]
        );

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($rows as $row) {
            $pdo->exec('DROP TABLE IF EXISTS ' . sparkWrapIdentifier($driver, $row->table_name));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return;
    }

    if ($driver === 'pgsql') {
        $rows = $db->raw("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()");

        foreach ($rows as $row) {
            $pdo->exec('DROP TABLE IF EXISTS ' . sparkWrapIdentifier($driver, $row->tablename) . ' CASCADE');
        }
        return;
    }

    if ($driver === 'sqlite') {
        $rows = $db->raw(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );

        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($rows as $row) {
            $pdo->exec('DROP TABLE IF EXISTS ' . sparkWrapIdentifier($driver, $row->name));
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function sparkDbFresh(array $args = []): void
{
    $shouldSeed = in_array('--seed', $args, true);

    sparkBootDatabaseCore();

    out(color('  Dropping all tables...', 'yellow'));
    sparkDropAllTables();

    out(color('  Re-running all migrations...', 'cyan'));
    sparkMigrate($shouldSeed ? ['--seed'] : []);
}

function sparkMigrateStatus(): void
{
    $files = sparkMigrationFiles();
    $ran = array_column(sparkMigrationRecords(), null, 'migration');

    echo "\n";
    out('  ' . color(str_pad('Status', 14), 'yellow')
        . color(str_pad('Batch', 8), 'yellow')
        . color(str_pad('File', 40), 'yellow')
        . color(str_pad('Class', 28), 'yellow')
        . color('Ran At', 'yellow'));
    out('  ' . color(str_repeat('─', 112), 'dim'));

    foreach ($files as $file) {
        $name = basename($file);
        $record = $ran[$name] ?? null;
        $status = $record ? color(str_pad('Ran', 14), 'green') : color(str_pad('Pending', 14), 'yellow');
        $batch = str_pad((string) ($record['batch'] ?? '—'), 8);
        $ranAt = $record['ran_at'] ?? '—';
        $class = sparkMigrationClassLabel($file);

        out('  ' . $status
            . color($batch, 'dim')
            . str_pad($name, 40)
            . str_pad($class, 28)
            . color($ranAt, 'dim'));
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Database inspection commands
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Show database overview: all tables with column count and row count.
 *
 * Usage: php spark db:show
 */
function sparkDbShow(): void
{
    sparkBootDatabaseCore();

    $db     = db();
    $driver = $db->driver();
    $dbName = $_ENV['DB_NAME'] ?? $_ENV['DB_DATABASE'] ?? 'unknown';

    echo "\n";
    out(color('  ⚡ Database Overview', 'cyan') . color("  [{$driver}:{$dbName}]", 'dim'));
    echo "\n";

    // Get tables
    $tables = [];
    if ($driver === 'mysql') {
        $rows = $db->raw(
            "SELECT table_name as name, table_rows as row_count "
            . "FROM information_schema.tables "
            . "WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
            [$dbName]
        );
        foreach ($rows as $row) {
            $cols = $db->raw(
                "SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = ? AND table_name = ?",
                [$dbName, $row->name]
            );
            $tables[] = [
                'name' => $row->name,
                'rows' => (int) $row->row_count,
                'cols' => (int) ($cols[0]->cnt ?? 0),
            ];
        }
    } elseif ($driver === 'pgsql') {
        $rows = $db->raw("SELECT tablename as name FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename");
        foreach ($rows as $row) {
            $countRow = $db->raw("SELECT COUNT(*) as cnt FROM " . '"' . $row->name . '"');
            $colsRow  = $db->raw(
                "SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?",
                [$row->name]
            );
            $tables[] = [
                'name' => $row->name,
                'rows' => (int) ($countRow[0]->cnt ?? 0),
                'cols' => (int) ($colsRow[0]->cnt ?? 0),
            ];
        }
    } elseif ($driver === 'sqlite') {
        $rows = $db->raw("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        foreach ($rows as $row) {
            $countRow = $db->raw("SELECT COUNT(*) as cnt FROM \"{$row->name}\"");
            $colsRow  = $db->raw("PRAGMA table_info('{$row->name}')");
            $tables[] = [
                'name' => $row->name,
                'rows' => (int) ($countRow[0]->cnt ?? 0),
                'cols' => count($colsRow),
            ];
        }
    }

    if (empty($tables)) {
        out(color('  No tables found.', 'dim'));
        echo "\n";
        return;
    }

    // Header
    out('  ' . color(str_pad('Table', 40), 'yellow')
        . color(str_pad('Columns', 12), 'yellow')
        . color('Rows', 'yellow'));
    out('  ' . color(str_repeat('─', 60), 'dim'));

    $totalRows = 0;
    foreach ($tables as $t) {
        out('  ' . color(str_pad($t['name'], 40), 'green')
            . str_pad((string) $t['cols'], 12)
            . number_format($t['rows']));
        $totalRows += $t['rows'];
    }

    out('  ' . color(str_repeat('─', 60), 'dim'));
    out('  ' . color(str_pad(count($tables) . ' tables', 40), 'cyan')
        . str_pad('', 12)
        . color(number_format($totalRows) . ' total rows', 'cyan'));
    echo "\n";
}

/**
 * Show the structure of a specific database table.
 *
 * Usage: php spark db:table users
 */
function sparkDbTable(?string $tableName): void
{
    if (!$tableName) {
        error('Please specify a table name: php spark db:table <name>');
        exit(1);
    }

    sparkBootDatabaseCore();

    $db     = db();
    $driver = $db->driver();
    $dbName = $_ENV['DB_NAME'] ?? $_ENV['DB_DATABASE'] ?? '';

    echo "\n";
    out(color("  ⚡ Table: {$tableName}", 'cyan') . color("  [{$driver}]", 'dim'));
    echo "\n";

    // Get columns
    $columns = [];
    if ($driver === 'mysql') {
        $columns = $db->raw(
            "SELECT column_name as name, column_type as type, is_nullable as nullable, "
            . "column_default as `default`, column_key as `key`, extra "
            . "FROM information_schema.columns "
            . "WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position",
            [$dbName, $tableName]
        );
    } elseif ($driver === 'pgsql') {
        $columns = $db->raw(
            "SELECT column_name as name, data_type as type, is_nullable as nullable, "
            . "column_default as default "
            . "FROM information_schema.columns "
            . "WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position",
            [$tableName]
        );
    } elseif ($driver === 'sqlite') {
        $columns = $db->raw("PRAGMA table_info('{$tableName}')");
    }

    if (empty($columns)) {
        error("Table '{$tableName}' not found or has no columns.");
        exit(1);
    }

    // Header
    out('  ' . color(str_pad('Column', 28), 'yellow')
        . color(str_pad('Type', 28), 'yellow')
        . color(str_pad('Nullable', 12), 'yellow')
        . color(str_pad('Default', 18), 'yellow')
        . color('Key', 'yellow'));
    out('  ' . color(str_repeat('─', 96), 'dim'));

    foreach ($columns as $col) {
        $col = (object) (array) $col;

        if ($driver === 'sqlite') {
            $name     = $col->name ?? '';
            $type     = $col->type ?? 'TEXT';
            $nullable = ($col->notnull ?? 0) ? 'NO' : 'YES';
            $default  = $col->dflt_value ?? '—';
            $key      = ($col->pk ?? 0) ? 'PRI' : '';
        } else {
            $name     = $col->name ?? '';
            $type     = $col->type ?? '';
            $nullable = ($col->nullable ?? 'YES');
            $default  = $col->default ?? '—';
            $key      = $col->key ?? '';
        }

        $nullColor = ($nullable === 'YES') ? 'yellow' : 'green';

        out('  ' . color(str_pad($name, 28), 'green')
            . str_pad($type, 28)
            . color(str_pad($nullable, 12), $nullColor)
            . color(str_pad((string) $default, 18), 'dim')
            . ($key ? color($key, 'cyan') : ''));
    }

    // Row count
    try {
        $wrapped = ($driver === 'mysql') ? "`{$tableName}`" : (($driver === 'pgsql') ? '"' . $tableName . '"' : $tableName);
        $countRow = $db->raw("SELECT COUNT(*) as cnt FROM {$wrapped}");
        $rowCount = $countRow[0]->cnt ?? 0;
        echo "\n";
        out('  ' . color(number_format((int) $rowCount) . ' rows', 'cyan'));
    } catch (Throwable) {}

    echo "\n";
}

/**
 * Drop all tables without re-running migrations (complete wipe).
 *
 * Usage: php spark db:wipe
 */
function sparkDbWipe(): void
{
    sparkBootDatabaseCore();

    out(color('  Dropping all tables...', 'yellow'));
    sparkDropAllTables();
    success('All tables dropped successfully.');
}

function sparkAboutDatabaseInfo(): array
{
    $driver = $_ENV['DB'] ?? 'mysql';
    $target = $driver === 'sqlite'
        ? ($_ENV['DB_NAME'] ?? 'sqlite::memory:')
        : sprintf(
            '%s:%s/%s',
            $_ENV['DB_HOST'] ?? 'localhost',
            $_ENV['DB_PORT'] ?? 'default',
            $_ENV['DB_NAME'] ?? 'unknown'
        );

    try {
        $pdo = db()->pdo();
        $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        return [
            'driver' => $driver,
            'target' => $target,
            'connected' => true,
            'server_version' => (string) $serverVersion,
            'error' => 'ok',
        ];
    } catch (Throwable $e) {
        return [
            'driver' => $driver,
            'target' => $target,
            'connected' => false,
            'server_version' => 'unavailable',
            'error' => $e->getMessage(),
        ];
    }
}

function sparkAboutMigrationInfo(bool $dbConnected): array
{
    $files = sparkMigrationFiles();

    if (!$dbConnected) {
        return [
            'summary' => count($files) . ' file(s)',
            'pending' => 'unknown',
            'repository' => 'unreachable',
            'latest_batch' => '—',
        ];
    }

    $repositoryExists = sparkMigrationRepositoryExists();
    $records = $repositoryExists ? sparkMigrationRecords() : [];
    $applied = count($records);
    $pending = max(0, count($files) - $applied);
    $latestBatch = $records === [] ? '—' : (string) sparkMigrationBatchMax();

    return [
        'summary' => $applied . ' applied / ' . count($files) . ' files',
        'pending' => (string) $pending,
        'repository' => $repositoryExists ? 'present' : 'missing',
        'latest_batch' => $latestBatch,
    ];
}

function sparkAboutStorageChecks(): array
{
    return [
        'storage' => sparkIsWritablePath(SPARK_BASE . '/storage'),
        'cache' => sparkIsWritablePath(SPARK_BASE . '/storage/cache'),
        'logs' => sparkIsWritablePath(SPARK_BASE . '/storage/logs'),
        'sessions' => sparkIsWritablePath(SPARK_BASE . '/storage/sessions'),
    ];
}

function sparkAboutSection(string $title, array $rows): void
{
    out('  ' . color($title, 'yellow'));
    foreach ($rows as [$label, $value]) {
        out('    ' . color(str_pad($label, 16) . ' ', 'green') . color((string) $value, 'dim'));
    }
    echo "\n";
}

function sparkAboutExtensions(): string
{
    $extensions = ['pdo', 'mbstring', 'openssl', 'json'];
    $driver = $_ENV['DB'] ?? 'mysql';
    $extensions[] = match ($driver) {
        'sqlite' => 'pdo_sqlite',
        'pgsql' => 'pdo_pgsql',
        default => 'pdo_mysql',
    };

    $statuses = [];
    foreach ($extensions as $extension) {
        $statuses[] = extension_loaded($extension) ? $extension : $extension . ' (missing)';
    }

    return implode(', ', $statuses);
}

function sparkAboutCpuCores(): string
{
    $cores = trim((string) @shell_exec('nproc 2>/dev/null'));
    if ($cores === '') {
        $cores = trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
    }

    if ($cores === '' && getenv('NUMBER_OF_PROCESSORS')) {
        $cores = (string) getenv('NUMBER_OF_PROCESSORS');
    }

    return $cores !== '' ? $cores : 'unknown';
}

function sparkAboutSystemMemory(): string
{
    if (is_readable('/proc/meminfo')) {
        $contents = (string) file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $contents, $matches)) {
            return sparkFormatBytes((int) $matches[1] * 1024);
        }
    }

    $macBytes = trim((string) @shell_exec('sysctl -n hw.memsize 2>/dev/null'));
    if ($macBytes !== '' && ctype_digit($macBytes)) {
        return sparkFormatBytes((int) $macBytes);
    }

    return 'unknown';
}

function sparkAboutLoadAverage(): string
{
    if (!function_exists('sys_getloadavg')) {
        return 'n/a';
    }

    $load = sys_getloadavg();
    if (!is_array($load) || $load === []) {
        return 'n/a';
    }

    return implode(', ', array_map(fn($value) => number_format((float) $value, 2), array_slice($load, 0, 3)));
}

function sparkAboutUptime(): string
{
    if (is_readable('/proc/uptime')) {
        $contents = trim((string) file_get_contents('/proc/uptime'));
        $seconds = (int) (float) strtok($contents, ' ');
        return sparkFormatDuration($seconds);
    }

    $uptime = trim((string) @shell_exec('uptime -p 2>/dev/null'));
    return $uptime !== '' ? $uptime : 'unknown';
}

function sparkAboutPhpMemoryLimit(): string
{
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit === '' || $limit === false) {
        return 'unknown';
    }

    if ($limit === '-1') {
        return 'unlimited';
    }

    $unit = strtolower(substr($limit, -1));
    $value = (int) $limit;
    $multiplier = match ($unit) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };

    return sparkFormatBytes($value * $multiplier);
}

function sparkAboutRouteCount(): string
{
    $routesPath = SPARK_BASE . '/app/routes';
    if (!is_dir($routesPath)) {
        return '0 files';
    }

    $count = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($routesPath, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $count++;
        }
    }

    return $count . ' file(s)';
}

function sparkIsWritablePath(string $path): bool
{
    return is_dir($path) && is_writable($path);
}

function sparkStatusLabel(bool $ok, string $message): string
{
    return ($ok ? '[ok] ' : '[warn] ') . $message;
}

function sparkNormalizeSeederClass(?string $seeder): string
{
    if ($seeder === null || $seeder === '') {
        return 'DatabaseSeeder';
    }

    $candidate = sparkStudly($seeder);
    if (!str_ends_with($candidate, 'Seeder')) {
        $candidate .= 'Seeder';
    }

    return $candidate;
}

function sparkRunSeederClass(string $class): void
{
    sparkBootDatabaseCore();

    $file = SPARK_BASE . '/database/seeds/' . $class . '.php';
    if (!class_exists($class) && file_exists($file)) {
        require_once $file;
    }

    if (!class_exists($class)) {
        throw new RuntimeException("Seeder not found: {$class}");
    }

    $seeder = new $class();
    if (!$seeder instanceof Seeder) {
        throw new RuntimeException("Seeder must extend Seeder: {$class}");
    }

    out('  ' . color('Seeding ', 'dim') . color($class, 'cyan'));
    $seeder->run();
}

function sparkSeed(?string $seeder = null): void
{
    $class = sparkNormalizeSeederClass($seeder);
    sparkRunSeederClass($class);
    success("{$class} executed.");
}

