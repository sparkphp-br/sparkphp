<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectScaffolderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-scaffold-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0777, true);

        file_put_contents($this->basePath . '/.env.example', <<<'ENV'
APP_NAME=SparkPHP
APP_ENV=dev
APP_KEY=change-me-to-a-random-secret-32-chars
AI_DRIVER=fake
SPARK_AI_MASK=true
ENV
        );
        file_put_contents($this->basePath . '/composer.json', '{"name":"sparkphp/test"}');
        file_put_contents($this->basePath . '/VERSION', '0.9.0');
        file_put_contents($this->basePath . '/CHANGELOG.md', '# Changelog');
        file_put_contents($this->basePath . '/spark', "#!/usr/bin/env php\n<?php\n");
        mkdir($this->basePath . '/docs', 0777, true);
        file_put_contents($this->basePath . '/docs/README.md', '# Docs');
        mkdir($this->basePath . '/public', 0777, true);
        file_put_contents($this->basePath . '/public/index.php', '<?php echo "ok";');

        $this->writeFile($this->basePath . '/core/stubs/starters/api/manifest.php', <<<'PHP'
<?php

return [
    'key' => 'api',
    'name' => 'API',
    'description' => 'JSON-first starter',
    'entrypoint' => '/api',
    'focus' => ['json', 'openapi'],
    'sort' => 10,
];
PHP
        );
        $this->writeFile($this->basePath . '/core/stubs/starters/api/files/app/routes/index.php', "<?php\n\nget(fn() => redirect('/api'));\n");
        $this->writeFile($this->basePath . '/core/stubs/starters/docs/manifest.php', <<<'PHP'
<?php

return [
    'key' => 'docs',
    'name' => 'Docs',
    'description' => 'Docs starter',
    'entrypoint' => '/documents',
    'focus' => ['markdown'],
    'sort' => 20,
];
PHP
        );
        $this->writeFile($this->basePath . '/core/stubs/starters/docs/files/app/routes/index.php', "<?php\n\nget(fn() => redirect('/documents'));\n");
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testInitializesFreshProjectEnvironmentAndDirectories(): void
    {
        $scaffolder = new ProjectScaffolder($this->basePath);
        $result = $scaffolder->initialize();

        $this->assertFileExists($this->basePath . '/.env');
        $env = (string) file_get_contents($this->basePath . '/.env');

        $this->assertMatchesRegularExpression('/^APP_KEY=[a-f0-9]{32}$/m', $env);
        $this->assertStringContainsString('.env created from .env.example', implode("\n", $result['messages']));
        $this->assertDirectoryExists($this->basePath . '/app/ai/agents');
        $this->assertDirectoryExists($this->basePath . '/app/ai/prompts');
        $this->assertDirectoryExists($this->basePath . '/app/ai/tools');
        $this->assertDirectoryExists($this->basePath . '/database/migrations');
        $this->assertFileExists($this->basePath . '/database/seeds/DatabaseSeeder.php');
        $this->assertDirectoryExists($this->basePath . '/storage/cache/views');
        $this->assertDirectoryExists($this->basePath . '/storage/queue');
        $this->assertDirectoryExists($this->basePath . '/public/css');
    }

    public function testForceRegeneratesExistingAppKeyAndClearsArtifacts(): void
    {
        mkdir($this->basePath . '/storage/cache/views', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
        mkdir($this->basePath . '/storage/sessions', 0777, true);

        file_put_contents($this->basePath . '/.env', "APP_KEY=existing-secret-key\n");
        file_put_contents($this->basePath . '/storage/cache/views/test.php', '<?php echo "cached";');
        file_put_contents($this->basePath . '/storage/logs/app.log', 'log');
        file_put_contents($this->basePath . '/storage/sessions/sess_test', 'session');

        $scaffolder = new ProjectScaffolder($this->basePath);
        $scaffolder->initialize(true);

        $env = (string) file_get_contents($this->basePath . '/.env');

        $this->assertMatchesRegularExpression('/^APP_KEY=[a-f0-9]{32}$/m', $env);
        $this->assertStringNotContainsString('existing-secret-key', $env);
        $this->assertFileDoesNotExist($this->basePath . '/storage/cache/views/test.php');
        $this->assertFileDoesNotExist($this->basePath . '/storage/logs/app.log');
        $this->assertFileDoesNotExist($this->basePath . '/storage/sessions/sess_test');
    }

    public function testCreateProjectCopiesRuntimeSkeletonAndInitializesTarget(): void
    {
        $source = sys_get_temp_dir() . '/sparkphp-scaffold-source-' . bin2hex(random_bytes(6));
        mkdir($source, 0777, true);

        $this->writeFile($source . '/.env.example', "APP_NAME=SparkPHP\nAPP_ENV=dev\nAPP_KEY=change-me-to-a-random-secret-32-chars\nAI_DRIVER=fake\n");
        $this->writeFile($source . '/.gitignore', "/vendor\n/.env\n");
        $this->writeFile($source . '/composer.json', '{"name":"sparkphp/test"}');
        $this->writeFile($source . '/VERSION', '0.9.0');
        $this->writeFile($source . '/CHANGELOG.md', '# Changelog');
        $this->writeFile($source . '/spark', "#!/usr/bin/env php\n<?php echo 'spark';\n");
        $this->writeFile($source . '/public/index.php', '<?php echo "hello";');
        $this->writeFile($source . '/docs/README.md', '# Docs');
        $this->writeFile($source . '/app/routes/index.php', '<?php get(fn() => "ok");');
        $this->writeFile($source . '/core/Bootstrap.php', '<?php class Bootstrap {}');
        $this->writeFile($source . '/database/migrations/.gitkeep', '');
        $this->writeFile($source . '/tests/README.md', '# Tests');

        $target = sys_get_temp_dir() . '/sparkphp-created-project-' . bin2hex(random_bytes(6));
        $result = (new ProjectScaffolder($source))->createProject($target);

        $this->assertSame($target, $result['target']);
        $this->assertFileExists($target . '/.env');
        $this->assertFileExists($target . '/spark');
        $this->assertFileExists($target . '/public/index.php');
        $this->assertFileExists($target . '/docs/README.md');
        $this->assertDirectoryExists($target . '/app/ai/agents');
        $this->assertDirectoryExists($target . '/storage/cache/views');
        $this->assertStringContainsString('root file(s) copied', implode("\n", $result['messages']));
        $this->assertMatchesRegularExpression('/^APP_KEY=[a-f0-9]{32}$/m', (string) file_get_contents($target . '/.env'));

        $this->deleteDirectory($source);
        $this->deleteDirectory($target);
    }

    public function testListsAvailableStartersFromLocalRuntimeCatalog(): void
    {
        $starters = (new ProjectScaffolder($this->basePath))->listStarters();

        $this->assertCount(2, $starters);
        $this->assertSame('api', $starters[0]['key']);
        $this->assertSame('/api', $starters[0]['entrypoint']);
        $this->assertSame('docs', $starters[1]['key']);
    }

    public function testInitializeCanApplyStarterAndWriteMarker(): void
    {
        $scaffolder = new ProjectScaffolder($this->basePath);
        $result = $scaffolder->initialize(false, 'api');

        $this->assertFileExists($this->basePath . '/.spark-starter');
        $this->assertStringContainsString('"key": "api"', (string) file_get_contents($this->basePath . '/.spark-starter'));
        $this->assertFileExists($this->basePath . '/app/routes/index.php');
        $this->assertStringContainsString("redirect('/api')", (string) file_get_contents($this->basePath . '/app/routes/index.php'));
        $this->assertStringContainsString('starter [api] applied', implode("\n", $result['messages']));
    }

    public function testAuditAndSyncUpgradeDetectAndRepairMissingScaffoldParts(): void
    {
        $scaffolder = new ProjectScaffolder($this->basePath);
        $scaffolder->initialize();

        rmdir($this->basePath . '/app/ai/tools');
        file_put_contents($this->basePath . '/.env', "APP_NAME=SparkPHP\nAPP_ENV=dev\nAPP_KEY=test-key-1234567890123456789012\n");

        $audit = $scaffolder->audit();

        $this->assertContains('app/ai/tools', $audit['missing_directories']);
        $this->assertContains('AI_DRIVER', $audit['missing_env_keys']);
        $this->assertFalse($audit['ready']);

        $sync = $scaffolder->syncUpgrade();

        $this->assertDirectoryExists($this->basePath . '/app/ai/tools');
        $this->assertContains('AI_DRIVER', $sync['synced_env_keys']);
        $this->assertStringContainsString('AI_DRIVER=fake', (string) file_get_contents($this->basePath . '/.env'));
        $this->assertTrue($sync['audit']['ready']);
    }

    public function testCreateProjectAppliesStarterPresetOnFreshTarget(): void
    {
        $source = sys_get_temp_dir() . '/sparkphp-starter-source-' . bin2hex(random_bytes(6));
        mkdir($source, 0777, true);

        $this->writeFile($source . '/.env.example', "APP_NAME=SparkPHP\nAPP_ENV=dev\nAPP_KEY=change-me-to-a-random-secret-32-chars\n");
        $this->writeFile($source . '/composer.json', '{"name":"sparkphp/test"}');
        $this->writeFile($source . '/VERSION', '0.9.0');
        $this->writeFile($source . '/CHANGELOG.md', '# Changelog');
        $this->writeFile($source . '/spark', "#!/usr/bin/env php\n<?php echo 'spark';\n");
        $this->writeFile($source . '/docs/README.md', '# Docs');
        $this->writeFile($source . '/public/index.php', '<?php echo "ok";');
        $this->writeFile($source . '/app/routes/index.php', '<?php get(fn() => "base");');
        $this->writeFile($source . '/core/ProjectScaffolder.php', '<?php class ProjectScaffolder {}');
        $this->writeFile($source . '/core/stubs/starters/api/manifest.php', <<<'PHP'
<?php

return [
    'key' => 'api',
    'name' => 'API',
    'description' => 'JSON-first starter',
    'entrypoint' => '/api',
    'focus' => ['json'],
];
PHP
        );
        $this->writeFile($source . '/core/stubs/starters/api/files/app/routes/index.php', "<?php\n\nget(fn() => redirect('/api'));\n");
        $this->writeFile($source . '/database/migrations/.gitkeep', '');
        $this->writeFile($source . '/tests/README.md', '# Tests');

        $target = sys_get_temp_dir() . '/sparkphp-starter-target-' . bin2hex(random_bytes(6));
        $result = (new ProjectScaffolder($source))->createProject($target, false, true, 'api');

        $this->assertSame('api', $result['starter']['key']);
        $this->assertFileExists($target . '/.spark-starter');
        $this->assertStringContainsString("redirect('/api')", (string) file_get_contents($target . '/app/routes/index.php'));

        $this->deleteDirectory($source);
        $this->deleteDirectory($target);
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

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
