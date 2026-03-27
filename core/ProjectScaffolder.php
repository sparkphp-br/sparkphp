<?php

class ProjectScaffolder
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function initialize(bool $force = false): array
    {
        $messages = [];

        $createdEnv = $this->ensureEnvFile();
        if ($createdEnv) {
            $messages[] = '.env created from .env.example';
        }

        $keyState = $this->ensureAppKey($force);
        if ($keyState === 'generated') {
            $messages[] = 'APP_KEY generated';
        } elseif ($keyState === 'regenerated') {
            $messages[] = 'APP_KEY regenerated';
        }

        $createdDirs = $this->ensureDirectories();
        if ($createdDirs > 0) {
            $messages[] = "{$createdDirs} project directories prepared";
        }

        $removedArtifacts = $this->clearRuntimeArtifacts();
        if ($removedArtifacts > 0) {
            $messages[] = "{$removedArtifacts} runtime artifacts cleared";
        }

        if ($messages === []) {
            $messages[] = 'project already initialized';
        }

        return ['messages' => $messages];
    }

    private function ensureEnvFile(): bool
    {
        $envFile = $this->basePath . '/.env';
        $exampleFile = $this->basePath . '/.env.example';

        if (file_exists($envFile) || !file_exists($exampleFile)) {
            return false;
        }

        copy($exampleFile, $envFile);
        return true;
    }

    private function ensureAppKey(bool $force): ?string
    {
        $envFile = $this->basePath . '/.env';
        if (!file_exists($envFile)) {
            return null;
        }

        $contents = (string) file_get_contents($envFile);
        $key = $this->readEnvValue($contents, 'APP_KEY');
        $needsKey = $force || $key === null || $key === '' || $key === 'change-me-to-a-random-secret-32-chars';

        if (!$needsKey) {
            return null;
        }

        $newKey = bin2hex(random_bytes(16));
        $replacement = 'APP_KEY=' . $newKey;

        if (preg_match('/^APP_KEY=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^APP_KEY=.*$/m', $replacement, $contents, 1);
        } else {
            $contents = rtrim($contents) . PHP_EOL . $replacement . PHP_EOL;
        }

        file_put_contents($envFile, $contents);

        return $key === null || $key === '' || $key === 'change-me-to-a-random-secret-32-chars'
            ? 'generated'
            : 'regenerated';
    }

    private function readEnvValue(string $contents, string $key): ?string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], " \t\n\r\0\x0B\"'");
    }

    private function ensureDirectories(): int
    {
        $directories = [
            'app/events',
            'app/models',
            'app/services',
            'database/seeds',
            'public/css',
            'public/images',
            'public/js',
            'storage/cache/app',
            'storage/cache/phpunit',
            'storage/cache/views',
            'storage/benchmarks',
            'storage/inspector',
            'storage/logs',
            'storage/queue',
            'storage/sessions',
            'storage/uploads',
        ];

        $created = 0;

        foreach ($directories as $directory) {
            $path = $this->basePath . '/' . $directory;
            if (is_dir($path)) {
                continue;
            }

            mkdir($path, 0755, true);
            $created++;
        }

        return $created;
    }

    private function clearRuntimeArtifacts(): int
    {
        $patterns = [
            'storage/cache/app/*.cache',
            'storage/cache/views/*.php',
            'storage/cache/phpunit/*',
            'storage/logs/*.log',
            'storage/inspector/*.json',
            'storage/queue/*.json',
            'storage/sessions/*',
        ];

        $files = [
            'storage/cache/env.php',
            'storage/cache/classes.php',
            'storage/cache/routes.php',
            'storage/inspector/index.json',
            'storage/migrations.json',
        ];

        $removed = 0;

        foreach ($files as $relativePath) {
            $path = $this->basePath . '/' . $relativePath;
            if (is_file($path) && unlink($path)) {
                $removed++;
            }
        }

        foreach ($patterns as $pattern) {
            $matches = glob($this->basePath . '/' . $pattern) ?: [];
            foreach ($matches as $path) {
                if (!is_file($path)) {
                    continue;
                }

                if (basename($path) === '.gitignore') {
                    continue;
                }

                if (unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
