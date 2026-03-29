<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SparkCliDatabaseTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        Database::reset();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-cli-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0777, true);

        $this->copyDirectory(__DIR__ . '/../../core', $this->basePath . '/core');
        copy(__DIR__ . '/../../spark', $this->basePath . '/spark');
        copy(__DIR__ . '/../../VERSION', $this->basePath . '/VERSION');
        chmod($this->basePath . '/spark', 0755);

        file_put_contents($this->basePath . '/.env.example', <<<'ENV'
APP_NAME=SparkPHP
APP_ENV=dev
APP_KEY=change-me-to-a-random-secret-32-chars
DB=sqlite
DB_NAME=
ENV
        );

        $scaffolder = new ProjectScaffolder($this->basePath);
        $scaffolder->initialize();

        $databasePath = $this->basePath . '/database.sqlite';
        $env = <<<'ENV'
APP_NAME=SparkPHP
APP_ENV=dev
APP_KEY=test-key-1234567890123456789012
DB=sqlite
DB_NAME=%s
ENV;
        file_put_contents($this->basePath . '/.env', sprintf($env, $databasePath));
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testMakeCommandsGenerateClassBasedMigrationAndSeeder(): void
    {
        $makeMigration = $this->runSpark(['make:migration', 'create_posts_table']);
        $makeSeeder = $this->runSpark(['make:seeder', 'UserSeeder']);

        $migrationFiles = glob($this->basePath . '/database/migrations/*_create_posts_table.php') ?: [];

        $this->assertSame(0, $makeMigration['exit_code'], $makeMigration['output']);
        $this->assertSame(0, $makeSeeder['exit_code'], $makeSeeder['output']);
        $this->assertCount(1, $migrationFiles);
        $this->assertStringContainsString('extends Migration', (string) file_get_contents($migrationFiles[0]));
        $this->assertFileExists($this->basePath . '/database/seeds/UserSeeder.php');
        $this->assertStringContainsString('extends Seeder', (string) file_get_contents($this->basePath . '/database/seeds/UserSeeder.php'));
    }

    public function testMigrateSeedStatusRollbackAndFreshWorkEndToEnd(): void
    {
        file_put_contents($this->basePath . '/database/migrations/20260327010101_create_users_table.php', <<<'PHP'
<?php

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
PHP
        );

        file_put_contents($this->basePath . '/database/migrations/20260327010102_create_posts_table.php', <<<'PHP'
<?php

class CreatePostsTable extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
}
PHP
        );

        file_put_contents($this->basePath . '/database/seeds/DatabaseSeeder.php', <<<'PHP'
<?php

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
    }
}
PHP
        );

        file_put_contents($this->basePath . '/database/seeds/UserSeeder.php', <<<'PHP'
<?php

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $count = (int) (db()->raw('SELECT COUNT(*) AS aggregate_count FROM users')[0]->aggregate_count ?? 0);

        db()->statement(
            'INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Spark', 'spark' . ($count + 1) . '@example.com', '2026-03-27 00:00:00', '2026-03-27 00:00:00']
        );
    }
}
PHP
        );

        $migrate = $this->runSpark(['migrate', '--seed']);
        $statusAfterMigrate = $this->runSpark(['migrate:status']);
        $seedSingle = $this->runSpark(['seed', 'UserSeeder']);

        $pdo = $this->sqlitePdo();
        $userCountAfterSeed = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $rollback = $this->runSpark(['migrate:rollback', '1']);
        $statusAfterRollback = $this->runSpark(['migrate:status']);

        $fresh = $this->runSpark(['db:fresh', '--seed']);
        $userCountAfterFresh = (int) $this->sqlitePdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $migrationCount = (int) $this->sqlitePdo()->query('SELECT COUNT(*) FROM spark_migrations')->fetchColumn();

        $this->assertSame(0, $migrate['exit_code'], $migrate['output']);
        $this->assertStringContainsString('migration(s) complete', $migrate['output']);
        $this->assertStringContainsString('CreateUsersTable', $statusAfterMigrate['output']);
        $this->assertStringContainsString('CreatePostsTable', $statusAfterMigrate['output']);
        $this->assertSame(0, $seedSingle['exit_code'], $seedSingle['output']);
        $this->assertSame(2, $userCountAfterSeed);
        $this->assertSame(0, $rollback['exit_code'], $rollback['output']);
        $this->assertStringContainsString('Pending', $statusAfterRollback['output']);
        $this->assertSame(0, $fresh['exit_code'], $fresh['output']);
        $this->assertSame(1, $userCountAfterFresh);
        $this->assertSame(2, $migrationCount);
    }

    public function testLegacyMigrationsFailWithHelpfulMessage(): void
    {
        file_put_contents($this->basePath . '/database/migrations/20260327020202_legacy_users.php', <<<'PHP'
<?php

up(function () {
    db()->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
});

down(function () {
    db()->statement('DROP TABLE IF EXISTS users');
});
PHP
        );

        $result = $this->runSpark(['migrate']);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('Legacy migration format detected', $result['output']);
    }

    public function testAboutCommandShowsEnvironmentAndDatabaseDiagnostics(): void
    {
        file_put_contents($this->basePath . '/database/migrations/20260327040404_create_users_table.php', <<<'PHP'
<?php

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
PHP
        );

        $this->runSpark(['migrate']);
        $result = $this->runSpark(['about']);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('SparkPHP', $result['output']);
        $this->assertStringContainsString('environment report', $result['output']);
        $this->assertStringContainsString('Application', $result['output']);
        $this->assertStringContainsString('Database', $result['output']);
        $this->assertStringContainsString(trim((string) file_get_contents(__DIR__ . '/../../VERSION')), $result['output']);
        $this->assertStringContainsString('sqlite', $result['output']);
        $this->assertStringContainsString('connected', $result['output']);
        $this->assertStringContainsString('Pending', $result['output']);
    }

    public function testVersionCommandReadsVersionFileAndReleaseLine(): void
    {
        $result = $this->runSpark(['version']);
        $version = trim((string) file_get_contents(__DIR__ . '/../../VERSION'));

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('SparkPHP v' . $version, $result['output']);
        $this->assertStringContainsString(SparkVersion::releaseLine($version), $result['output']);
    }

    public function testServeCommandShowsVersionInBanner(): void
    {
        $result = $this->runSpark(['serve', '--port=8123', '--dry-run']);
        $version = trim((string) file_get_contents(__DIR__ . '/../../VERSION'));

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('SparkPHP v' . $version, $result['output']);
        $this->assertStringContainsString('http://localhost:8123', $result['output']);
        $this->assertStringContainsString('Press Ctrl+C to stop.', $result['output']);
    }

    public function testBenchmarkCommandOutputsVersionedRealProjectSuite(): void
    {
        $result = $this->runSpark(['benchmark', '--json', '--iterations=1', '--warmup=0', '--no-save']);
        $payload = json_decode($result['output'], true);
        $version = trim((string) file_get_contents(__DIR__ . '/../../VERSION'));

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertIsArray($payload);
        $this->assertSame($version, $payload['spark_version']);
        $this->assertSame(SparkVersion::releaseLine($version), $payload['spark_release_line']);
        $this->assertSame('real_project_fixture', $payload['profile']['name']);
        $this->assertContains('http.request_html', array_column($payload['scenarios'], 'name'));
        $this->assertContains('http.request_json', array_column($payload['scenarios'], 'name'));
    }

    public function testAiCommandsExposeDiagnosticsAndSmokeTests(): void
    {
        $status = $this->runSpark(['ai:status', '--json']);
        $smoke = $this->runSpark(['ai:smoke-test', '--json']);
        $retrieval = $this->runSpark(['ai:smoke-test', '--capability=retrieval', '--json']);

        $statusPayload = json_decode($status['output'], true);
        $smokePayload = json_decode($smoke['output'], true);
        $retrievalPayload = json_decode($retrieval['output'], true);
        $version = trim((string) file_get_contents(__DIR__ . '/../../VERSION'));

        $this->assertSame(0, $status['exit_code'], $status['output']);
        $this->assertIsArray($statusPayload);
        $this->assertSame($version, $statusPayload['spark_version']);
        $this->assertSame('fake', $statusPayload['driver']);
        $this->assertSame('fake', $statusPayload['provider']);
        $this->assertArrayHasKey('inspector', $statusPayload);
        $this->assertArrayHasKey('ai_preview', $statusPayload['inspector']);

        $this->assertSame(0, $smoke['exit_code'], $smoke['output']);
        $this->assertIsArray($smokePayload);
        $this->assertSame($version, $smokePayload['spark_version']);
        $this->assertSame('fake', $smokePayload['driver']);
        $this->assertSame('fake', $smokePayload['provider']);
        $this->assertSame('ok', $smokePayload['capabilities']['text']['status']);
        $this->assertSame('ok', $smokePayload['capabilities']['agent']['status']);
        $this->assertGreaterThan(0, $smokePayload['capabilities']['text']['tokens']['total']);

        $this->assertSame(0, $retrieval['exit_code'], $retrieval['output']);
        $this->assertIsArray($retrievalPayload);
        $this->assertSame('ok', $retrievalPayload['capabilities']['retrieval']['status']);
    }

    public function testApiSpecCommandGeneratesOpenApiFromRoutesValidationAndResponses(): void
    {
        mkdir($this->basePath . '/app/routes/api', 0777, true);

        file_put_contents($this->basePath . '/app/models/ApiUser.php', <<<'PHP'
<?php

#[Hidden('email')]
#[Rename('name', 'display_name')]
class ApiUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'active', 'role'];
    protected array $casts = ['active' => 'bool'];
    protected bool $timestamps = false;
}
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/users.php', <<<'PHP'
<?php
get(fn() => ApiUser::query()->paginate(15))->guard('auth');

post(function () {
    $data = validate([
        'name' => 'required|string|min:3|max:80',
        'email' => 'required|email',
        'active' => 'bool',
        'role' => 'in:admin,editor,user',
    ]);

    return ApiUser::create($data);
})->guard('auth');
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/users.[id].php', <<<'PHP'
<?php
get(fn(ApiUser $user) => $user)->guard('auth');
PHP
        );

        $result = $this->runSpark(['api:spec']);
        $specPath = $this->basePath . '/storage/api/openapi.json';
        $spec = json_decode((string) file_get_contents($specPath), true);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertFileExists($specPath);
        $this->assertStringContainsString('OpenAPI spec generated', $result['output']);
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(trim((string) file_get_contents(__DIR__ . '/../../VERSION')), $spec['info']['version']);
        $this->assertArrayHasKey('/api/users', $spec['paths']);
        $this->assertArrayHasKey('/api/users/{id}', $spec['paths']);
        $this->assertSame([['sessionAuth' => []]], $spec['paths']['/api/users']['get']['security']);
        $this->assertSame(['$ref' => '#/components/schemas/ApiUser'], $spec['paths']['/api/users/{id}']['get']['responses']['200']['content']['application/json']['schema']);
        $this->assertSame(['$ref' => '#/components/schemas/ApiUser'], $spec['paths']['/api/users']['post']['responses']['201']['content']['application/json']['schema']);
        $this->assertSame('boolean', $spec['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema']['properties']['active']['type']);
        $this->assertSame(['admin', 'editor', 'user'], $spec['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema']['properties']['role']['enum']);
        $this->assertSame(['name', 'email'], $spec['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema']['required']);
        $this->assertArrayHasKey('data', $spec['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['properties']);
        $this->assertArrayHasKey('links', $spec['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['properties']);
        $this->assertArrayHasKey('meta', $spec['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['properties']);
        $this->assertArrayHasKey('display_name', $spec['components']['schemas']['ApiUser']['properties']);
        $this->assertArrayNotHasKey('email', $spec['components']['schemas']['ApiUser']['properties']);
        $this->assertSame('integer', $spec['paths']['/api/users/{id}']['get']['parameters'][0]['schema']['type']);
    }

    public function testQueueCommandsSupportRoutingInspectRetryAndSelectiveClear(): void
    {
        mkdir($this->basePath . '/app/jobs', 0777, true);

        file_put_contents($this->basePath . '/app/jobs/_queue.php', <<<'PHP'
<?php

return [
    'routes' => [
        CliQueueJob::class => [
            'queue' => 'emails',
            'tries' => 4,
            'backoff' => [0],
        ],
        CliFailingQueueJob::class => [
            'tries' => 2,
            'backoff' => [0],
        ],
    ],
];
PHP
        );

        file_put_contents($this->basePath . '/app/jobs/CliQueueJob.php', <<<'PHP'
<?php

class CliQueueJob
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        file_put_contents(base_path('storage/logs/cli-queue.log'), json_encode($this->data) . PHP_EOL, FILE_APPEND);
    }
}
PHP
        );

        file_put_contents($this->basePath . '/app/jobs/CliFailingQueueJob.php', <<<'PHP'
<?php

class CliFailingQueueJob
{
    public int $tries = 2;
    public array $backoff = [0];

    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        throw new RuntimeException('CLI queue failed');
    }
}
PHP
        );

        $queue = new Queue($this->basePath);
        $queue->push('CliQueueJob', ['batch' => 'emails']);
        $failedId = $queue->push('CliFailingQueueJob', ['batch' => 'default']);

        $list = $this->runSpark(['queue:list']);
        $workEmails = $this->runSpark(['queue:work', '--queue=emails', '--sleep=0', '--max-jobs=1']);
        $workDefault = $this->runSpark(['queue:work', '--queue=default', '--sleep=0', '--max-jobs=2']);
        $inspect = $this->runSpark(['queue:inspect', $failedId, '--queue=failed']);
        $retry = $this->runSpark(['queue:retry', $failedId]);
        $clear = $this->runSpark(['queue:clear', 'default', '--job=CliFailingQueueJob']);

        $this->assertSame(0, $list['exit_code'], $list['output']);
        $this->assertStringContainsString('emails', $list['output']);
        $this->assertStringContainsString('default', $list['output']);

        $this->assertSame(0, $workEmails['exit_code'], $workEmails['output']);
        $this->assertStringContainsString('Processed CliQueueJob', $workEmails['output']);

        $this->assertSame(0, $workDefault['exit_code'], $workDefault['output']);
        $this->assertStringContainsString('Released CliFailingQueueJob', $workDefault['output']);
        $this->assertStringContainsString('Failed CliFailingQueueJob', $workDefault['output']);

        $this->assertSame(0, $inspect['exit_code'], $inspect['output']);
        $this->assertStringContainsString('CliFailingQueueJob', $inspect['output']);
        $this->assertStringContainsString('CLI queue failed', $inspect['output']);

        $this->assertSame(0, $retry['exit_code'], $retry['output']);
        $this->assertStringContainsString('Retried job', $retry['output']);

        $this->assertSame(0, $clear['exit_code'], $clear['output']);
        $this->assertStringContainsString('1 job(s) removed', $clear['output']);
    }

    /**
     * @dataProvider externalDriverProvider
     */
    public function testExternalDriversCanRunMigrationsWhenConfigured(string $driver, array $config): void
    {
        foreach ($config as $key => $value) {
            if ($value === '') {
                $this->markTestSkipped("External {$driver} test is not configured.");
            }
        }

        file_put_contents($this->basePath . '/.env', sprintf(
            "APP_NAME=SparkPHP\nAPP_ENV=dev\nAPP_KEY=test-key-1234567890123456789012\nDB=%s\nDB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n",
            $driver,
            $config['host'],
            $config['port'],
            $config['database'],
            $config['user'],
            $config['pass']
        ));

        file_put_contents($this->basePath . '/database/migrations/20260327030303_create_users_table.php', <<<'PHP'
<?php

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
PHP
        );

        $pdo = $this->externalPdo($driver, $config);
        $this->dropExternalTables($driver, $pdo, ['spark_migrations', 'users']);

        $migrate = $this->runSpark(['migrate']);
        $status = $this->runSpark(['migrate:status']);

        $this->assertSame(0, $migrate['exit_code'], $migrate['output']);
        $this->assertStringContainsString('CreateUsersTable', $status['output']);

        $this->dropExternalTables($driver, $pdo, ['spark_migrations', 'users']);
    }

    public static function externalDriverProvider(): array
    {
        return [
            'mysql' => [
                'mysql',
                [
                    'host' => (string) getenv('SPARK_TEST_MYSQL_HOST'),
                    'port' => (string) getenv('SPARK_TEST_MYSQL_PORT'),
                    'database' => (string) getenv('SPARK_TEST_MYSQL_DATABASE'),
                    'user' => (string) getenv('SPARK_TEST_MYSQL_USER'),
                    'pass' => (string) getenv('SPARK_TEST_MYSQL_PASS'),
                ],
            ],
            'pgsql' => [
                'pgsql',
                [
                    'host' => (string) getenv('SPARK_TEST_PGSQL_HOST'),
                    'port' => (string) getenv('SPARK_TEST_PGSQL_PORT'),
                    'database' => (string) getenv('SPARK_TEST_PGSQL_DATABASE'),
                    'user' => (string) getenv('SPARK_TEST_PGSQL_USER'),
                    'pass' => (string) getenv('SPARK_TEST_PGSQL_PASS'),
                ],
            ],
        ];
    }

    private function sqlitePdo(): PDO
    {
        return new PDO('sqlite:' . $this->basePath . '/database.sqlite');
    }

    private function externalPdo(string $driver, array $config): PDO
    {
        $dsn = match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}]"),
        };

        return new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function dropExternalTables(string $driver, PDO $pdo, array $tables): void
    {
        foreach ($tables as $table) {
            if ($driver === 'mysql') {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                continue;
            }

            $pdo->exec("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
        }
    }

    private function runSpark(array $args): array
    {
        $command = array_merge(['php', 'spark'], $args);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->basePath);
        if (!is_resource($process)) {
            $this->fail('Unable to start spark process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'output' => trim($stdout . "\n" . $stderr),
        ];
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $items->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
                continue;
            }

            copy($item->getPathname(), $target);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
