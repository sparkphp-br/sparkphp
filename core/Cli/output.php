<?php

declare(strict_types=1);

function sparkHelp(?string $command = null): void
{
    if ($command !== null) {
        $meta = sparkResolveCommandInfo($command);
        if ($meta !== null) {
            sparkRenderCommandHelp($meta);
            return;
        }

        error("Unknown command [{$command}].");
        echo "\n";
    }

    sparkRenderHelpIndex();
}

function sparkRenderHelpIndex(): void
{
    $v = SPARK_VERSION;

    $logo = <<<'ASCII'

        ███████╗██████╗  █████╗ ██████╗ ██╗  ██╗
        ██╔════╝██╔══██╗██╔══██╗██╔══██╗██║ ██╔╝
        ███████╗██████╔╝███████║██████╔╝█████╔╝
        ╚════██║██╔═══╝ ██╔══██║██╔══██╗██╔═██╗
        ███████║██║     ██║  ██║██║  ██║██║  ██╗
        ╚══════╝╚═╝     ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝

ASCII;

    echo "\n";
    echo color($logo, 'cyan');
    echo color("        Write what matters.", 'dim') . color("  v{$v}\n\n", 'dim');

    echo '  ' . color('USAGE', 'yellow') . "\n";
    echo '  ' . color('php spark', 'white') . color(' <command> [options]', 'dim') . "\n";
    echo '  ' . color('php spark help', 'white') . color(' [command]', 'dim') . "\n\n";

    $sections = [];
    foreach (sparkCommandCatalog() as $name => $meta) {
        $sections[$meta['group']][$name] = $meta;
    }

    foreach ($sections as $title => $commands) {
        echo '  ' . color($title, 'yellow') . "\n";
        foreach ($commands as $name => $meta) {
            $summary = $meta['summary'];
            if ($meta['aliases'] !== []) {
                $summary .= ' (aliases: ' . implode(', ', $meta['aliases']) . ')';
            }

            $commandLabel = str_pad($name, 22);
            $argsLabel = str_pad($meta['index_args'], 58) . '  ';
            echo '    ' . color($commandLabel, 'green') . color($argsLabel, 'dim') . $summary . "\n";
        }
        echo "\n";
    }

    out('  ' . color('Tip: ', 'yellow') . color('use php spark help <command> or php spark <command> --help for detailed command info.', 'dim'));
    echo "\n";
}

function sparkRenderCommandHelp(array $meta): void
{
    $aliases = $meta['aliases'] !== []
        ? ' ' . color('[' . implode(', ', $meta['aliases']) . ']', 'dim')
        : '';

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' command help', 'dim') . color('  v' . SPARK_VERSION, 'white'));
    echo "\n";
    out('  ' . color($meta['name'], 'green') . $aliases);
    out('  ' . color($meta['description'], 'dim'));
    echo "\n";

    out('  ' . color('USAGE', 'yellow'));
    foreach ($meta['usage'] as $usage) {
        out('    ' . color($usage, 'white'));
    }
    echo "\n";

    if ($meta['arguments'] !== []) {
        sparkRenderHelpPairs('ARGUMENTS', $meta['arguments']);
    }

    $options = $meta['options'];
    $options[] = ['-i, --info, -h, --help', 'Show this command help without executing anything.'];
    sparkRenderHelpPairs('OPTIONS', $options);

    if ($meta['examples'] !== []) {
        out('  ' . color('EXAMPLES', 'yellow'));
        foreach ($meta['examples'] as $example) {
            out('    ' . color($example, 'green'));
        }
        echo "\n";
    }

    if ($meta['caution'] !== []) {
        out('  ' . color('CAUTION', 'yellow'));
        foreach ($meta['caution'] as $line) {
            out('    ' . color($line, 'dim'));
        }
        echo "\n";
    }

    if ($meta['notes'] !== []) {
        out('  ' . color('NOTES', 'yellow'));
        foreach ($meta['notes'] as $line) {
            out('    ' . color($line, 'dim'));
        }
        echo "\n";
    }

    out('  ' . color('GUIDE', 'yellow'));
    out('    ' . color($meta['guide'], 'cyan'));
    echo "\n";
}

function sparkRenderHelpPairs(string $title, array $pairs): void
{
    out('  ' . color($title, 'yellow'));

    $width = 0;
    foreach ($pairs as [$label]) {
        $width = max($width, strlen($label));
    }

    $width = max(18, min($width, 28));

    foreach ($pairs as [$label, $description]) {
        out('    ' . color(str_pad($label, $width + 2), 'green') . color($description, 'dim'));
    }

    echo "\n";
}

// ─── CLI output helpers ──────────────────────────────────────────────────────

function color(string $text, string $style): string
{
    // Disable colors when piping or on Windows without ANSI support
    if (!stream_isatty(STDOUT) && getenv('FORCE_COLOR') === false) {
        return $text;
    }

    $codes = match ($style) {
        'reset'   => "\e[0m",
        'bold'    => "\e[1m",
        'dim'     => "\e[2m",
        'red'     => "\e[31m",
        'green'   => "\e[32m",
        'yellow'  => "\e[33m",
        'blue'    => "\e[34m",
        'magenta' => "\e[35m",
        'cyan'    => "\e[36m",
        'white'   => "\e[97m",
        'bg_red'  => "\e[41m",
        'bg_green'=> "\e[42m",
        default   => "\e[0m",
    };

    return $codes . $text . "\e[0m";
}

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function success(string $message): void
{
    out(color(' DONE ', 'bg_green') . ' ' . color($message, 'green'));
}

function error(string $message): void
{
    out(color(' ERROR ', 'bg_red') . ' ' . color($message, 'red'));
}

function sparkRenderBenchmarkReport(array $report, bool $saved): void
{
    $version = $report['spark_version'] ?? SPARK_VERSION;
    $releaseLine = $report['spark_release_line'] ?? SparkVersion::releaseLine($version);
    $profile = $report['profile']['description'] ?? 'Isolated SparkPHP benchmark fixture';
    $scenarioCount = (int) ($report['summary']['scenario_count'] ?? count($report['scenarios'] ?? []));
    $groups = implode(', ', $report['summary']['groups'] ?? []);

    echo "\n";
    out(color('  ⚡ SparkPHP', 'cyan') . color(' v' . $version, 'white') . color(' comparative benchmarks', 'dim'));
    out('  ' . color("Iterations: {$report['iterations']}  Warmup: {$report['warmup']}", 'dim'));
    out('  ' . color("Release line: {$releaseLine}", 'dim'));
    out('  ' . color("Suite: {$scenarioCount} scenarios" . ($groups !== '' ? " [{$groups}]" : ''), 'dim'));
    out('  ' . color("Fixture: {$profile}", 'dim'));
    echo "\n";

    out('  ' . color(str_pad('Scenario', 38), 'yellow')
        . color(str_pad('Avg ms', 12), 'yellow')
        . color(str_pad('Median', 12), 'yellow')
        . color(str_pad('P95', 12), 'yellow')
        . color(str_pad('Ops/s', 12), 'yellow')
        . color('Compare', 'yellow'));
    out('  ' . color(str_repeat('─', 98), 'dim'));

    foreach ($report['scenarios'] as $scenario) {
        $comparison = '—';
        if (isset($scenario['comparison']['speedup'])) {
            $speedup = number_format($scenario['comparison']['speedup'], 2);
            $comparison = $speedup . 'x vs ' . $scenario['comparison']['against'];
        }

        $scenarioName = isset($scenario['group'])
            ? $scenario['group'] . '.' . $scenario['name']
            : $scenario['name'];

        out(
            '  '
            . color(str_pad($scenarioName, 38), 'green')
            . color(str_pad(number_format($scenario['avg_ms'], 3), 12), 'white')
            . color(str_pad(number_format($scenario['median_ms'], 3), 12), 'white')
            . color(str_pad(number_format($scenario['p95_ms'], 3), 12), 'white')
            . color(str_pad(number_format($scenario['ops_per_second'], 0), 12), 'cyan')
            . color($comparison, 'dim')
        );
    }

    echo "\n";

    if (isset($report['summary']['fastest']['name'], $report['summary']['slowest']['name'])) {
        out('  ' . color(
            'Fastest: ' . $report['summary']['fastest']['name']
            . ' • Slowest: ' . $report['summary']['slowest']['name'],
            'dim'
        ));
    }

    if ($saved && isset($report['saved_to'])) {
        success('Benchmark report saved to ' . color(str_replace(SPARK_BASE . '/', '', $report['saved_to']), 'cyan'));
    }
}
