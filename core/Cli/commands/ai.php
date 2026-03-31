<?php

declare(strict_types=1);

function sparkAiStatus(array $args = []): void
{
    require_once SPARK_BASE . '/core/Ai.php';

    $json = in_array('--json', $args, true);
    $driver = trim((string) ($_ENV['AI_DRIVER'] ?? 'fake')) ?: 'fake';
    $status = [
        'spark_version' => SPARK_VERSION,
        'spark_release_line' => SparkVersion::releaseLine(SPARK_VERSION),
        'driver' => $driver,
        'provider' => null,
        'provider_status' => 'ok',
        'models' => [
            'text' => trim((string) ($_ENV['AI_TEXT_MODEL'] ?? 'spark-text')) ?: 'spark-text',
            'embeddings' => trim((string) ($_ENV['AI_EMBEDDING_MODEL'] ?? 'spark-embedding')) ?: 'spark-embedding',
            'image' => trim((string) ($_ENV['AI_IMAGE_MODEL'] ?? 'spark-image')) ?: 'spark-image',
            'audio' => trim((string) ($_ENV['AI_AUDIO_MODEL'] ?? 'spark-audio')) ?: 'spark-audio',
            'agent' => trim((string) ($_ENV['AI_AGENT_MODEL'] ?? 'spark-agent')) ?: 'spark-agent',
        ],
        'image_size' => trim((string) ($_ENV['AI_IMAGE_SIZE'] ?? '1024x1024')) ?: '1024x1024',
        'audio_voice' => trim((string) ($_ENV['AI_AUDIO_VOICE'] ?? 'default')) ?: 'default',
        'audio_format' => trim((string) ($_ENV['AI_AUDIO_FORMAT'] ?? 'mp3')) ?: 'mp3',
        'inspector' => [
            'enabled' => sparkInspectorEnabledFlag(),
            'mode' => trim((string) ($_ENV['SPARK_INSPECTOR'] ?? 'auto')) ?: 'auto',
            'ai_mask' => filter_var($_ENV['SPARK_AI_MASK'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'ai_preview' => max(40, (int) ($_ENV['SPARK_AI_TRACE_PREVIEW'] ?? 240)),
        ],
    ];

    try {
        $manager = app()->getContainer()->make(AiManager::class);
        $provider = $manager->provider($driver);
        $status['provider'] = $provider->name();
    } catch (Throwable $e) {
        $status['provider_status'] = 'error';
        $status['provider'] = $e->getMessage();
    }

    if ($json) {
        echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' AI status', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    echo "\n";
    sparkAboutSection('AI Runtime', [
        ['Driver', $status['driver']],
        ['Provider', $status['provider_status'] === 'ok' ? $status['provider'] : sparkStatusLabel(false, (string) $status['provider'])],
        ['Text Model', $status['models']['text']],
        ['Embedding Model', $status['models']['embeddings']],
        ['Image Model', $status['models']['image'] . ' @ ' . $status['image_size']],
        ['Audio Model', $status['models']['audio'] . ' @ ' . $status['audio_voice'] . '/' . $status['audio_format']],
        ['Agent Model', $status['models']['agent']],
    ]);

    sparkAboutSection('AI Observability', [
        ['Inspector', sparkStatusLabel($status['inspector']['enabled'], $status['inspector']['enabled'] ? 'enabled' : 'disabled')],
        ['Inspector Mode', $status['inspector']['mode']],
        ['AI Masking', $status['inspector']['ai_mask'] ? 'on' : 'off'],
        ['Trace Preview', $status['inspector']['ai_preview'] . ' chars'],
    ]);

    echo "\n";
}

function sparkAiSmokeTest(array $args = []): void
{
    require_once SPARK_BASE . '/core/Ai.php';
    require_once SPARK_BASE . '/core/Database.php';

    $json = in_array('--json', $args, true);
    $driver = null;
    $capability = 'all';

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--driver=')) {
            $driver = trim(substr($arg, 9)) ?: null;
            continue;
        }

        if (str_starts_with($arg, '--capability=')) {
            $capability = strtolower(trim(substr($arg, 13))) ?: 'all';
        }
    }

    $client = app()->getContainer()->make(AiManager::class)->driver($driver);
    $capabilities = $capability === 'all'
        ? ['text', 'embeddings', 'image', 'audio', 'agent']
        : [$capability];

    $results = [];
    foreach ($capabilities as $name) {
        $results[$name] = sparkAiRunSmokeCapability($client, $name);
    }

    $payload = [
        'spark_version' => SPARK_VERSION,
        'spark_release_line' => SparkVersion::releaseLine(SPARK_VERSION),
        'driver' => $client->driverName(),
        'provider' => $client->providerName(),
        'capabilities' => $results,
    ];

    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' AI smoke test', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    out('  ' . color('Driver: ' . $payload['driver'] . ' • Provider: ' . $payload['provider'], 'dim'));
    echo "\n";

    out('  ' . color(str_pad('Capability', 16), 'yellow')
        . color(str_pad('Status', 12), 'yellow')
        . color(str_pad('Latency', 12), 'yellow')
        . color(str_pad('Tokens', 12), 'yellow')
        . color('Summary', 'yellow'));
    out('  ' . color(str_repeat('─', 88), 'dim'));

    foreach ($results as $name => $result) {
        $statusLabel = ($result['status'] ?? 'ok') === 'ok' ? color('ok', 'green') : color('failed', 'red');
        $tokens = (string) ($result['tokens']['total'] ?? 0);

        out(
            '  '
            . color(str_pad($name, 16), 'cyan')
            . str_pad($statusLabel, 21)
            . color(str_pad(number_format((float) ($result['duration_ms'] ?? 0.0), 3) . ' ms', 12), 'white')
            . color(str_pad($tokens, 12), 'white')
            . color((string) ($result['summary'] ?? '-'), 'dim')
        );
    }

    echo "\n";
}

function sparkAiRunSmokeCapability(AiClient $client, string $capability): array
{
    $startedAt = microtime(true);

    try {
        $result = match ($capability) {
            'text' => sparkAiSmokeText($client),
            'embeddings' => sparkAiSmokeEmbeddings($client),
            'image' => sparkAiSmokeImage($client),
            'audio' => sparkAiSmokeAudio($client),
            'agent' => sparkAiSmokeAgent($client),
            'retrieval' => sparkAiSmokeRetrieval($client),
            default => throw new RuntimeException("Unknown AI capability [{$capability}]."),
        };
    } catch (Throwable $e) {
        return [
            'status' => 'failed',
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 3),
            'error' => $e->getMessage(),
            'summary' => $e->getMessage(),
            'tokens' => ['input' => 0, 'output' => 0, 'total' => 0],
            'cost_usd' => 0.0,
        ];
    }

    $result['status'] ??= 'ok';
    $result['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 3);

    return $result;
}

function sparkAiSmokeText(AiClient $client): array
{
    $response = $client->text('Resuma SparkPHP em uma frase.')
        ->system('Seja curto e objetivo.')
        ->generate();

    return [
        'provider' => $response->provider,
        'model' => $response->model,
        'summary' => mb_substr((string) $response, 0, 80),
        'tokens' => sparkAiUsageFromMeta($response->meta),
        'cost_usd' => sparkAiCostFromMeta($response->meta),
    ];
}

function sparkAiSmokeEmbeddings(AiClient $client): array
{
    $response = $client->embeddings(['SparkPHP observability', 'SparkPHP AI SDK'])->generate();

    return [
        'provider' => $response->provider,
        'model' => $response->model,
        'summary' => count($response->vectors) . ' vectors x ' . count($response->first()) . ' dims',
        'tokens' => sparkAiUsageFromMeta($response->meta),
        'cost_usd' => sparkAiCostFromMeta($response->meta),
    ];
}

function sparkAiSmokeImage(AiClient $client): array
{
    $response = $client->image('Uma dashboard editorial do SparkPHP')
        ->size(trim((string) ($_ENV['AI_IMAGE_SIZE'] ?? '1024x1024')) ?: '1024x1024')
        ->generate();

    return [
        'provider' => $response->provider,
        'model' => $response->model,
        'summary' => $response->mimeType . ' • ' . strlen((string) $response) . ' bytes',
        'tokens' => sparkAiUsageFromMeta($response->meta),
        'cost_usd' => sparkAiCostFromMeta($response->meta),
    ];
}

function sparkAiSmokeAudio(AiClient $client): array
{
    $response = $client->audio('SparkPHP pronto para smoke test.')
        ->voice(trim((string) ($_ENV['AI_AUDIO_VOICE'] ?? 'default')) ?: 'default')
        ->format(trim((string) ($_ENV['AI_AUDIO_FORMAT'] ?? 'mp3')) ?: 'mp3')
        ->generate();

    return [
        'provider' => $response->provider,
        'model' => $response->model,
        'summary' => $response->mimeType . ' • ' . strlen((string) $response) . ' bytes',
        'tokens' => sparkAiUsageFromMeta($response->meta),
        'cost_usd' => sparkAiCostFromMeta($response->meta),
    ];
}

function sparkAiSmokeAgent(AiClient $client): array
{
    $response = $client->agent('smoke-support')
        ->instructions('Responda de forma curta e tecnica.')
        ->tool('lookup-ticket', fn(array $arguments) => ['id' => $arguments['id'] ?? null, 'status' => 'open'], 'Consulta ticket')
        ->context([
            'ticket' => 42,
            'tool_arguments' => [
                'lookup-ticket' => ['id' => 42],
            ],
        ])
        ->prompt('Qual o status do ticket 42?')
        ->run();

    return [
        'provider' => $response->provider,
        'model' => $response->model,
        'summary' => mb_substr((string) $response, 0, 80),
        'tokens' => sparkAiUsageFromMeta($response->meta),
        'cost_usd' => sparkAiCostFromMeta($response->meta),
        'tool_calls' => count($response->tools),
    ];
}

function sparkAiSmokeRetrieval(AiClient $client): array
{
    $driver = env('DB', 'sqlite');
    $table = 'spark_ai_smoke_documents';
    $quoted = sparkWrapIdentifier($driver, $table);
    $titleColumn = sparkWrapIdentifier($driver, 'title');
    $contentColumn = sparkWrapIdentifier($driver, 'content');
    $embeddingColumn = sparkWrapIdentifier($driver, 'embedding');

    db()->statement("DROP TABLE IF EXISTS {$quoted}");

    $createSql = match ($driver) {
        'mysql' => "CREATE TABLE {$quoted} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, {$titleColumn} VARCHAR(255) NOT NULL, {$contentColumn} TEXT NULL, {$embeddingColumn} TEXT NULL)",
        'pgsql' => "CREATE TABLE {$quoted} (id BIGSERIAL PRIMARY KEY, {$titleColumn} VARCHAR(255) NOT NULL, {$contentColumn} TEXT NULL, {$embeddingColumn} TEXT NULL)",
        default => "CREATE TABLE {$quoted} (id INTEGER PRIMARY KEY AUTOINCREMENT, {$titleColumn} VARCHAR(255) NOT NULL, {$contentColumn} TEXT NULL, {$embeddingColumn} TEXT NULL)",
    };

    db()->statement($createSql);

    $documents = [
        ['title' => 'SparkPHP cache guide', 'content' => 'Cache com touch, tags e flexible.'],
        ['title' => 'SparkPHP queue guide', 'content' => 'Jobs, retries e worker file-based.'],
        ['title' => 'SparkPHP AI guide', 'content' => 'AI SDK, prompts e agentes por convencao.'],
    ];

    try {
        foreach ($documents as $document) {
            $embedding = $client->embeddings($document['title'])->generate()->first();
            db()->statement(
                "INSERT INTO {$quoted} ({$titleColumn}, {$contentColumn}, {$embeddingColumn}) VALUES (?, ?, ?)",
                [
                    $document['title'],
                    $document['content'],
                    json_encode($embedding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        }

        $retrieval = $client->retrieve('Como configuro cache?')
            ->from($table, 'embedding')
            ->select('id', 'title', 'content')
            ->take(2)
            ->get();

        return [
            'provider' => $retrieval->provider,
            'model' => $retrieval->model,
            'summary' => count($retrieval->items) . ' hits • top=' . (($retrieval->first()->title ?? null) ?: 'n/a'),
            'tokens' => sparkAiUsageFromMeta($retrieval->meta),
            'cost_usd' => sparkAiCostFromMeta($retrieval->meta),
        ];
    } finally {
        db()->statement("DROP TABLE IF EXISTS {$quoted}");
    }
}

function sparkAiUsageFromMeta(array $meta): array
{
    $usage = $meta['usage'] ?? $meta['tokens'] ?? [];
    if (!is_array($usage)) {
        return ['input' => 0, 'output' => 0, 'total' => 0];
    }

    $input = max(0, (int) ($usage['input'] ?? $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0));
    $output = max(0, (int) ($usage['output'] ?? $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0));
    $total = max($input + $output, (int) ($usage['total'] ?? $usage['total_tokens'] ?? 0));

    return ['input' => $input, 'output' => $output, 'total' => $total];
}

function sparkAiCostFromMeta(array $meta): float
{
    return round((float) ($meta['cost_usd'] ?? $meta['cost'] ?? 0.0), 6);
}

function sparkInspectorEnabledFlag(): bool
{
    $mode = strtolower(trim((string) ($_ENV['SPARK_INSPECTOR'] ?? 'auto')));

    return match ($mode) {
        'on', 'true', '1' => true,
        'off', 'false', '0' => false,
        default => strtolower((string) ($_ENV['APP_ENV'] ?? 'dev')) === 'dev',
    };
}

