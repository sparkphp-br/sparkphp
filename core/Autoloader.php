<?php

class Autoloader
{
    private string $basePath;
    private array $classMap = [];
    private string $cacheFile;

    public function __construct(string $basePath)
    {
        $this->basePath  = $basePath;
        $this->cacheFile = $basePath . '/storage/cache/classes.php';
    }

    public function register(): void
    {
        $this->classMap = $this->loadMap();

        spl_autoload_register(function (string $class): void {
            if (isset($this->classMap[$class])) {
                require_once $this->classMap[$class];
            }
        });
    }

    private function loadMap(): array
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';

        if (!$isDev && file_exists($this->cacheFile)) {
            return require $this->cacheFile;
        }

        return $this->buildMap();
    }

    public function buildMap(): array
    {
        $appPath = $this->basePath . '/app';
        if (!is_dir($appPath)) {
            return [];
        }

        $map      = [];
        $skip     = ['routes'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip routes directory
            $relative = str_replace($appPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $parts    = explode(DIRECTORY_SEPARATOR, $relative);
            if (in_array($parts[0], $skip, true)) {
                continue;
            }

            $className = $this->extractClassName($file->getPathname());
            if (!$className) {
                continue;
            }

            if (isset($map[$className])) {
                trigger_error(
                    "Autoloader conflict: class '{$className}' found in both:\n" .
                    "  {$map[$className]}\n  {$file->getPathname()}",
                    E_USER_ERROR
                );
            }

            $map[$className] = $file->getPathname();
        }

        $this->saveCache($map);
        return $map;
    }

    private function extractClassName(string $file): ?string
    {
        $content = file_get_contents($file);
        if (!$content) {
            return null;
        }

        // Match class / interface / trait / enum declarations
        if (preg_match('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    private function saveCache(array $map): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->cacheFile, '<?php return ' . var_export($map, true) . ';');
    }

    public function getMap(): array
    {
        return $this->classMap;
    }
}
