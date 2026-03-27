<?php

class Container
{
    private array $bindings   = [];
    private array $singletons = [];
    private array $instances  = [];

    /**
     * Register a binding (new instance each call).
     */
    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    /**
     * Register a singleton (shared instance).
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->singletons[$abstract] = $factory;
    }

    /**
     * Resolve a class/abstract from the container.
     */
    public function make(string $abstract): mixed
    {
        // Already resolved singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Singleton factory
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = ($this->singletons[$abstract])($this);
            return $this->instances[$abstract];
        }

        // Regular binding
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        // Auto-wire if it's a real class
        if (class_exists($abstract)) {
            return $this->build($abstract);
        }

        throw new \RuntimeException("Container: cannot resolve [{$abstract}]");
    }

    /**
     * Auto-wire a class by reflecting its constructor.
     */
    public function build(string $class): mixed
    {
        $ref = new \ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Container: [{$class}] is not instantiable");
        }

        $constructor = $ref->getConstructor();
        if (!$constructor) {
            return new $class();
        }

        $args = $this->resolveParameters($constructor->getParameters(), []);
        return $ref->newInstanceArgs($args);
    }

    /**
     * Call a callable, resolving its parameters from the container + extras.
     */
    public function call(callable $callable, array $extras = []): mixed
    {
        $ref = is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : new \ReflectionFunction(\Closure::fromCallable($callable));

        $args = $this->resolveParameters($ref->getParameters(), $extras);
        return $callable(...$args);
    }

    /**
     * Resolve an array of ReflectionParameter values.
     * $extras: ['paramName' => value, ...] — from URL params or explicit overrides.
     */
    private function resolveParameters(array $params, array $extras): array
    {
        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();

            // Explicit extra by name
            if (array_key_exists($name, $extras)) {
                $args[] = $extras[$name];
                continue;
            }

            $type = $param->getType();

            // Typed class parameter → resolve from container
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                try {
                    $args[] = $this->make($typeName);
                    continue;
                } catch (\RuntimeException) {}
            }

            // Primitive type with matching URL param name → cast and inject
            if ($type && $type->isBuiltin()) {
                $inputVal = input($name) ?? query($name);
                if ($inputVal !== null) {
                    $args[] = $this->castPrimitive($inputVal, $type->getName());
                    continue;
                }
            }

            // Default value
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Nullable
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \RuntimeException("Container: cannot resolve parameter \${$name}");
        }
        return $args;
    }

    private function castPrimitive(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default  => $value,
        };
    }

    /**
     * Check if a binding is registered.
     */
    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->bindings[$abstract]);
    }
}
