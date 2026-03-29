<?php

interface AiProvider
{
    public function name(): string;

    public function text(AiTextRequest $request): AiTextResponse;

    public function embeddings(AiEmbeddingRequest $request): AiEmbeddingResponse;

    public function image(AiImageRequest $request): AiImageResponse;

    public function audio(AiAudioRequest $request): AiAudioResponse;

    public function agent(AiAgentRequest $request): AiAgentResponse;
}

class AiException extends RuntimeException
{
}

final class AiManager
{
    private array $drivers = [];
    private array $customResolvers = [];
    private ?AiRegistry $registry = null;

    public function __construct(
        private ?Container $container = null,
        private ?string $basePath = null,
    ) {
    }

    public function driver(?string $name = null): AiClient
    {
        $name ??= $this->defaultDriver();

        return new AiClient($this->provider($name), $name, $this->registry());
    }

    public function provider(?string $name = null): AiProvider
    {
        $name ??= $this->defaultDriver();

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $provider = $this->resolveProvider($name);
        $this->drivers[$name] = $provider;

        return $provider;
    }

    public function extend(string $name, callable $resolver): void
    {
        $this->customResolvers[$name] = $resolver;
        unset($this->drivers[$name]);
    }

    public function swap(string $name, AiProvider $provider): void
    {
        $this->drivers[$name] = $provider;
    }

    public function fake(?AiProvider $provider = null, string $name = 'fake'): AiProvider
    {
        $provider ??= new AiFakeProvider();
        $this->swap($name, $provider);

        return $provider;
    }

    public function defaultDriver(): string
    {
        return trim((string) ($_ENV['AI_DRIVER'] ?? 'fake')) ?: 'fake';
    }

    public function registry(): AiRegistry
    {
        if (!$this->registry instanceof AiRegistry) {
            $this->registry = new AiRegistry($this->basePath, $this->container);
        }

        return $this->registry;
    }

    public function prompt(string $name, array $data = []): string
    {
        return $this->registry()->prompt($name, $data);
    }

    public function tool(string $name): AiTool
    {
        return $this->registry()->tool($name);
    }

    public function tools(array $names = []): array
    {
        return $this->registry()->tools($names);
    }

    public function agentDefinition(string $name): ?array
    {
        return $this->registry()->agent($name, false);
    }

    public function discoverAgents(): array
    {
        return $this->registry()->discoverAgents();
    }

    public function discoverTools(): array
    {
        return $this->registry()->discoverTools();
    }

    public function discoverPrompts(): array
    {
        return $this->registry()->discoverPrompts();
    }

    private function resolveProvider(string $name): AiProvider
    {
        if (isset($this->customResolvers[$name])) {
            $provider = ($this->customResolvers[$name])($this->container, $this->basePath);
            if (!$provider instanceof AiProvider) {
                throw new AiException("AI driver [{$name}] resolver must return an AiProvider.");
            }

            return $provider;
        }

        return match ($name) {
            'fake' => new AiFakeProvider(),
            default => throw new AiException("AI driver [{$name}] is not configured."),
        };
    }
}

final class AiRegistry
{
    private array $agentCache = [];
    private array $toolCache = [];
    private array $promptCache = [];

    public function __construct(
        private ?string $basePath = null,
        private ?Container $container = null,
    ) {
    }

    public function prompt(string $name, array $data = [], bool $required = true): ?string
    {
        $path = $this->findPromptFile($name);

        if ($path === null) {
            if ($required) {
                throw new AiException("AI prompt [{$name}] was not found.");
            }

            return null;
        }

        $cacheKey = $path . ':' . md5(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if (array_key_exists($cacheKey, $this->promptCache)) {
            return $this->promptCache[$cacheKey];
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === 'php') {
            $payload = require $path;

            if (is_callable($payload)) {
                $payload = $payload($data, $this->container, $this->basePath);
            }

            if (!is_string($payload)) {
                throw new AiException("AI prompt [{$name}] must resolve to a string.");
            }

            return $this->promptCache[$cacheKey] = $payload;
        }

        $contents = (string) file_get_contents($path);

        return $this->promptCache[$cacheKey] = $this->renderTemplate($contents, $data);
    }

    public function renderInlineTemplate(string $template, array $data = []): string
    {
        return $this->renderTemplate($template, $data);
    }

    public function agent(string $name, bool $required = true): ?array
    {
        $normalized = $this->normalizeName($name);
        if (array_key_exists($normalized, $this->agentCache)) {
            return $this->agentCache[$normalized];
        }

        $path = $this->baseDir('agents') . '/' . $normalized . '.php';

        if (!is_file($path)) {
            if ($required) {
                throw new AiException("AI agent [{$name}] was not found.");
            }

            return $this->agentCache[$normalized] = null;
        }

        $payload = require $path;
        if (is_callable($payload)) {
            $payload = $payload($this->container, $this->basePath);
        }

        if (!is_array($payload)) {
            throw new AiException("AI agent [{$name}] must return an array.");
        }

        return $this->agentCache[$normalized] = $payload;
    }

    public function tool(string $name): AiTool
    {
        $normalized = $this->normalizeName($name);
        if (isset($this->toolCache[$normalized])) {
            return $this->toolCache[$normalized];
        }

        $path = $this->baseDir('tools') . '/' . $normalized . '.php';
        if (!is_file($path)) {
            throw new AiException("AI tool [{$name}] was not found.");
        }

        $payload = require $path;

        if ($payload instanceof AiTool) {
            return $this->toolCache[$normalized] = $payload;
        }

        if (is_callable($payload)) {
            return $this->toolCache[$normalized] = AiTool::make($normalized, $payload);
        }

        if (!is_array($payload)) {
            throw new AiException("AI tool [{$name}] must return an array, callable or AiTool.");
        }

        $handle = $payload['handle'] ?? null;
        if (!is_callable($handle)) {
            throw new AiException("AI tool [{$name}] must define a callable [handle].");
        }

        return $this->toolCache[$normalized] = AiTool::make(
            $payload['name'] ?? $normalized,
            $handle,
            $payload['description'] ?? null,
            $payload['schema'] ?? []
        );
    }

    public function tools(array $names = []): array
    {
        if ($names === []) {
            $names = $this->discoverTools();
        }

        return array_map(fn(string $name): AiTool => $this->tool($name), array_values($names));
    }

    public function discoverAgents(): array
    {
        return $this->discoverPhpFiles('agents');
    }

    public function discoverTools(): array
    {
        return $this->discoverPhpFiles('tools');
    }

    public function discoverPrompts(): array
    {
        $directory = $this->baseDir('prompts');
        if (!is_dir($directory)) {
            return [];
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $names = [];

        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }

            if (!in_array($item->getExtension(), ['spark', 'md', 'txt', 'prompt', 'php'], true)) {
                continue;
            }

            $relative = str_replace($directory . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $names[] = str_replace(DIRECTORY_SEPARATOR, '/', preg_replace('/\.[^.]+$/', '', $relative) ?? $relative);
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function hasPrompt(string $name): bool
    {
        return $this->findPromptFile($name) !== null;
    }

    private function baseDir(string $segment): string
    {
        $basePath = $this->basePath;
        if ($basePath === null || $basePath === '') {
            return sys_get_temp_dir() . '/sparkphp-ai-missing';
        }

        return rtrim($basePath, '/\\') . '/app/ai/' . trim($segment, '/\\');
    }

    private function normalizeName(string $name): string
    {
        $name = trim(str_replace(['\\', '.'], '/', $name), '/');

        return $name;
    }

    private function findPromptFile(string $name): ?string
    {
        $normalized = $this->normalizeName($name);
        $base = $this->baseDir('prompts') . '/' . $normalized;

        foreach (['spark', 'md', 'txt', 'prompt', 'php'] as $extension) {
            $path = $base . '.' . $extension;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function renderTemplate(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', function (array $matches) use ($data): string {
            $value = $this->dataGet($data, $matches[1]);

            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }

            return (string) ($value ?? '');
        }, $template) ?? $template;
    }

    private function dataGet(array $data, string $key): mixed
    {
        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
                continue;
            }

            return null;
        }

        return $value;
    }

    private function discoverPhpFiles(string $segment): array
    {
        $directory = $this->baseDir($segment);
        if (!is_dir($directory)) {
            return [];
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $names = [];

        foreach ($items as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($directory . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $names[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($relative, 0, -4));
        }

        sort($names);

        return $names;
    }
}

final class AiClient
{
    public function __construct(
        private AiProvider $provider,
        private string $driver,
        private ?AiRegistry $registry = null,
    ) {
    }

    public function text(string|array|null $prompt = null): AiTextOperation
    {
        return (new AiTextOperation($this->provider, $this->driver, $this->registry))
            ->when($prompt !== null, fn(AiTextOperation $operation) => $operation->prompt($prompt));
    }

    public function embeddings(string|array|null $input = null): AiEmbeddingOperation
    {
        return (new AiEmbeddingOperation($this->provider, $this->driver, $this->registry))
            ->when($input !== null, fn(AiEmbeddingOperation $operation) => $operation->input($input));
    }

    public function retrieve(string|array|null $input = null): AiRetrievalOperation
    {
        return (new AiRetrievalOperation($this->provider, $this->driver, $this->registry))
            ->when($input !== null, fn(AiRetrievalOperation $operation) => $operation->query($input));
    }

    public function image(?string $prompt = null): AiImageOperation
    {
        return (new AiImageOperation($this->provider, $this->driver, $this->registry))
            ->when($prompt !== null, fn(AiImageOperation $operation) => $operation->prompt($prompt));
    }

    public function audio(?string $input = null): AiAudioOperation
    {
        return (new AiAudioOperation($this->provider, $this->driver, $this->registry))
            ->when($input !== null, fn(AiAudioOperation $operation) => $operation->input($input));
    }

    public function agent(?string $name = null): AiAgentOperation
    {
        return (new AiAgentOperation($this->provider, $this->driver, $this->registry))
            ->when($name !== null, fn(AiAgentOperation $operation) => $operation->name($name));
    }

    public function prompt(string $name, array $data = []): string
    {
        if (!$this->registry instanceof AiRegistry) {
            throw new AiException('AI prompt conventions require a project base path.');
        }

        return $this->registry->prompt($name, $data);
    }

    public function tool(string $name): AiTool
    {
        if (!$this->registry instanceof AiRegistry) {
            throw new AiException('AI tool conventions require a project base path.');
        }

        return $this->registry->tool($name);
    }

    public function tools(array $names = []): array
    {
        if (!$this->registry instanceof AiRegistry) {
            throw new AiException('AI tool conventions require a project base path.');
        }

        return $this->registry->tools($names);
    }

    public function discoverAgents(): array
    {
        return $this->registry?->discoverAgents() ?? [];
    }

    public function discoverTools(): array
    {
        return $this->registry?->discoverTools() ?? [];
    }

    public function discoverPrompts(): array
    {
        return $this->registry?->discoverPrompts() ?? [];
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    public function driverName(): string
    {
        return $this->driver;
    }
}

abstract class AiOperation
{
    protected ?string $model = null;
    protected array $options = [];

    public function __construct(
        protected AiProvider $provider,
        protected string $driver,
        protected ?AiRegistry $registry = null,
    ) {
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    public function options(array $options): static
    {
        foreach ($options as $key => $value) {
            $this->option((string) $key, $value);
        }

        return $this;
    }

    public function when(bool $condition, callable $callback): static
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    protected function defaultModel(string $envKey, string $fallback): string
    {
        return $this->model ?? trim((string) ($_ENV[$envKey] ?? $fallback)) ?: $fallback;
    }
}

final class AiTextOperation extends AiOperation
{
    private string|array|null $prompt = null;
    private ?string $system = null;
    private ?float $temperature = null;
    private ?int $maxTokens = null;
    private ?array $schema = null;

    public function prompt(string|array $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function system(string $system): static
    {
        $this->system = $system;

        return $this;
    }

    public function usingPrompt(string $name, array $data = []): static
    {
        if (!$this->registry instanceof AiRegistry) {
            throw new AiException('AI prompt conventions require a project base path.');
        }

        return $this->prompt($this->registry->prompt($name, $data));
    }

    public function temperature(float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function maxTokens(int $maxTokens): static
    {
        $this->maxTokens = max(1, $maxTokens);

        return $this;
    }

    public function schema(array $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function generate(): AiTextResponse
    {
        if ($this->prompt === null) {
            throw new AiException('AI text generation requires a prompt.');
        }

        return $this->provider->text(new AiTextRequest(
            prompt: $this->prompt,
            system: $this->system,
            model: $this->defaultModel('AI_TEXT_MODEL', 'spark-text'),
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            schema: $this->schema,
            options: $this->options,
        ));
    }
}

final class AiEmbeddingOperation extends AiOperation
{
    private string|array|null $input = null;

    public function input(string|array $input): static
    {
        $this->input = $input;

        return $this;
    }

    public function generate(): AiEmbeddingResponse
    {
        if ($this->input === null) {
            throw new AiException('AI embeddings require at least one input string.');
        }

        $input = is_array($this->input)
            ? array_values($this->input)
            : [$this->input];

        return $this->provider->embeddings(new AiEmbeddingRequest(
            input: $input,
            model: $this->defaultModel('AI_EMBEDDING_MODEL', 'spark-embedding'),
            options: $this->options,
        ));
    }
}

final class AiRetrievalOperation extends AiOperation
{
    private string|array|null $input = null;
    private string|QueryBuilder|null $source = null;
    private string $vectorColumn = 'embedding';
    private string $metric = 'cosine';
    private ?float $threshold = null;
    private int $limit = 3;
    private array $columns = [];

    public function query(string|array $input): static
    {
        $this->input = $input;

        return $this;
    }

    public function from(string|QueryBuilder $source, string $vectorColumn = 'embedding'): static
    {
        $this->source = $source;
        $this->vectorColumn = $vectorColumn;

        return $this;
    }

    public function column(string $vectorColumn): static
    {
        $this->vectorColumn = $vectorColumn;

        return $this;
    }

    public function metric(string $metric): static
    {
        $this->metric = $metric;

        return $this;
    }

    public function threshold(float $threshold): static
    {
        $this->threshold = $threshold;

        return $this;
    }

    public function take(int $limit): static
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    public function select(array|string ...$columns): static
    {
        if (count($columns) === 1 && is_array($columns[0])) {
            $this->columns = array_values($columns[0]);

            return $this;
        }

        $normalized = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $normalized = array_merge($normalized, array_values($column));
                continue;
            }

            $normalized[] = $column;
        }

        $this->columns = $normalized;

        return $this;
    }

    public function get(): AiRetrievalResult
    {
        if ($this->input === null) {
            throw new AiException('AI retrieval requires a query string or vector.');
        }

        if ($this->source === null) {
            throw new AiException('AI retrieval requires a source table, model or QueryBuilder.');
        }

        $builder = $this->resolveBuilder();
        $vector = $this->resolveQueryVector();

        if ($this->columns !== []) {
            $builder->select($this->columns);
        }

        $items = $builder
            ->selectVectorSimilarity($this->vectorColumn, $vector, 'vector_score', $this->metric)
            ->whereVectorSimilarTo($this->vectorColumn, $vector, $this->threshold, $this->metric)
            ->limit($this->limit)
            ->get();

        return new AiRetrievalResult(
            items: $items,
            provider: $this->provider->name(),
            model: $this->defaultModel('AI_EMBEDDING_MODEL', 'spark-embedding'),
            meta: [
                'metric' => $this->metric,
                'vector_column' => $this->vectorColumn,
                'threshold' => $this->threshold,
                'limit' => $this->limit,
                'source' => $this->describeSource(),
            ],
        );
    }

    private function resolveBuilder(): QueryBuilder
    {
        if ($this->source instanceof QueryBuilder) {
            return clone $this->source;
        }

        if (class_exists($this->source) && is_subclass_of($this->source, Model::class)) {
            /** @var class-string<Model> $model */
            $model = $this->source;

            return $model::query();
        }

        return db($this->source);
    }

    private function describeSource(): string
    {
        if ($this->source instanceof QueryBuilder) {
            return 'query:' . $this->source->toSql();
        }

        return (string) $this->source;
    }

    private function resolveQueryVector(): array
    {
        if (is_array($this->input)) {
            foreach ($this->input as $value) {
                if (!is_numeric($value)) {
                    throw new AiException('AI retrieval vectors must contain only numeric dimensions.');
                }
            }

            return array_map(static fn(mixed $value): float => (float) $value, array_values($this->input));
        }

        $response = $this->provider->embeddings(new AiEmbeddingRequest(
            input: [$this->input],
            model: $this->defaultModel('AI_EMBEDDING_MODEL', 'spark-embedding'),
            options: $this->options,
        ));

        return array_map(static fn(mixed $value): float => (float) $value, $response->first());
    }
}

final class AiImageOperation extends AiOperation
{
    private ?string $prompt = null;
    private ?string $size = null;

    public function prompt(string $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function size(string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function generate(): AiImageResponse
    {
        if ($this->prompt === null || trim($this->prompt) === '') {
            throw new AiException('AI image generation requires a prompt.');
        }

        return $this->provider->image(new AiImageRequest(
            prompt: $this->prompt,
            model: $this->defaultModel('AI_IMAGE_MODEL', 'spark-image'),
            size: $this->size ?? trim((string) ($_ENV['AI_IMAGE_SIZE'] ?? '1024x1024')),
            options: $this->options,
        ));
    }
}

final class AiAudioOperation extends AiOperation
{
    private ?string $input = null;
    private ?string $voice = null;
    private ?string $format = null;

    public function input(string $input): static
    {
        $this->input = $input;

        return $this;
    }

    public function voice(string $voice): static
    {
        $this->voice = $voice;

        return $this;
    }

    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function generate(): AiAudioResponse
    {
        if ($this->input === null || trim($this->input) === '') {
            throw new AiException('AI audio generation requires an input string.');
        }

        return $this->provider->audio(new AiAudioRequest(
            input: $this->input,
            model: $this->defaultModel('AI_AUDIO_MODEL', 'spark-audio'),
            voice: $this->voice ?? trim((string) ($_ENV['AI_AUDIO_VOICE'] ?? 'default')),
            format: $this->format ?? trim((string) ($_ENV['AI_AUDIO_FORMAT'] ?? 'mp3')),
            options: $this->options,
        ));
    }
}

final class AiAgentOperation extends AiOperation
{
    private ?string $name = null;
    private ?string $prompt = null;
    private ?string $instructions = null;
    private array $tools = [];
    private array $context = [];
    private ?float $temperature = null;
    private ?int $maxSteps = null;
    private ?array $schema = null;

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function prompt(string $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function instructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function usingPrompt(string $name, array $data = []): static
    {
        if (!$this->registry instanceof AiRegistry) {
            throw new AiException('AI prompt conventions require a project base path.');
        }

        return $this->prompt($this->registry->prompt($name, $data));
    }

    public function context(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function tool(string|AiTool $tool, ?callable $handler = null, ?string $description = null, array $schema = []): static
    {
        if (
            is_string($tool)
            && $handler === null
            && $description === null
            && $schema === []
            && $this->registry instanceof AiRegistry
        ) {
            try {
                $this->tools[] = $this->registry->tool($tool);

                return $this;
            } catch (AiException) {
                // Fall back to an inline placeholder tool when no convention file exists.
            }
        }

        $this->tools[] = $tool instanceof AiTool
            ? $tool
            : AiTool::make($tool, $handler ?? static fn() => null, $description, $schema);

        return $this;
    }

    public function tools(array $tools): static
    {
        foreach ($tools as $tool) {
            $this->tool($tool instanceof AiTool ? $tool : (string) $tool);
        }

        return $this;
    }

    public function temperature(float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function maxSteps(int $maxSteps): static
    {
        $this->maxSteps = max(1, $maxSteps);

        return $this;
    }

    public function schema(array $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function run(): AiAgentResponse
    {
        $this->hydrateFromConvention();

        if ($this->prompt === null || trim($this->prompt) === '') {
            throw new AiException('AI agents require a prompt.');
        }

        return $this->provider->agent(new AiAgentRequest(
            name: $this->name,
            prompt: $this->prompt,
            instructions: $this->instructions,
            model: $this->defaultModel('AI_AGENT_MODEL', 'spark-agent'),
            tools: $this->tools,
            context: $this->context,
            temperature: $this->temperature,
            maxSteps: $this->maxSteps ?? 3,
            schema: $this->schema,
            options: $this->options,
        ));
    }

    public function generate(): AiAgentResponse
    {
        return $this->run();
    }

    private function hydrateFromConvention(): void
    {
        if ($this->name === null || !$this->registry instanceof AiRegistry) {
            return;
        }

        $definition = $this->registry->agent($this->name, false);
        if (is_array($definition)) {
            $this->context = array_replace($definition['context'] ?? [], $this->context);
            $this->options = array_replace($definition['options'] ?? [], $this->options);
            $this->model ??= $definition['model'] ?? null;
            $this->temperature ??= $definition['temperature'] ?? null;
            $this->maxSteps ??= $definition['max_steps'] ?? null;
            $this->schema ??= $definition['schema'] ?? null;
            $this->instructions ??= $this->resolveDefinitionText(
                $definition['instructions'] ?? null,
                $definition['instructions_prompt'] ?? null
            );
            $this->prompt ??= $this->resolveDefinitionText(
                $definition['prompt'] ?? null,
                $definition['prompt_template'] ?? null
            );

            foreach (($definition['tools'] ?? []) as $tool) {
                $this->appendConventionTool($tool);
            }
        }

        if ($this->prompt === null && $this->registry->hasPrompt($this->name)) {
            $this->prompt = $this->registry->prompt($this->name, $this->context);
        }
    }

    private function resolveDefinitionText(mixed $inline, mixed $namedPrompt): ?string
    {
        if (is_string($namedPrompt) && $namedPrompt !== '') {
            return $this->registry?->prompt($namedPrompt, $this->context, false);
        }

        if (!is_string($inline) || $inline === '') {
            return null;
        }

        return $this->registry?->renderInlineTemplate($inline, $this->context) ?? $inline;
    }

    private function appendConventionTool(mixed $tool): void
    {
        if ($tool instanceof AiTool) {
            $this->tools[] = $tool;

            return;
        }

        if (is_string($tool)) {
            $this->tools[] = $this->registry?->tool($tool) ?? AiTool::make($tool, static fn() => null);

            return;
        }

        if (!is_array($tool)) {
            throw new AiException('AI agent tool definitions must be AiTool, string or array.');
        }

        if (($tool['handle'] ?? null) instanceof Closure || is_callable($tool['handle'] ?? null)) {
            $this->tools[] = AiTool::make(
                $tool['name'] ?? 'tool',
                $tool['handle'],
                $tool['description'] ?? null,
                $tool['schema'] ?? []
            );

            return;
        }

        if (isset($tool['use']) && is_string($tool['use'])) {
            $this->tools[] = $this->registry?->tool($tool['use']) ?? AiTool::make($tool['use'], static fn() => null);

            return;
        }

        throw new AiException('AI agent array tool definitions require [handle] or [use].');
    }
}

final class AiTool
{
    public function __construct(
        public string $name,
        private $handler,
        public ?string $description = null,
        public array $schema = [],
    ) {
    }

    public static function make(string $name, callable $handler, ?string $description = null, array $schema = []): static
    {
        return new static($name, $handler, $description, $schema);
    }

    public function call(array $arguments = []): mixed
    {
        return ($this->handler)($arguments);
    }

    public function manifest(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'schema' => $this->schema,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);
    }
}

final readonly class AiTextRequest
{
    public function __construct(
        public string|array $prompt,
        public ?string $system,
        public string $model,
        public ?float $temperature,
        public ?int $maxTokens,
        public ?array $schema = null,
        public array $options = [],
    ) {
    }
}

final readonly class AiEmbeddingRequest
{
    public function __construct(
        public array $input,
        public string $model,
        public array $options = [],
    ) {
    }
}

final readonly class AiImageRequest
{
    public function __construct(
        public string $prompt,
        public string $model,
        public string $size,
        public array $options = [],
    ) {
    }
}

final readonly class AiAudioRequest
{
    public function __construct(
        public string $input,
        public string $model,
        public string $voice,
        public string $format,
        public array $options = [],
    ) {
    }
}

final readonly class AiAgentRequest
{
    public function __construct(
        public ?string $name,
        public string $prompt,
        public ?string $instructions,
        public string $model,
        public array $tools = [],
        public array $context = [],
        public ?float $temperature = null,
        public int $maxSteps = 3,
        public ?array $schema = null,
        public array $options = [],
    ) {
    }
}

interface AiResponse extends JsonSerializable
{
    public function toArray(): array;
}

final readonly class AiRetrievalResult implements JsonSerializable
{
    public function __construct(
        public array $items,
        public string $provider,
        public string $model,
        public array $meta = [],
    ) {
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function toPromptContext(string|callable $formatter = 'content'): string
    {
        $chunks = [];

        foreach ($this->items as $index => $item) {
            if (is_callable($formatter)) {
                $text = $formatter($item, $index);
            } elseif (is_object($item)) {
                $text = $item->{$formatter} ?? json_encode($this->normalizeItem($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_array($item)) {
                $text = $item[$formatter] ?? json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $text = (string) $item;
            }

            $score = is_object($item) ? ($item->vector_score ?? null) : ($item['vector_score'] ?? null);
            $prefix = $score !== null ? '[' . number_format((float) $score, 4, '.', '') . '] ' : '';
            $chunks[] = $prefix . trim((string) $text);
        }

        return implode("\n\n", array_filter($chunks, static fn(string $chunk): bool => $chunk !== ''));
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(fn(mixed $item): mixed => $this->normalizeItem($item), $this->items),
            'provider' => $this->provider,
            'model' => $this->model,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function normalizeItem(mixed $item): mixed
    {
        if ($item instanceof Model) {
            return $item->toApi();
        }

        if (is_object($item)) {
            return (array) $item;
        }

        return $item;
    }
}

final readonly class AiTextResponse implements AiResponse
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?array $structured = null,
        public array $meta = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->text;
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'provider' => $this->provider,
            'model' => $this->model,
            'structured' => $this->structured,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final readonly class AiEmbeddingResponse implements AiResponse
{
    public function __construct(
        public array $vectors,
        public string $provider,
        public string $model,
        public array $meta = [],
    ) {
    }

    public function first(): array
    {
        return $this->vectors[0] ?? [];
    }

    public function toArray(): array
    {
        return [
            'vectors' => $this->vectors,
            'provider' => $this->provider,
            'model' => $this->model,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final readonly class AiImageResponse implements AiResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public string $mimeType = 'image/png',
        public array $meta = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function toArray(): array
    {
        return [
            'content' => base64_encode($this->content),
            'provider' => $this->provider,
            'model' => $this->model,
            'mime_type' => $this->mimeType,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final readonly class AiAudioResponse implements AiResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public string $mimeType = 'audio/mpeg',
        public array $meta = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function toArray(): array
    {
        return [
            'content' => base64_encode($this->content),
            'provider' => $this->provider,
            'model' => $this->model,
            'mime_type' => $this->mimeType,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final readonly class AiAgentResponse implements AiResponse
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public array $tools = [],
        public array $context = [],
        public ?array $structured = null,
        public array $meta = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->text;
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'provider' => $this->provider,
            'model' => $this->model,
            'tools' => $this->tools,
            'context' => $this->context,
            'structured' => $this->structured,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final class AiFakeProvider implements AiProvider
{
    private array $resolvers = [];

    public function __construct(array $resolvers = [])
    {
        $this->resolvers = $resolvers;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function text(AiTextRequest $request): AiTextResponse
    {
        $resolver = $this->resolvers['text'] ?? null;

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        $prompt = is_array($request->prompt)
            ? implode("\n", array_map(static fn(mixed $part): string => (string) $part, $request->prompt))
            : $request->prompt;

        $structured = $request->schema !== null
            ? $this->fakeStructured($request->schema, $prompt)
            : null;
        $text = $structured !== null
            ? (json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}')
            : 'Fake response: ' . trim($prompt);

        return new AiTextResponse(
            text: $text,
            provider: $this->name(),
            model: $request->model,
            structured: $structured,
            meta: ['driver' => 'fake'],
        );
    }

    public function embeddings(AiEmbeddingRequest $request): AiEmbeddingResponse
    {
        $resolver = $this->resolvers['embeddings'] ?? null;

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        $vectors = array_map(fn(string $input): array => $this->vectorize($input), $request->input);

        return new AiEmbeddingResponse(
            vectors: $vectors,
            provider: $this->name(),
            model: $request->model,
            meta: ['dimensions' => count($vectors[0] ?? [])],
        );
    }

    public function image(AiImageRequest $request): AiImageResponse
    {
        $resolver = $this->resolvers['image'] ?? null;

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        return new AiImageResponse(
            content: 'FAKE_IMAGE:' . $request->prompt,
            provider: $this->name(),
            model: $request->model,
            mimeType: 'image/png',
            meta: ['size' => $request->size],
        );
    }

    public function audio(AiAudioRequest $request): AiAudioResponse
    {
        $resolver = $this->resolvers['audio'] ?? null;

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        return new AiAudioResponse(
            content: 'FAKE_AUDIO:' . $request->input,
            provider: $this->name(),
            model: $request->model,
            mimeType: $request->format === 'wav' ? 'audio/wav' : 'audio/mpeg',
            meta: ['voice' => $request->voice],
        );
    }

    public function agent(AiAgentRequest $request): AiAgentResponse
    {
        $resolver = $this->resolvers['agent'] ?? null;

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        $structured = $request->schema !== null
            ? $this->fakeStructured($request->schema, $request->prompt)
            : null;
        $toolResults = [];
        $toolManifests = [];

        foreach (array_values(array_filter($request->tools, static fn(mixed $tool): bool => $tool instanceof AiTool)) as $tool) {
            $toolManifests[] = $tool->manifest();
            $arguments = $this->toolArguments($request->context, $tool->name);
            $toolResults[$tool->name] = $tool->call($arguments);
        }

        return new AiAgentResponse(
            text: $structured !== null
                ? (json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}')
                : 'Fake agent response: ' . trim($request->prompt),
            provider: $this->name(),
            model: $request->model,
            tools: $toolManifests,
            context: $request->context,
            structured: $structured,
            meta: [
                'agent' => $request->name,
                'max_steps' => $request->maxSteps,
                'tool_results' => $toolResults,
            ],
        );
    }

    public function textUsing(callable $resolver): static
    {
        $this->resolvers['text'] = $resolver;

        return $this;
    }

    public function embeddingsUsing(callable $resolver): static
    {
        $this->resolvers['embeddings'] = $resolver;

        return $this;
    }

    public function imageUsing(callable $resolver): static
    {
        $this->resolvers['image'] = $resolver;

        return $this;
    }

    public function audioUsing(callable $resolver): static
    {
        $this->resolvers['audio'] = $resolver;

        return $this;
    }

    public function agentUsing(callable $resolver): static
    {
        $this->resolvers['agent'] = $resolver;

        return $this;
    }

    private function vectorize(string $input): array
    {
        $hash = hash('sha256', $input);
        $vector = [];

        for ($offset = 0; $offset < 16; $offset += 2) {
            $segment = substr($hash, $offset, 2);
            $vector[] = round(hexdec($segment) / 255, 6);
        }

        return $vector;
    }

    private function toolArguments(array $context, string $toolName): array
    {
        $named = $context['tool_arguments'][$toolName] ?? null;
        if (is_array($named)) {
            return $named;
        }

        return array_filter(
            $context,
            static fn(string $key): bool => $key !== 'tool_arguments',
            ARRAY_FILTER_USE_KEY
        );
    }

    private function fakeStructured(array $schema, string $seed): array
    {
        $value = $this->fakeSchemaValue($schema, $seed);

        return is_array($value) ? $value : ['value' => $value];
    }

    private function fakeSchemaValue(array $schema, string $seed): mixed
    {
        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : null);

        return match ($type) {
            'object' => $this->fakeSchemaObject($schema, $seed),
            'array' => [$this->fakeSchemaValue($schema['items'] ?? ['type' => 'string'], $seed . '.0')],
            'integer' => abs(crc32($seed)) % 1000,
            'number' => round((abs(crc32($seed)) % 10000) / 100, 2),
            'boolean' => (abs(crc32($seed)) % 2) === 0,
            default => $this->fakeSchemaString($schema, $seed),
        };
    }

    private function fakeSchemaObject(array $schema, string $seed): array
    {
        $result = [];

        foreach (($schema['properties'] ?? []) as $name => $propertySchema) {
            if (!is_array($propertySchema)) {
                $propertySchema = ['type' => 'string'];
            }

            $result[$name] = $this->fakeSchemaValue($propertySchema, $seed . '.' . $name);
        }

        return $result;
    }

    private function fakeSchemaString(array $schema, string $seed): string
    {
        $hash = substr(hash('sha256', $seed), 0, 8);
        $format = $schema['format'] ?? null;

        return match ($format) {
            'email' => 'spark+' . $hash . '@example.com',
            'date-time' => '2026-03-29T12:00:00+00:00',
            'date' => '2026-03-29',
            'uuid' => '00000000-0000-4000-8000-' . str_pad(substr($hash, 0, 12), 12, '0'),
            default => 'sample-' . $hash,
        };
    }
}
