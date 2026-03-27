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
ENV
        );
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
