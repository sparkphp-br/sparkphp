<?php

declare(strict_types=1);

function sparkCommandSpec(
    string $group,
    string $summary,
    string $description,
    string|array $usage,
    string $indexArgs = '',
    array $arguments = [],
    array $options = [],
    array $examples = [],
    string $guide = 'docs/13-cli.md',
    array $aliases = [],
    array $caution = [],
    array $notes = [],
): array {
    return [
        'group' => $group,
        'summary' => $summary,
        'description' => $description,
        'usage' => is_array($usage) ? array_values($usage) : [$usage],
        'index_args' => $indexArgs,
        'arguments' => $arguments,
        'options' => $options,
        'examples' => $examples,
        'guide' => $guide,
        'aliases' => $aliases,
        'caution' => $caution,
        'notes' => $notes,
    ];
}

function sparkCommandCatalog(): array
{
    static $catalog = null;

    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'help' => sparkCommandSpec(
            group: 'Help',
            summary: 'Show the CLI index or detailed help for one command',
            description: 'Render the SparkPHP CLI index or inspect one command with usage, options, examples and guide references.',
            usage: [
                'php spark help',
                'php spark help <command>',
                'php spark <command> --help',
            ],
            indexArgs: '[command]',
            arguments: [
                ['[command]', 'Optional command name or alias to inspect.'],
            ],
            examples: [
                'php spark help',
                'php spark help benchmark',
                'php spark queue:work --help',
            ]
        ),
        'init' => sparkCommandSpec(
            group: 'Project',
            summary: 'Prepare the current project scaffold',
            description: 'Initialize runtime directories, copy environment defaults and optionally apply a first-party starter to the current project.',
            usage: 'php spark init [--starter=key] [--force] [--quiet]',
            indexArgs: '[--starter=] [--force] [--quiet]',
            options: [
                ['--starter=', 'Apply a specific first-party starter to the current project.'],
                ['--force', 'Overwrite starter-managed files when needed.'],
                ['--quiet', 'Run without printing the bootstrap summary.'],
            ],
            examples: [
                'php spark init',
                'php spark init --starter=docs --force',
            ],
            guide: 'docs/20-starter-kits.md'
        ),
        'new' => sparkCommandSpec(
            group: 'Project',
            summary: 'Scaffold a fresh SparkPHP project',
            description: 'Create a new project directory from the runtime scaffold, with optional starter selection and JSON output for automation.',
            usage: 'php spark new <directory> [--starter=key] [--force] [--no-init] [--json]',
            indexArgs: '<directory> [--starter=] [--force] [--no-init] [--json]',
            arguments: [
                ['<directory>', 'Destination path for the new SparkPHP project.'],
            ],
            options: [
                ['--starter=', 'Create the project with a first-party starter preset.'],
                ['--force', 'Allow scaffold generation into an existing directory.'],
                ['--no-init', 'Copy the scaffold without generating runtime files yet.'],
                ['--json', 'Emit the scaffolding result as JSON.'],
            ],
            examples: [
                'php spark new ../meu-app',
                'php spark new ../minha-api --starter=api',
                'php spark new ../meu-app --json',
            ],
            guide: 'docs/20-starter-kits.md'
        ),
        'upgrade' => sparkCommandSpec(
            group: 'Project',
            summary: 'Audit or sync the current project scaffold',
            description: 'Compare the local project with the published runtime scaffold and optionally apply the safe sync actions the upgrade assistant supports.',
            usage: 'php spark upgrade [--sync] [--json]',
            indexArgs: '[--sync] [--json]',
            options: [
                ['--sync', 'Apply the safe scaffold and .env sync actions.'],
                ['--json', 'Emit the upgrade audit or sync payload as JSON.'],
            ],
            examples: [
                'php spark upgrade',
                'php spark upgrade --json',
                'php spark upgrade --sync',
            ],
            guide: 'docs/15-upgrade-guide.md',
            notes: [
                'The sync mode creates only safe scaffold pieces such as missing directories, DatabaseSeeder and .env keys.',
            ]
        ),
        'starter:list' => sparkCommandSpec(
            group: 'Project',
            summary: 'List first-party starter kits',
            description: 'Show the starter presets available in the current runtime so the CLI and installed framework version stay aligned.',
            usage: 'php spark starter:list [--json]',
            indexArgs: '[--json]',
            options: [
                ['--json', 'Emit the starter catalog as JSON.'],
            ],
            examples: [
                'php spark starter:list',
                'php spark starter:list --json',
            ],
            guide: 'docs/20-starter-kits.md'
        ),
        'serve' => sparkCommandSpec(
            group: 'Project',
            summary: 'Start the development server',
            description: 'Run PHP built-in server with the SparkPHP banner and optionally print the banner only for smoke checks.',
            usage: 'php spark serve [--port=8000] [--dry-run]',
            indexArgs: '[--port=] [--dry-run]',
            options: [
                ['--port=', 'Port used by the built-in PHP development server.'],
                ['--dry-run', 'Print the banner and exit without opening the port.'],
            ],
            examples: [
                'php spark serve',
                'php spark serve --port=3000',
                'php spark serve --dry-run',
            ],
            guide: 'docs/01-installation.md'
        ),
        'version' => sparkCommandSpec(
            group: 'Project',
            summary: 'Show framework version and release line',
            description: 'Print the current SparkPHP version in a compact format that also exposes the release line.',
            usage: [
                'php spark version',
                'php spark --version',
                'php spark -V',
            ],
            aliases: ['--version', '-V'],
            examples: [
                'php spark version',
                'php spark --version',
                'php spark -V',
            ],
            guide: 'docs/13-cli.md'
        ),
        'about' => sparkCommandSpec(
            group: 'Project',
            summary: 'Show environment, system and database diagnostics',
            description: 'Inspect application metadata, runtime health, storage writability and database connectivity from the terminal.',
            usage: [
                'php spark about',
                'php spark doctor',
            ],
            aliases: ['doctor'],
            examples: [
                'php spark about',
                'php spark doctor',
            ],
            guide: 'docs/13-cli.md'
        ),
        'benchmark' => sparkCommandSpec(
            group: 'Benchmark',
            summary: 'Run comparative core benchmarks',
            description: 'Run the versioned SparkPHP benchmark suite across bootstrap, routing, views, container and full HTTP scenarios.',
            usage: 'php spark benchmark [--iterations=200] [--warmup=15] [--json] [--no-save] [--save=path]',
            indexArgs: '[--iterations=] [--warmup=] [--json] [--no-save] [--save=]',
            aliases: ['bench'],
            options: [
                ['--iterations=', 'Number of measured iterations to execute (minimum 1).'],
                ['--warmup=', 'Warmup iterations executed before measurement.'],
                ['--json', 'Emit the benchmark report as JSON.'],
                ['--no-save', 'Skip writing storage/benchmarks/latest.json.'],
                ['--save=', 'Persist the report to a custom path relative to the project root.'],
            ],
            examples: [
                'php spark benchmark',
                'php spark benchmark --iterations=20 --warmup=3',
                'php spark benchmark --json --no-save',
                'php spark benchmark --save=storage/benchmarks/release.json',
            ],
            guide: 'docs/23-benchmarking.md',
            caution: [
                'The benchmark writes storage/benchmarks/latest.json by default unless --no-save is used.',
            ]
        ),
        'migrate' => sparkCommandSpec(
            group: 'Database',
            summary: 'Run pending migrations',
            description: 'Execute every migration that has not been recorded yet and optionally trigger the default seeder afterward.',
            usage: 'php spark migrate [--seed]',
            indexArgs: '[--seed]',
            options: [
                ['--seed', 'Run DatabaseSeeder after the migrations finish.'],
            ],
            examples: [
                'php spark migrate',
                'php spark migrate --seed',
            ],
            guide: 'docs/05-database.md'
        ),
        'migrate:rollback' => sparkCommandSpec(
            group: 'Database',
            summary: 'Rollback the latest migration batches',
            description: 'Rollback the last batch by default, or a custom number of batches when you pass an explicit value.',
            usage: 'php spark migrate:rollback [batches]',
            indexArgs: '[batches]',
            arguments: [
                ['[batches]', 'How many migration batches should be rolled back. Default: 1.'],
            ],
            examples: [
                'php spark migrate:rollback',
                'php spark migrate:rollback 3',
            ],
            guide: 'docs/05-database.md'
        ),
        'migrate:fresh' => sparkCommandSpec(
            group: 'Database',
            summary: 'Drop all tables and re-run migrations',
            description: 'Reset the database by dropping every table, replaying the migration stack and optionally seeding afterward.',
            usage: [
                'php spark migrate:fresh [--seed]',
                'php spark db:fresh [--seed]',
            ],
            indexArgs: '[--seed]',
            aliases: ['db:fresh'],
            options: [
                ['--seed', 'Run DatabaseSeeder after the fresh migration pass.'],
            ],
            examples: [
                'php spark migrate:fresh',
                'php spark migrate:fresh --seed',
                'php spark db:fresh --seed',
            ],
            guide: 'docs/05-database.md',
            caution: [
                'This command drops every table before rebuilding the schema.',
            ]
        ),
        'migrate:status' => sparkCommandSpec(
            group: 'Database',
            summary: 'Show the migration status table',
            description: 'List every migration file and whether it has already run, including batch number and execution timestamp.',
            usage: 'php spark migrate:status',
            examples: [
                'php spark migrate:status',
            ],
            guide: 'docs/05-database.md'
        ),
        'seed' => sparkCommandSpec(
            group: 'Database',
            summary: 'Run the database seeder',
            description: 'Execute DatabaseSeeder by default or a specific seeder class when one is provided.',
            usage: 'php spark seed [SeederClass]',
            indexArgs: '[SeederClass]',
            arguments: [
                ['[SeederClass]', 'Optional seeder class name. Defaults to DatabaseSeeder.'],
            ],
            examples: [
                'php spark seed',
                'php spark seed UserSeeder',
            ],
            guide: 'docs/05-database.md'
        ),
        'db:show' => sparkCommandSpec(
            group: 'Database',
            summary: 'Show database overview',
            description: 'Print the active database connection overview with table counts, column counts and total rows.',
            usage: 'php spark db:show',
            examples: [
                'php spark db:show',
            ],
            guide: 'docs/05-database.md'
        ),
        'db:table' => sparkCommandSpec(
            group: 'Database',
            summary: 'Show the structure of one table',
            description: 'Inspect one table with columns, nullability, defaults, keys and an optional row count summary.',
            usage: 'php spark db:table <name>',
            indexArgs: '<name>',
            arguments: [
                ['<name>', 'Database table name to inspect.'],
            ],
            examples: [
                'php spark db:table users',
                'php spark db:table posts',
            ],
            guide: 'docs/05-database.md'
        ),
        'db:wipe' => sparkCommandSpec(
            group: 'Database',
            summary: 'Drop all tables without re-running migrations',
            description: 'Remove every table from the active database connection without rebuilding the schema afterward.',
            usage: 'php spark db:wipe',
            examples: [
                'php spark db:wipe',
            ],
            guide: 'docs/05-database.md',
            caution: [
                'This command permanently drops all tables and does not run migrate afterward.',
            ]
        ),
        'queue:work' => sparkCommandSpec(
            group: 'Queue',
            summary: 'Start the queue worker',
            description: 'Consume queued jobs from the file-based worker loop with optional queue selection, pacing and retry limits.',
            usage: 'php spark queue:work [--queue=name] [--sleep=3] [--max-jobs=0] [--tries=3]',
            indexArgs: '[--queue=] [--sleep=] [--max-jobs=] [--tries=]',
            options: [
                ['--queue=', 'Queue name to consume. Defaults to default.'],
                ['--sleep=', 'Seconds to wait before polling again when the queue is empty.'],
                ['--max-jobs=', 'Stop after N processed jobs. Use 0 for unlimited.'],
                ['--tries=', 'Default retry ceiling used by the worker when processing jobs.'],
            ],
            examples: [
                'php spark queue:work',
                'php spark queue:work --queue=emails --sleep=1',
                'php spark queue:work --queue=default --max-jobs=10 --tries=5',
            ],
            guide: 'docs/10-events-jobs.md'
        ),
        'queue:list' => sparkCommandSpec(
            group: 'Queue',
            summary: 'List queues with ready and delayed totals',
            description: 'Inspect the queue files available in storage and print ready, delayed and total job counts per queue.',
            usage: 'php spark queue:list',
            examples: [
                'php spark queue:list',
            ],
            guide: 'docs/10-events-jobs.md'
        ),
        'queue:inspect' => sparkCommandSpec(
            group: 'Queue',
            summary: 'Inspect one queued or failed job',
            description: 'Show the stored metadata for one job, including retries, failure reason, payload data and optional JSON output.',
            usage: 'php spark queue:inspect <job-id> [--queue=name] [--json]',
            indexArgs: '<job-id> [--queue=] [--json]',
            arguments: [
                ['<job-id>', 'Queued or failed job identifier to inspect.'],
            ],
            options: [
                ['--queue=', 'Queue to search first before scanning the defaults.'],
                ['--json', 'Emit the job payload as JSON.'],
            ],
            examples: [
                'php spark queue:inspect job_123',
                'php spark queue:inspect job_123 --queue=failed',
                'php spark queue:inspect job_123 --queue=failed --json',
            ],
            guide: 'docs/10-events-jobs.md'
        ),
        'queue:retry' => sparkCommandSpec(
            group: 'Queue',
            summary: 'Retry one failed job or a whole source queue',
            description: 'Move one failed job back to a queue, or retry every job from a source queue when --all is used.',
            usage: [
                'php spark queue:retry <job-id> [--queue=name] [--from=failed]',
                'php spark queue:retry --all [--queue=name] [--from=failed]',
            ],
            indexArgs: '<job-id> [--queue=] [--from=] [--all]',
            arguments: [
                ['<job-id>', 'Job identifier to retry when --all is not used.'],
            ],
            options: [
                ['--queue=', 'Destination queue override when retrying the job.'],
                ['--from=', 'Source queue to pull failed jobs from. Defaults to failed.'],
                ['--all', 'Retry every job found in the source queue.'],
            ],
            examples: [
                'php spark queue:retry job_123',
                'php spark queue:retry job_123 --from=failed',
                'php spark queue:retry --all --from=failed',
            ],
            guide: 'docs/10-events-jobs.md'
        ),
        'queue:clear' => sparkCommandSpec(
            group: 'Queue',
            summary: 'Clear one queue or selected jobs',
            description: 'Remove all jobs from a queue or narrow the deletion by job class, job id or failed queue selection.',
            usage: 'php spark queue:clear [queue] [--queue=name] [--failed] [--job=Class] [--id=job_123]',
            indexArgs: '[queue] [--queue=] [--failed] [--job=] [--id=]',
            arguments: [
                ['[queue]', 'Queue name used when --queue is not provided. Defaults to default.'],
            ],
            options: [
                ['--queue=', 'Explicit queue name to clear.'],
                ['--failed', 'Shortcut for clearing the failed queue.'],
                ['--job=', 'Remove only jobs that match a given class label.'],
                ['--id=', 'Remove only the job with the matching identifier.'],
            ],
            examples: [
                'php spark queue:clear',
                'php spark queue:clear failed --job=SendWelcomeEmail',
                'php spark queue:clear default --id=job_123',
            ],
            guide: 'docs/10-events-jobs.md',
            caution: [
                'This command deletes queued payloads from disk immediately.',
            ]
        ),
        'cache:clear' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Clear the application cache',
            description: 'Remove cached environment, class map, route and application cache artifacts from storage/cache.',
            usage: 'php spark cache:clear',
            examples: [
                'php spark cache:clear',
            ],
            guide: 'docs/09-session-cache.md'
        ),
        'views:cache' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Pre-compile every .spark view',
            description: 'Warm the compiled view cache by rendering every Spark template once.',
            usage: 'php spark views:cache',
            examples: [
                'php spark views:cache',
            ],
            guide: 'docs/04-views.md'
        ),
        'views:clear' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Clear compiled view cache',
            description: 'Remove compiled Spark template files from storage/cache/views.',
            usage: 'php spark views:clear',
            examples: [
                'php spark views:clear',
            ],
            guide: 'docs/04-views.md'
        ),
        'routes:cache' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Cache the route map',
            description: 'Build and persist the current route map so production boots can reuse it.',
            usage: 'php spark routes:cache',
            examples: [
                'php spark routes:cache',
            ],
            guide: 'docs/02-routing.md'
        ),
        'routes:clear' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Clear the route cache',
            description: 'Remove the cached route map so the router rebuilds it on the next load.',
            usage: 'php spark routes:clear',
            examples: [
                'php spark routes:clear',
            ],
            guide: 'docs/02-routing.md'
        ),
        'routes:list' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'List all registered routes',
            description: 'Print the effective route table including route names, middleware order and source files.',
            usage: 'php spark routes:list',
            examples: [
                'php spark routes:list',
            ],
            guide: 'docs/02-routing.md'
        ),
        'api:spec' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Generate the OpenAPI spec from routes',
            description: 'Write an OpenAPI 3.1 JSON document inferred from Spark conventions and route definitions.',
            usage: 'php spark api:spec [--output=path] [--all]',
            indexArgs: '[--output=] [--all]',
            options: [
                ['--output=', 'Custom output path relative to the project root.'],
                ['--all', 'Include non-API routes instead of only /api/* endpoints.'],
            ],
            examples: [
                'php spark api:spec',
                'php spark api:spec --output=public/openapi.json',
                'php spark api:spec --all',
            ],
            guide: 'docs/02-routing.md',
            notes: [
                'The default output path is storage/api/openapi.json.',
            ]
        ),
        'inspector:clear' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Clear Spark Inspector request history',
            description: 'Delete the persisted Spark Inspector request history files from storage.',
            usage: 'php spark inspector:clear',
            examples: [
                'php spark inspector:clear',
            ],
            guide: 'docs/13-cli.md'
        ),
        'inspector:status' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Show Spark Inspector status',
            description: 'Print whether Spark Inspector is enabled, which prefix it uses and how much history is stored.',
            usage: 'php spark inspector:status',
            examples: [
                'php spark inspector:status',
            ],
            guide: 'docs/13-cli.md'
        ),
        'optimize' => sparkCommandSpec(
            group: 'Cache & Optimize',
            summary: 'Warm production caches',
            description: 'Run the cache clear, route cache and view cache steps used to prepare a production runtime.',
            usage: 'php spark optimize',
            examples: [
                'php spark optimize',
            ],
            guide: 'docs/13-cli.md',
            caution: [
                'This command rewrites cache artifacts in storage/cache.',
            ]
        ),
        'ai:status' => sparkCommandSpec(
            group: 'AI',
            summary: 'Show AI driver, models and trace settings',
            description: 'Inspect the active AI driver, resolved provider, model defaults and Spark Inspector trace configuration.',
            usage: 'php spark ai:status [--json]',
            indexArgs: '[--json]',
            options: [
                ['--json', 'Emit the AI runtime diagnostics as JSON.'],
            ],
            examples: [
                'php spark ai:status',
                'php spark ai:status --json',
            ],
            guide: 'docs/19-ai-observability.md'
        ),
        'ai:smoke-test' => sparkCommandSpec(
            group: 'AI',
            summary: 'Run AI smoke tests for the configured provider',
            description: 'Execute smoke checks for one or more AI capabilities and report latency, tokens, cost and compact result summaries.',
            usage: 'php spark ai:smoke-test [--driver=name] [--capability=all] [--json]',
            indexArgs: '[--driver=] [--capability=] [--json]',
            options: [
                ['--driver=', 'Override the configured AI driver for this run.'],
                ['--capability=', 'Limit the smoke test to one capability such as text, retrieval or agent.'],
                ['--json', 'Emit the smoke test payload as JSON.'],
            ],
            examples: [
                'php spark ai:smoke-test',
                'php spark ai:smoke-test --capability=agent',
                'php spark ai:smoke-test --capability=retrieval --json',
            ],
            guide: 'docs/19-ai-observability.md'
        ),
        'make:model' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new model class',
            description: 'Generate a new model stub under app/models using the provided class name.',
            usage: 'php spark make:model <Name>',
            indexArgs: '<Name>',
            arguments: [
                ['<Name>', 'Model class name to generate.'],
            ],
            examples: [
                'php spark make:model User',
            ]
        ),
        'make:middleware' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new middleware stub',
            description: 'Generate a middleware file in app/middleware with inline usage guidance.',
            usage: 'php spark make:middleware <name>',
            indexArgs: '<name>',
            arguments: [
                ['<name>', 'Middleware file name to generate.'],
            ],
            examples: [
                'php spark make:middleware auth',
            ]
        ),
        'make:migration' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new migration file',
            description: 'Generate a timestamped migration class with up and down methods ready for editing.',
            usage: 'php spark make:migration <name>',
            indexArgs: '<name>',
            arguments: [
                ['<name>', 'Migration name used for the file and generated class.'],
            ],
            examples: [
                'php spark make:migration create_posts_table',
            ]
        ),
        'make:seeder' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new seeder class',
            description: 'Generate a seeder stub under database/seeds for one-off or shared seed data.',
            usage: 'php spark make:seeder <name>',
            indexArgs: '<name>',
            arguments: [
                ['<name>', 'Seeder class name to generate.'],
            ],
            examples: [
                'php spark make:seeder UserSeeder',
            ]
        ),
        'make:service' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new service class',
            description: 'Generate a simple service class under app/services for container-resolved domain logic.',
            usage: 'php spark make:service <Name>',
            indexArgs: '<Name>',
            arguments: [
                ['<Name>', 'Service class name to generate.'],
            ],
            examples: [
                'php spark make:service BillingService',
            ]
        ),
        'make:job' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new queued job class',
            description: 'Generate a queued job stub with placeholders for tries, backoff, timeout and handle logic.',
            usage: 'php spark make:job <Name>',
            indexArgs: '<Name>',
            arguments: [
                ['<Name>', 'Job class name to generate.'],
            ],
            examples: [
                'php spark make:job SendEmailJob',
            ]
        ),
        'make:event' => sparkCommandSpec(
            group: 'Scaffolding',
            summary: 'Create a new event file',
            description: 'Generate an event stub in app/events for lightweight event-driven workflows.',
            usage: 'php spark make:event <name>',
            indexArgs: '<name>',
            arguments: [
                ['<name>', 'Event file name to generate.'],
            ],
            examples: [
                'php spark make:event OrderPlaced',
            ]
        ),
    ];

    return $catalog;
}

function sparkResolveCommandInfo(?string $command): ?array
{
    if ($command === null) {
        return null;
    }

    $command = trim($command);
    if ($command === '') {
        return null;
    }

    static $lookup = null;
    if ($lookup === null) {
        $lookup = [];
        foreach (sparkCommandCatalog() as $name => $meta) {
            $lookup[$name] = $name;
            foreach ($meta['aliases'] as $alias) {
                $lookup[$alias] = $name;
            }
        }
    }

    if (!isset($lookup[$command])) {
        return null;
    }

    $name = $lookup[$command];
    $meta = sparkCommandCatalog()[$name];
    $meta['name'] = $name;

    return $meta;
}

function sparkIsInfoFlag(string $arg): bool
{
    return in_array($arg, ['-i', '--info', '-h', '--help'], true);
}

function sparkArgsContainInfoFlag(array $args): bool
{
    foreach ($args as $arg) {
        if (sparkIsInfoFlag($arg)) {
            return true;
        }
    }

    return false;
}

function sparkResolveHelpTarget(array $args): ?string
{
    foreach ($args as $arg) {
        if (sparkIsInfoFlag($arg)) {
            continue;
        }

        return $arg;
    }

    return null;
}

