<?php

class Middleware
{
    private string $basePath;
    private array $names;

    public function __construct(string $basePath, array $names)
    {
        $this->basePath = $basePath;
        $this->names    = $names;
    }

    /**
     * Run the middleware pipeline.
     * Returns a Response object if any middleware blocks, null if all pass.
     */
    public function run(): ?Response
    {
        foreach ($this->names as $name) {
            $result = $this->execute($name);
            if ($result !== null) {
                return $result instanceof Response ? $result : null;
            }
        }
        return null;
    }

    private function execute(string $name): mixed
    {
        // Parse params: "throttle:30" → ['throttle', ['30']]
        $parts  = explode(':', $name, 2);
        $alias  = $parts[0];
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

        if (class_exists('SparkInspector')) {
            SparkInspector::recordMiddleware($alias, $params, 'running');
        }

        $file = $this->basePath . "/app/middleware/{$alias}.php";
        if (!file_exists($file)) {
            throw new \RuntimeException("Middleware not found: {$alias}");
        }

        // Execute the middleware file in an isolated scope
        $result = (static function () use ($file, $params) {
            return require $file;
        })();

        if (class_exists('SparkInspector')) {
            SparkInspector::recordMiddleware(
                $alias,
                $params,
                $result instanceof Response ? 'blocked' : 'passed'
            );
        }

        return $result;
    }
}
