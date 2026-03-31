<?php

declare(strict_types=1);

function sparkMake(string $command, array $args): void
{
    $name = $args[0] ?? null;
    if (!$name) {
        out('  ' . color('Usage: ', 'dim') . color("php spark {$command} <Name>", 'white'));
        return;
    }

    $type = substr($command, 5); // strip "make:"
    $migrationName = sparkSnake($name);
    $migrationClass = sparkStudly($name);
    $seederClass = sparkNormalizeSeederClass($name);

    $templates = [
        'model'     => [
            'path'    => "app/models/{$name}.php",
            'content' => "<?php\n\nclass {$name} extends Model\n{\n    //\n}\n",
        ],
        'middleware' => [
            'path'    => "app/middleware/{$name}.php",
            'content' => "<?php\n\n// Middleware: {$name}\n// Return a Response to block, or nothing to continue.\n",
        ],
        'migration' => [
            'path'    => 'database/migrations/' . sparkMigrationTimestamp() . "_{$migrationName}.php",
            'content' => "<?php\n\nclass {$migrationClass} extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('table_name', function (Blueprint \$table) {\n            \$table->id();\n            \$table->timestamps();\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('table_name');\n    }\n}\n",
        ],
        'seeder' => [
            'path'    => "database/seeds/{$seederClass}.php",
            'content' => "<?php\n\nclass {$seederClass} extends Seeder\n{\n    public function run(): void\n    {\n        // db('table_name')->insert([]);\n    }\n}\n",
        ],
        'service'   => [
            'path'    => "app/services/{$name}.php",
            'content' => "<?php\n\nclass {$name}\n{\n    public function __construct()\n    {\n        //\n    }\n}\n",
        ],
        'job'       => [
            'path'    => "app/jobs/{$name}.php",
            'content' => "<?php\n\nclass {$name}\n{\n    // Opcional: public string \$queue = 'default';\n    // Opcional: public int \$tries = 3;\n    // Opcional: public array|int \$backoff = [60, 120, 300];\n    // Opcional: public int|float \$timeout = 0;\n    // Opcional: public bool \$failOnTimeout = false;\n\n    public function __construct(private mixed \$data = null) {}\n\n    public function handle(): void\n    {\n        // Process the job\n    }\n}\n",
        ],
        'event'     => [
            'path'    => "app/events/{$name}.php",
            'content' => "<?php\n\n// Event: {$name}\n// \$data contains the dispatched payload.\n",
        ],
    ];

    if (!isset($templates[$type])) {
        error("Unknown make type: {$type}");
        return;
    }

    $tpl  = $templates[$type];
    $file = SPARK_BASE . '/' . $tpl['path'];
    $dir  = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (file_exists($file)) {
        out('  ' . color('Already exists: ', 'yellow') . color($tpl['path'], 'dim'));
        return;
    }

    file_put_contents($file, $tpl['content']);
    success('Created ' . color($tpl['path'], 'cyan'));
}

// ─── Queue commands ───────────────────────────────────────────────────────────

