<?php

class OpenApiGenerator
{
    private string $basePath;
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];
    private bool $usesSessionSecurity = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function generate(array $options = []): array
    {
        require_once $this->basePath . '/core/Response.php';
        require_once $this->basePath . '/core/Database.php';
        require_once $this->basePath . '/core/Relation.php';
        require_once $this->basePath . '/core/Model.php';
        require_once $this->basePath . '/core/Router.php';
        require_once $this->basePath . '/core/helpers.php';

        $onlyApi = ($options['only_api'] ?? true) === true;
        $router = new Router($this->basePath);
        $paths = [];

        foreach ($router->list() as $route) {
            if ($onlyApi && !str_starts_with($route['url'], '/api')) {
                continue;
            }

            $loaded = $this->loadRouteHandlers($route['file']);
            foreach ($loaded['handlers'] as $verb => $handler) {
                if (!is_callable($handler)) {
                    continue;
                }

                $openApiPath = $this->toOpenApiPath($route['url']);
                $paths[$openApiPath][strtolower($verb)] = $this->buildOperation(
                    $route,
                    strtolower($verb),
                    $handler,
                    $loaded['meta'][$verb] ?? []
                );
            }
        }

        ksort($paths);

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => ($_ENV['APP_NAME'] ?? basename($this->basePath)) . ' API',
                'version' => defined('SPARK_VERSION') ? SPARK_VERSION : '0.1.0',
            ],
            'servers' => [
                ['url' => $_ENV['APP_URL'] ?? 'http://localhost:8000'],
            ],
            'paths' => $paths,
            'components' => [
                'schemas' => array_merge($this->baseSchemas(), $this->schemas),
            ],
        ];

        if ($this->usesSessionSecurity) {
            $spec['components']['securitySchemes'] = [
                'sessionAuth' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => 'spark_session',
                ],
            ];
        }

        return $spec;
    }

    public function write(array $options = []): string
    {
        $output = $options['output'] ?? $this->basePath . '/storage/api/openapi.json';
        $spec = $this->generate($options);

        $dir = dirname($output);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $output,
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        return $output;
    }

    /**
     * @return array{handlers: array<string, callable>, meta: array<string, array<string, mixed>>}
     */
    private function loadRouteHandlers(string $file): array
    {
        Router::$_collected = [];
        Router::$_meta = [];
        Router::$_path = null;
        Router::$_routeName = null;

        (static function () use ($file) {
            require $file;
        })();

        $handlers = Router::$_collected;
        $meta = Router::$_meta;

        Router::$_collected = [];
        Router::$_meta = [];
        Router::$_path = null;
        Router::$_routeName = null;

        return [
            'handlers' => $handlers,
            'meta' => $meta,
        ];
    }

    private function toOpenApiPath(string $route): string
    {
        return preg_replace('/\:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $route) ?? $route;
    }

    private function buildOperation(array $route, string $verb, callable $handler, array $meta): array
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($handler));
        $source = $this->handlerSource($reflection);
        $modelParameters = $this->modelParameters($reflection);
        $validationRules = $this->extractValidationRules($source);
        $middlewares = array_values(array_unique(array_merge(
            $route['middlewares'] ?? [],
            $meta['middlewares'] ?? []
        )));

        $operation = [
            'operationId' => $this->operationId($verb, $route['url']),
            'tags' => [$this->tagForRoute($route['url'])],
            'parameters' => array_values(array_filter(array_merge(
                $this->pathParameters($route, $reflection, $modelParameters),
                $this->queryParameters($source)
            ))),
            'responses' => $this->responsesForOperation($verb, $route, $reflection, $source, $validationRules, $middlewares),
            'x-spark-route-file' => str_replace($this->basePath . '/app/routes/', '', $route['file']),
            'x-spark-middlewares' => $middlewares,
        ];

        if (!empty($route['name'])) {
            $operation['x-spark-route-name'] = $route['name'];
        }

        if ($validationRules !== null) {
            $operation['requestBody'] = [
                'required' => in_array($verb, ['post', 'put', 'patch'], true),
                'content' => [
                    'application/json' => [
                        'schema' => $this->schemaFromValidationRules($validationRules),
                    ],
                ],
            ];
        }

        $abilities = $this->extractAuthorizeAbilities($source);
        if ($abilities !== []) {
            $operation['x-spark-authorize'] = $abilities;
        }

        if ($this->requiresSessionSecurity($middlewares, $source)) {
            $this->usesSessionSecurity = true;
            $operation['security'] = [['sessionAuth' => []]];
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(array $route, ReflectionFunction $reflection, array $modelParameters): array
    {
        $parameters = [];
        $primitiveTypes = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $primitiveTypes[$parameter->getName()] = $type->getName();
            }
        }

        foreach ($route['paramNames'] ?? [] as $name) {
            $schema = ['type' => 'string'];

            if (isset($primitiveTypes[$name])) {
                $schema = $this->builtinSchema($primitiveTypes[$name]);
            } elseif ($modelParameters !== []) {
                $schema = $this->routeModelParameterSchema($name, $modelParameters);
            } elseif (strtolower($name) === 'id' || str_ends_with(strtolower($name), '_id')) {
                $schema = ['type' => 'integer'];
            }

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryParameters(string $source): array
    {
        $names = [];

        if (preg_match_all('/(?:^|[^A-Za-z0-9_])query\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches)) {
            $names = array_merge($names, $matches[1]);
        }

        if (preg_match_all('/->query\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches)) {
            $names = array_merge($names, $matches[1]);
        }

        if (str_contains($source, 'paginate(')) {
            $names[] = 'page';
        }

        $parameters = [];
        foreach (array_values(array_unique($names)) as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'schema' => $name === 'page' ? ['type' => 'integer', 'minimum' => 1] : ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * @param array<string, string> $modelParameters
     */
    private function routeModelParameterSchema(string $routeParamName, array $modelParameters): array
    {
        foreach ($modelParameters as $parameterName => $className) {
            foreach ($this->routeParamCandidates($parameterName, $className) as $candidate) {
                if ($this->normalizeName($candidate) !== $this->normalizeName($routeParamName)) {
                    continue;
                }

                try {
                    /** @var Model $instance */
                    $instance = new $className();
                    $routeKey = $instance->getRouteKeyName();

                    return ($routeKey === 'id' || str_ends_with($routeKey, '_id'))
                        ? ['type' => 'integer']
                        : ['type' => 'string'];
                } catch (Throwable) {
                    return ['type' => 'string'];
                }
            }
        }

        return ['type' => 'string'];
    }

    /**
     * @return array<string, string>
     */
    private function modelParameters(ReflectionFunction $reflection): array
    {
        $models = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            if (is_subclass_of($className, Model::class)) {
                $models[$parameter->getName()] = $className;
            }
        }

        return $models;
    }

    private function handlerSource(ReflectionFunction $reflection): string
    {
        $file = $reflection->getFileName();
        if (!is_string($file) || !is_file($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }

        $start = max(0, $reflection->getStartLine() - 1);
        $length = max(1, $reflection->getEndLine() - $reflection->getStartLine() + 1);

        return implode("\n", array_slice($lines, $start, $length));
    }

    private function operationId(string $verb, string $route): string
    {
        $clean = trim($route, '/');
        $clean = $clean === '' ? 'root' : preg_replace('/[^A-Za-z0-9]+/', ' ', $clean);
        $parts = preg_split('/\s+/', trim((string) $clean)) ?: [];
        $parts = array_map(static fn(string $part): string => ucfirst($part), $parts);

        return lcfirst(ucfirst($verb) . implode('', $parts));
    }

    private function tagForRoute(string $route): string
    {
        $segments = array_values(array_filter(explode('/', trim($route, '/'))));
        if ($segments === []) {
            return 'root';
        }

        if (($segments[0] ?? null) === 'api') {
            return $segments[1] ?? 'api';
        }

        return $segments[0];
    }

    private function responsesForOperation(
        string $verb,
        array $route,
        ReflectionFunction $reflection,
        string $source,
        ?array $validationRules,
        array $middlewares
    ): array {
        $status = $this->successStatus($verb, $source);
        $schema = $this->successSchema($source, $reflection, $route);

        $responses = [
            (string) $status => [
                'description' => Response::statusText($status),
            ],
        ];

        if ($schema !== null) {
            $responses[(string) $status]['content'] = [
                'application/json' => [
                    'schema' => $schema,
                ],
            ];
        }

        if ($validationRules !== null) {
            $responses['422'] = [
                'description' => 'Validation error',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SparkValidationError'],
                    ],
                ],
            ];
        }

        if ($this->requiresSessionSecurity($middlewares, $source)) {
            $responses['401'] = [
                'description' => 'Unauthenticated',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SparkError'],
                    ],
                ],
            ];
            $responses['403'] = [
                'description' => 'Forbidden',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SparkError'],
                    ],
                ],
            ];
        } elseif (str_contains($source, 'authorize(') || preg_match('/abort\(\s*403\b/', $source) === 1) {
            $responses['403'] = [
                'description' => 'Forbidden',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SparkError'],
                    ],
                ],
            ];
        }

        if (($route['paramNames'] ?? []) !== [] || str_contains($source, 'findOrFail(')) {
            $responses['404'] = [
                'description' => 'Not found',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SparkError'],
                    ],
                ],
            ];
        }

        ksort($responses);
        return $responses;
    }

    private function successStatus(string $verb, string $source): int
    {
        if (preg_match('/(?:Response::empty|Response::noContent|noContent\s*\(|empty\s*\(\s*204\b|return\s+null\b|=>\s*null\b)/', $source) === 1) {
            return 204;
        }

        if (str_contains($source, 'Response::created(') || preg_match('/\bcreated\s*\(/', $source) === 1) {
            return 201;
        }

        return $verb === 'post' ? 201 : 200;
    }

    private function successSchema(string $source, ReflectionFunction $reflection, array $route): ?array
    {
        if (preg_match('/(?:Response::empty|Response::noContent|noContent\s*\(|return\s+null\b|=>\s*null\b)/', $source) === 1) {
            return null;
        }

        $modelParameters = $this->modelParameters($reflection);

        if ($jsonApiModel = $this->inferJsonApiModel($source, $modelParameters)) {
            return $this->jsonApiDocumentSchema($jsonApiModel);
        }

        if ($paginatedModel = $this->inferPaginatedModel($source)) {
            return $this->paginatedSchema($this->schemaForModelRef($paginatedModel));
        }

        if ($returnedModel = $this->inferReturnedModel($source, $modelParameters)) {
            return $this->schemaForModelRef($returnedModel);
        }

        if ($literal = $this->extractReturnedArrayLiteral($source)) {
            $schema = $this->schemaFromStaticArrayLiteral($literal);
            if ($schema !== null) {
                return $schema;
            }
        }

        if (($route['paramNames'] ?? []) !== [] && count($modelParameters) === 1) {
            return $this->schemaForModelRef(array_values($modelParameters)[0]);
        }

        return ['type' => 'object'];
    }

    /**
     * @param array<string, string> $modelParameters
     */
    private function inferReturnedModel(string $source, array $modelParameters): ?string
    {
        if (preg_match('/(?:return|=>)\s*\$([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $matches) === 1) {
            $variable = $matches[1];
            if (isset($modelParameters[$variable])) {
                return $modelParameters[$variable];
            }
        }

        if (preg_match('/(?:return|=>)\s*([A-Z][A-Za-z0-9_]*)::(?:findOrFail|find|create|firstOrCreate|updateOrCreate)\b/', $source, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param array<string, string> $modelParameters
     */
    private function inferJsonApiModel(string $source, array $modelParameters): ?string
    {
        if (preg_match('/([A-Z][A-Za-z0-9_]*)::api\(.+?[\'"]json_api[\'"]\s*=>\s*true/s', $source, $matches) === 1) {
            return $matches[1];
        }

        foreach ($modelParameters as $parameterName => $className) {
            if (preg_match('/(?:return|=>)\s*' . preg_quote('$' . $parameterName, '/') . '\b/', $source) === 1
                && preg_match('/[\'"]json_api[\'"]\s*=>\s*true/', $source) === 1) {
                return $className;
            }
        }

        return null;
    }

    private function inferPaginatedModel(string $source): ?string
    {
        if (preg_match('/([A-Z][A-Za-z0-9_]*)::(?:query\(\)|with\([^\)]*\)|where[^\n;]*)->paginate\(/s', $source, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function paginatedSchema(array $itemSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => $itemSchema,
                ],
                'links' => ['$ref' => '#/components/schemas/SparkPaginationLinks'],
                'meta' => ['$ref' => '#/components/schemas/SparkPaginationMeta'],
            ],
            'required' => ['data', 'links', 'meta'],
        ];
    }

    private function jsonApiDocumentSchema(string $modelClass): array
    {
        $component = $this->modelComponentName($modelClass) . 'JsonApiResource';
        if (!isset($this->schemas[$component])) {
            $this->schemas[$component] = [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'attributes' => $this->schemaForModelRef($modelClass),
                ],
                'required' => ['type'],
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => ['$ref' => '#/components/schemas/' . $component],
            ],
            'required' => ['data'],
        ];
    }

    private function requiresSessionSecurity(array $middlewares, string $source): bool
    {
        foreach ($middlewares as $middleware) {
            if ($middleware === 'auth' || str_starts_with($middleware, 'role:')) {
                return true;
            }
        }

        return str_contains($source, 'auth()') && str_contains($source, 'authorize(');
    }

    /**
     * @return array<int, string>
     */
    private function extractAuthorizeAbilities(string $source): array
    {
        if (!preg_match_all('/authorize\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, string>|null
     */
    private function extractValidationRules(string $source): ?array
    {
        $literal = $this->extractArrayArgumentLiteral($source, 'validate');
        if ($literal === null) {
            return null;
        }

        $rules = $this->evaluateStaticArrayLiteral($literal);
        return is_array($rules) ? $rules : null;
    }

    private function extractReturnedArrayLiteral(string $source): ?string
    {
        foreach (['return', '=>'] as $needle) {
            $position = strpos($source, $needle);
            if ($position === false) {
                continue;
            }

            $searchFrom = $position + strlen($needle);
            $arrayStart = strpos($source, '[', $searchFrom);
            if ($arrayStart === false) {
                continue;
            }

            return $this->balancedBracketLiteral($source, $arrayStart);
        }

        return null;
    }

    private function extractArrayArgumentLiteral(string $source, string $functionName): ?string
    {
        if (preg_match('/\b' . preg_quote($functionName, '/') . '\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $matches[0][1] + strlen($matches[0][0]);
        $arrayStart = strpos($source, '[', $offset);
        if ($arrayStart === false) {
            return null;
        }

        return $this->balancedBracketLiteral($source, $arrayStart);
    }

    private function balancedBracketLiteral(string $source, int $start): ?string
    {
        $length = strlen($source);
        $depth = 0;
        $string = null;

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($string !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }

                if ($char === $string) {
                    $string = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $string = $char;
                continue;
            }

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function evaluateStaticArrayLiteral(string $literal): ?array
    {
        if (preg_match('/[$;{}]|::|->|\bnew\b|\bfunction\b|\bfn\b/', $literal) === 1) {
            return null;
        }

        try {
            $value = eval('return ' . $literal . ';');
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function schemaFromStaticArrayLiteral(string $literal): ?array
    {
        $value = $this->evaluateStaticArrayLiteral($literal);
        if ($value === null) {
            return null;
        }

        return $this->schemaFromLiteralValue($value);
    }

    private function schemaFromLiteralValue(mixed $value): array
    {
        if (is_bool($value)) {
            return ['type' => 'boolean'];
        }

        if (is_int($value)) {
            return ['type' => 'integer'];
        }

        if (is_float($value)) {
            return ['type' => 'number'];
        }

        if (is_string($value)) {
            return ['type' => 'string'];
        }

        if ($value === null) {
            return ['nullable' => true];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $itemSchema = $value === [] ? ['type' => 'object'] : $this->schemaFromLiteralValue($value[0]);
                return [
                    'type' => 'array',
                    'items' => $itemSchema,
                ];
            }

            $properties = [];
            foreach ($value as $key => $item) {
                $properties[(string) $key] = $this->schemaFromLiteralValue($item);
            }

            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
            ];
        }

        return ['type' => 'object'];
    }

    private function schemaFromValidationRules(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $ruleString) {
            $properties[$field] = $this->schemaForValidationRule($field, (string) $ruleString);
            $properties[$field]['x-spark-rules'] = $ruleString;

            $ruleList = explode('|', (string) $ruleString);
            if (in_array('required', $ruleList, true)) {
                $required[] = $field;
            }

            if (in_array('confirmed', $ruleList, true)) {
                $confirmation = $field . '_confirmation';
                $properties[$confirmation] = [
                    'type' => 'string',
                    'x-spark-rules' => 'confirmation',
                ];
                if (in_array('required', $ruleList, true)) {
                    $required[] = $confirmation;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    private function schemaForValidationRule(string $field, string $ruleString): array
    {
        $schema = ['type' => 'string'];
        $rules = explode('|', $ruleString);

        foreach ($rules as $rule) {
            [$name, $parameter] = str_contains($rule, ':')
                ? explode(':', $rule, 2)
                : [$rule, null];

            switch ($name) {
                case 'int':
                    $schema['type'] = 'integer';
                    break;
                case 'float':
                case 'numeric':
                    $schema['type'] = 'number';
                    break;
                case 'bool':
                    $schema['type'] = 'boolean';
                    break;
                case 'email':
                    $schema['type'] = 'string';
                    $schema['format'] = 'email';
                    break;
                case 'url':
                    $schema['type'] = 'string';
                    $schema['format'] = 'uri';
                    break;
                case 'date':
                case 'before':
                case 'after':
                    $schema['type'] = 'string';
                    $schema['format'] = 'date-time';
                    break;
                case 'file':
                case 'image':
                case 'max_size':
                    $schema['type'] = 'string';
                    $schema['format'] = 'binary';
                    break;
                case 'in':
                    $schema['enum'] = array_values(array_filter(explode(',', (string) $parameter), static fn(string $value): bool => $value !== ''));
                    break;
                case 'min':
                    if (($schema['type'] ?? 'string') === 'integer' || ($schema['type'] ?? 'string') === 'number') {
                        $schema['minimum'] = (float) $parameter;
                    } else {
                        $schema['minLength'] = (int) $parameter;
                    }
                    break;
                case 'max':
                    if (($schema['type'] ?? 'string') === 'integer' || ($schema['type'] ?? 'string') === 'number') {
                        $schema['maximum'] = (float) $parameter;
                    } else {
                        $schema['maxLength'] = (int) $parameter;
                    }
                    break;
                case 'between':
                    if ($parameter !== null && str_contains($parameter, ',')) {
                        [$min, $max] = explode(',', $parameter, 2);
                        if (($schema['type'] ?? 'string') === 'integer' || ($schema['type'] ?? 'string') === 'number') {
                            $schema['minimum'] = (float) $min;
                            $schema['maximum'] = (float) $max;
                        } else {
                            $schema['minLength'] = (int) $min;
                            $schema['maxLength'] = (int) $max;
                        }
                    }
                    break;
            }
        }

        return $schema;
    }

    private function schemaForModelRef(string $modelClass): array
    {
        $name = $this->modelComponentName($modelClass);
        if (!isset($this->schemas[$name])) {
            $this->schemas[$name] = $this->buildModelSchema($modelClass);
        }

        return ['$ref' => '#/components/schemas/' . $name];
    }

    private function modelComponentName(string $modelClass): string
    {
        return (new ReflectionClass($modelClass))->getShortName();
    }

    private function buildModelSchema(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        /** @var Model $instance */
        $instance = $reflection->newInstance();
        $defaults = $reflection->getDefaultProperties();

        $fillable = $defaults['fillable'] ?? [];
        $casts = $defaults['casts'] ?? [];
        $hidden = array_merge($defaults['hidden'] ?? [], $this->hiddenAttributes($reflection));
        $renames = $this->renameAttributes($reflection);
        $properties = [];
        $required = [];

        $columns = [];
        try {
            $columns = db()->getColumns($instance->getTable());
        } catch (Throwable) {
            $columns = [];
        }

        $accessors = $this->accessorProperties($reflection);
        $fields = array_values(array_unique(array_merge(
            [$instance->getPrimaryKey()],
            $columns,
            $fillable,
            array_keys($casts),
            array_keys($accessors)
        )));

        foreach ($fields as $field) {
            if (in_array($field, $hidden, true)) {
                continue;
            }

            $apiName = $renames[$field] ?? $field;
            $properties[$apiName] = $this->schemaForModelProperty($field, $casts[$field] ?? null, $accessors[$field] ?? null);

            if ($field === $instance->getPrimaryKey()) {
                $required[] = $apiName;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * @return array<int, string>
     */
    private function hiddenAttributes(ReflectionClass $reflection): array
    {
        $hidden = [];
        foreach ($reflection->getAttributes(Hidden::class) as $attribute) {
            /** @var Hidden $instance */
            $instance = $attribute->newInstance();
            $hidden[] = $instance->field;
        }

        return $hidden;
    }

    /**
     * @return array<string, string>
     */
    private function renameAttributes(ReflectionClass $reflection): array
    {
        $renames = [];
        foreach ($reflection->getAttributes(Rename::class) as $attribute) {
            /** @var Rename $instance */
            $instance = $attribute->newInstance();
            $renames[$instance->from] = $instance->to;
        }

        return $renames;
    }

    /**
     * @return array<string, ReflectionNamedType|null>
     */
    private function accessorProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(Accessor::class) as $attribute) {
                /** @var Accessor $accessor */
                $accessor = $attribute->newInstance();
                $name = $accessor->name ?? $this->toSnake($method->getName());
                $returnType = $method->getReturnType();
                $properties[$name] = $returnType instanceof ReflectionNamedType ? $returnType : null;
            }

            if (preg_match('/^get(.+)Attribute$/', $method->getName(), $matches) === 1) {
                $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $matches[1]) ?? $matches[1]);
                $returnType = $method->getReturnType();
                $properties[$name] = $returnType instanceof ReflectionNamedType ? $returnType : null;
            }
        }

        return $properties;
    }

    private function schemaForModelProperty(string $field, ?string $cast = null, ?ReflectionNamedType $returnType = null): array
    {
        if ($returnType instanceof ReflectionNamedType) {
            if ($returnType->isBuiltin()) {
                return $this->builtinSchema($returnType->getName());
            }

            if (is_a($returnType->getName(), DateTimeInterface::class, true)) {
                return ['type' => 'string', 'format' => 'date-time'];
            }
        }

        return match ($cast) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double', 'numeric' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'json' => ['type' => 'object'],
            default => (($field === 'id' || str_ends_with($field, '_id')) ? ['type' => 'integer'] : ['type' => 'string']),
        };
    }

    private function builtinSchema(string $type): array
    {
        return match ($type) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'string'],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function baseSchemas(): array
    {
        return [
            'SparkError' => [
                'type' => 'object',
                'properties' => [
                    'error' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                ],
                'required' => ['error', 'status', 'code'],
            ],
            'SparkValidationError' => [
                'type' => 'object',
                'properties' => [
                    'error' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ],
                'required' => ['error', 'status', 'code', 'errors'],
            ],
            'SparkPaginationLinks' => [
                'type' => 'object',
                'properties' => [
                    'self' => ['type' => 'string'],
                    'first' => ['type' => 'string'],
                    'last' => ['type' => 'string'],
                    'prev' => ['type' => 'string', 'nullable' => true],
                    'next' => ['type' => 'string', 'nullable' => true],
                ],
                'required' => ['self', 'first', 'last', 'prev', 'next'],
            ],
            'SparkPaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'current_page' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'],
                    'from' => ['type' => 'integer'],
                    'to' => ['type' => 'integer'],
                ],
                'required' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function routeParamCandidates(string $parameterName, string $modelClass): array
    {
        $short = (new ReflectionClass($modelClass))->getShortName();
        $camel = lcfirst($short);
        $snake = $this->toSnake($camel);
        $parameterSnake = $this->toSnake($parameterName);

        return array_values(array_unique([
            $parameterName,
            $parameterSnake,
            $parameterName . 'Id',
            $parameterSnake . '_id',
            $camel,
            $snake,
            $camel . 'Id',
            $snake . '_id',
            'id',
        ]));
    }

    private function toSnake(string $value): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value);
    }

    private function normalizeName(string $value): string
    {
        return strtolower(str_replace(['-', '_'], '', $value));
    }
}
