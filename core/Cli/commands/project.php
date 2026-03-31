<?php

declare(strict_types=1);

function sparkServe(array $args): void
{
    $port = 8000;
    $dryRun = false;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--port=')) {
            $port = (int) substr($arg, 7);
        }

        if ($arg === '--dry-run') {
            $dryRun = true;
        }
    }
    $host = '0.0.0.0';
    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' v' . SPARK_VERSION, 'white') . color(" server running at ", 'dim') . color("http://localhost:{$port}", 'green'));
    out(color("     Press Ctrl+C to stop.", 'dim'));
    echo "\n";

    if ($dryRun) {
        return;
    }

    passthru("php -S {$host}:{$port} " . SPARK_BASE . '/public/index.php');
}

function sparkAbout(): void
{
    sparkBootDatabaseCore();
    require_once SPARK_BASE . '/core/ProjectScaffolder.php';

    $dbInfo = sparkAboutDatabaseInfo();
    $migrationInfo = sparkAboutMigrationInfo($dbInfo['connected']);
    $storageChecks = sparkAboutStorageChecks();
    $starter = (new ProjectScaffolder(SPARK_BASE))->currentStarter();

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' environment report', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    echo "\n";

    sparkAboutSection('Application', [
        ['Version', SPARK_VERSION],
        ['Release Line', SparkVersion::releaseLine(SPARK_VERSION)],
        ['Starter', $starter['name'] ?? 'Base'],
        ['Base Path', SPARK_BASE],
        ['Environment', $_ENV['APP_ENV'] ?? 'dev'],
        ['URL', $_ENV['APP_URL'] ?? 'http://localhost:8000'],
        ['Timezone', $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get()],
    ]);

    sparkAboutSection('Runtime', [
        ['PHP', PHP_VERSION],
        ['SAPI', PHP_SAPI],
        ['OS', php_uname('s') . ' ' . php_uname('r')],
        ['Host', php_uname('n')],
        ['Composer vendor', is_dir(SPARK_BASE . '/vendor') ? 'present' : 'missing'],
        ['Extensions', sparkAboutExtensions()],
    ]);

    sparkAboutSection('System', [
        ['CPU Cores', sparkAboutCpuCores()],
        ['System Memory', sparkAboutSystemMemory()],
        ['PHP Memory Limit', sparkAboutPhpMemoryLimit()],
        ['Memory Peak', sparkFormatBytes(memory_get_peak_usage(true))],
        ['Disk Free', sparkFormatBytes((int) disk_free_space(SPARK_BASE))],
        ['Disk Total', sparkFormatBytes((int) disk_total_space(SPARK_BASE))],
        ['Load Average', sparkAboutLoadAverage()],
        ['Uptime', sparkAboutUptime()],
    ]);

    sparkAboutSection('Database', [
        ['Driver', $dbInfo['driver']],
        ['Target', $dbInfo['target']],
        ['Connection', sparkStatusLabel($dbInfo['connected'], $dbInfo['connected'] ? 'connected' : $dbInfo['error'])],
        ['Server Version', $dbInfo['server_version']],
        ['Migrations', $migrationInfo['summary']],
        ['Pending', $migrationInfo['pending']],
        ['Repository', $migrationInfo['repository']],
        ['Latest Batch', $migrationInfo['latest_batch']],
    ]);

    sparkAboutSection('Project Health', [
        ['Storage', sparkStatusLabel($storageChecks['storage'], $storageChecks['storage'] ? 'writable' : 'not writable')],
        ['Cache Dir', sparkStatusLabel($storageChecks['cache'], $storageChecks['cache'] ? 'writable' : 'not writable')],
        ['Logs Dir', sparkStatusLabel($storageChecks['logs'], $storageChecks['logs'] ? 'writable' : 'not writable')],
        ['Sessions Dir', sparkStatusLabel($storageChecks['sessions'], $storageChecks['sessions'] ? 'writable' : 'not writable')],
        ['Seeders', is_dir(SPARK_BASE . '/database/seeds') ? 'ready' : 'missing'],
        ['Routes', sparkAboutRouteCount()],
    ]);

    echo "\n";
}

function sparkVersion(): void
{
    out('SparkPHP v' . SPARK_VERSION . ' (' . SparkVersion::releaseLine(SPARK_VERSION) . ')');
}

function sparkBenchmark(array $args): void
{
    require_once SPARK_BASE . '/core/BenchmarkRunner.php';

    $iterations = 200;
    $warmup = 15;
    $json = in_array('--json', $args, true);
    $save = SPARK_BASE . '/storage/benchmarks/latest.json';
    $noSave = in_array('--no-save', $args, true);

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--iterations=')) {
            $iterations = max(1, (int) substr($arg, 13));
        }

        if (str_starts_with($arg, '--warmup=')) {
            $warmup = max(0, (int) substr($arg, 9));
        }

        if (str_starts_with($arg, '--save=')) {
            $save = SPARK_BASE . '/' . ltrim(substr($arg, 7), '/');
        }
    }

    $runner = new BenchmarkRunner(SPARK_BASE);
    $report = $runner->run($iterations, $warmup);

    if (!$noSave) {
        $savedPath = $runner->save($report, $save);
        $report['saved_to'] = $savedPath;
    }

    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    sparkRenderBenchmarkReport($report, !$noSave);
}

function sparkInit(array $args): void
{
    require_once SPARK_BASE . '/core/ProjectScaffolder.php';

    $force = in_array('--force', $args, true);
    $quiet = in_array('--quiet', $args, true);
    $starter = null;

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--starter=')) {
            $starter = trim(substr($arg, 10));
        }
    }

    $scaffolder = new ProjectScaffolder(SPARK_BASE);
    $result = $scaffolder->initialize($force, $starter !== '' ? $starter : null);

    if ($quiet) {
        return;
    }

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' project bootstrap', 'dim'));
    foreach ($result['messages'] as $message) {
        out('  ' . color('• ', 'dim') . $message);
    }
    echo "\n";

    success('Project is ready.');
}

function sparkNew(array $args): void
{
    require_once SPARK_BASE . '/core/ProjectScaffolder.php';

    $target = null;
    $force = in_array('--force', $args, true);
    $initialize = !in_array('--no-init', $args, true);
    $json = in_array('--json', $args, true);
    $starter = null;

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--starter=')) {
            $starter = trim(substr($arg, 10));
            continue;
        }

        if (!str_starts_with($arg, '--') && $target === null) {
            $target = $arg;
        }
    }

    if ($target === null) {
        throw new RuntimeException('Usage: php spark new <directory> [--starter=api] [--force] [--no-init] [--json]');
    }

    $absoluteTarget = sparkResolvePath($target, SPARK_BASE);
    $result = (new ProjectScaffolder(SPARK_BASE))->createProject(
        $absoluteTarget,
        $force,
        $initialize,
        $starter !== '' ? $starter : null
    );

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' new project', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    out('  ' . color('Target: ', 'yellow') . color($absoluteTarget, 'cyan'));
    if (is_array($result['starter'] ?? null)) {
        out('  ' . color('Starter: ', 'yellow') . color($result['starter']['name'] . ' (' . $result['starter']['key'] . ')', 'green'));
    }
    echo "\n";

    foreach ($result['messages'] as $message) {
        out('  ' . color('• ', 'dim') . $message);
    }

    echo "\n";
    out('  ' . color('Next steps', 'yellow'));
    out('    ' . color('1.', 'dim') . ' ' . color('cd ' . $absoluteTarget, 'green'));
    out('    ' . color('2.', 'dim') . ' ' . color('composer install', 'green'));
    if ($initialize) {
        out('    ' . color('3.', 'dim') . ' ' . color('php spark serve', 'green'));
    } else {
        out('    ' . color('3.', 'dim') . ' ' . color('php spark init', 'green'));
    }
    if (is_array($result['starter'] ?? null)) {
        out('    ' . color('4.', 'dim') . ' ' . color('open ' . ($result['starter']['entrypoint'] ?? '/'), 'green'));
    }
    echo "\n";

    success('Project scaffolded successfully.');
}

function sparkUpgrade(array $args): void
{
    require_once SPARK_BASE . '/core/ProjectScaffolder.php';

    $json = in_array('--json', $args, true);
    $sync = in_array('--sync', $args, true);

    $scaffolder = new ProjectScaffolder(SPARK_BASE);

    if ($sync) {
        $result = $scaffolder->syncUpgrade();
        $audit = $result['audit'];
    } else {
        $result = ['messages' => []];
        $audit = $scaffolder->audit();
    }

    $payload = [
        'spark_version' => SPARK_VERSION,
        'spark_release_line' => SparkVersion::releaseLine(SPARK_VERSION),
        'mode' => $sync ? 'sync' : 'check',
        'messages' => $result['messages'] ?? [],
        'synced_env_keys' => $result['synced_env_keys'] ?? [],
        'audit' => $audit,
    ];

    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' upgrade assistant', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    out('  ' . color('Mode: ', 'yellow') . color($sync ? 'sync' : 'check', $sync ? 'green' : 'cyan'));
    echo "\n";

    sparkAboutSection('Upgrade Status', [
        ['Version', $audit['spark_version']],
        ['Release Line', $audit['spark_release_line']],
        ['Starter', $audit['starter_name']],
        ['Environment File', sparkStatusLabel($audit['env_exists'], $audit['env_exists'] ? 'present' : 'missing')],
        ['APP_KEY', $audit['app_key_status']],
        ['DatabaseSeeder', sparkStatusLabel($audit['database_seeder_exists'], $audit['database_seeder_exists'] ? 'present' : 'missing')],
        ['Project Ready', sparkStatusLabel($audit['ready'], $audit['ready'] ? 'aligned' : 'attention needed')],
    ]);

    sparkPrintAuditList('Missing Directories', $audit['missing_directories']);
    sparkPrintAuditList('Missing Files', $audit['missing_files']);
    sparkPrintAuditList('Missing .env Keys', $audit['missing_env_keys']);

    if (($payload['messages'] ?? []) !== []) {
        echo "\n";
        out('  ' . color('Applied', 'yellow'));
        foreach ($payload['messages'] as $message) {
            out('  ' . color('• ', 'dim') . $message);
        }
    }

    echo "\n";
    out('  ' . color('Guide: ', 'yellow') . color('docs/15-upgrade-guide.md', 'cyan'));
    echo "\n";
}

function sparkStarterList(array $args): void
{
    require_once SPARK_BASE . '/core/ProjectScaffolder.php';

    $json = in_array('--json', $args, true);
    $starters = (new ProjectScaffolder(SPARK_BASE))->listStarters();

    if ($json) {
        echo json_encode([
            'spark_version' => SPARK_VERSION,
            'spark_release_line' => SparkVersion::releaseLine(SPARK_VERSION),
            'starters' => $starters,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' starter catalog', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    echo "\n";

    foreach ($starters as $starter) {
        out('  ' . color($starter['name'], 'green') . color(' [' . $starter['key'] . ']', 'dim'));
        out('    ' . ($starter['description'] ?? ''));
        out('    ' . color('Entrypoint: ', 'yellow') . ($starter['entrypoint'] ?? '/'));
        if (($starter['focus'] ?? []) !== []) {
            out('    ' . color('Focus: ', 'yellow') . implode(', ', $starter['focus']));
        }
        echo "\n";
    }
}

// ─── Database CLI v2 ────────────────────────────────────────────────────────

