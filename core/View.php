<?php

class View
{
    private string $basePath;
    private string $cacheDir;
    private bool $isDev;

    // Stacks for @css / @js / @stack
    private static array $cssStack  = [];
    private static array $jsStack   = [];
    private static array $onceKeys  = [];

    // Metadata collected during compile/render
    private static array $meta = [
        'title'     => null,
        'layout'    => 'main',
        'bodyClass' => '',
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->cacheDir = $basePath . '/storage/cache/views';
        $this->isDev    = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    // ─────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────

    public function render(string $name, array $data = []): string
    {
        $name = str_replace('.', '/', $name);
        $startedAt = microtime(true);

        // Reset stacks and meta for each top-level render
        self::$cssStack  = [];
        self::$jsStack   = [];
        self::$onceKeys  = [];
        self::$meta      = ['title' => null, 'layout' => 'main', 'bodyClass' => ''];

        $viewContent = $this->renderFile($name, $data);

        // Wrap in layout
        $layout = self::$meta['layout'];
        if (!$layout) {
            return $viewContent;
        }

        $layoutData = array_merge($data, [
            '__content'   => $viewContent,
            'title'       => self::$meta['title'] ?? env('APP_NAME', 'SparkPHP'),
            'bodyClass'   => self::$meta['bodyClass'],
        ]);

        $html = $this->renderLayout($layout, $layoutData);

        if (class_exists('SparkInspector')) {
            SparkInspector::recordView($name, 'render', (microtime(true) - $startedAt) * 1000, false, true);
        }

        return $html;
    }

    public function partial(string $name, mixed ...$args): string
    {
        $name = str_replace('.', '/', $name);
        $file = $this->basePath . "/app/views/partials/{$name}.spark";
        if (!file_exists($file)) {
            throw new \RuntimeException("Partial not found: {$name}");
        }

        // Support both positional ($user) and named (key: value) args
        $data = [];
        foreach ($args as $key => $val) {
            if (is_int($key)) {
                // positional — try to infer variable name or use generic
                $data['item'] = $val;
                if (is_object($val)) {
                    $shortName = strtolower((new \ReflectionClass($val))->getShortName());
                    $data[$shortName] = $val;
                }
            } else {
                $data[$key] = $val;
            }
        }

        return $this->renderFile("partials/{$name}", $data, false);
    }

    // ─────────────────────────────────────────────
    // File rendering
    // ─────────────────────────────────────────────

    private function renderFile(string $name, array $data, bool $resetMeta = false): string
    {
        $sparkFile   = $this->basePath . "/app/views/{$name}.spark";
        if (!file_exists($sparkFile)) {
            throw new \RuntimeException("View not found: {$name} ({$sparkFile})");
        }

        $compiledFile = $this->getCompiledPath($sparkFile);

        $compiled = false;
        $cacheHit = true;
        $startedAt = microtime(true);

        if ($this->needsRecompile($sparkFile, $compiledFile)) {
            $source   = file_get_contents($sparkFile);
            $compiledSource = $this->compile($source);
            file_put_contents($compiledFile, $compiledSource);
            $compiled = true;
            $cacheHit = false;
        }

        $html = $this->evaluateCompiled($compiledFile, $data);

        if (class_exists('SparkInspector')) {
            SparkInspector::recordView($name, 'view', (microtime(true) - $startedAt) * 1000, $compiled, $cacheHit);
        }

        return $html;
    }

    private function renderLayout(string $layout, array $data): string
    {
        $sparkFile    = $this->basePath . "/app/views/layouts/{$layout}.spark";
        if (!file_exists($sparkFile)) {
            // No layout file — just return content
            return $data['__content'] ?? '';
        }

        $compiledFile = $this->getCompiledPath($sparkFile);

        $compiled = false;
        $cacheHit = true;
        $startedAt = microtime(true);

        if ($this->needsRecompile($sparkFile, $compiledFile)) {
            $source   = file_get_contents($sparkFile);
            $compiledSource = $this->compileLayout($source);
            file_put_contents($compiledFile, $compiledSource);
            $compiled = true;
            $cacheHit = false;
        }

        $html = $this->evaluateCompiled($compiledFile, $data);

        if (class_exists('SparkInspector')) {
            SparkInspector::recordView($layout, 'layout', (microtime(true) - $startedAt) * 1000, $compiled, $cacheHit);
        }

        return $html;
    }

    private function evaluateCompiled(string $compiledFile, array $data): string
    {
        // Inject the View instance so partials/components can call back
        $data['__view'] = $this;
        $data['__data'] = $data;

        extract($data, EXTR_SKIP);
        ob_start();
        require $compiledFile;
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────
    // Cache management
    // ─────────────────────────────────────────────

    private function getCompiledPath(string $sparkFile): string
    {
        $hash = md5($sparkFile);
        return $this->cacheDir . '/' . $hash . '.php';
    }

    private function needsRecompile(string $sparkFile, string $compiledFile): bool
    {
        if (!file_exists($compiledFile)) {
            return true;
        }
        if ($this->isDev) {
            return filemtime($sparkFile) > filemtime($compiledFile);
        }
        return false;
    }

    // ─────────────────────────────────────────────
    // Compiler — view files
    // ─────────────────────────────────────────────

    public function compile(string $source): string
    {
        $source = $this->compileMetaDirectives($source);
        $source = $this->compileDirectives($source);
        $source = $this->compileEchos($source);
        return $source;
    }

    public function compileLayout(string $source): string
    {
        $source = $this->compileDirectives($source);
        $source = $this->compileEchos($source);
        // @content → output the view content
        $source = str_replace('@content', '<?php echo $__content ?? \'\'; ?>', $source);
        return $source;
    }

    // ─────────────────────────────────────────────
    // Meta directives (parsed first, removed from output)
    // ─────────────────────────────────────────────

    private function compileMetaDirectives(string $source): string
    {
        // @layout('name')
        $source = preg_replace_callback(
            '/@layout\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?php \\View::setMeta('layout', '{$m[1]}'); ?>",
            $source
        );

        // @title('text')
        $source = preg_replace_callback(
            '/@title\((.+?)\)/',
            fn($m) => "<?php \\View::setMeta('title', {$m[1]}); ?>",
            $source
        );

        // @bodyClass('classes')
        $source = preg_replace_callback(
            '/@bodyClass\((.+?)\)/',
            fn($m) => "<?php \\View::setMeta('bodyClass', {$m[1]}); ?>",
            $source
        );

        // @css('name') or @css('https://...')
        $source = preg_replace_callback(
            '/@css\((.+?)\)/',
            fn($m) => "<?php \\View::pushCss({$m[1]}); ?>",
            $source
        );

        // @js('name') or @js('https://...')
        $source = preg_replace_callback(
            '/@js\((.+?)\)/',
            fn($m) => "<?php \\View::pushJs({$m[1]}); ?>",
            $source
        );

        return $source;
    }

    // ─────────────────────────────────────────────
    // Main directive compiler
    // ─────────────────────────────────────────────

    private function compileDirectives(string $source): string
    {
        // @stack('css'|'js')
        $source = preg_replace_callback(
            '/@stack\([\'"](\w+)[\'"]\)/',
            fn($m) => "<?php echo \\View::renderStack('{$m[1]}'); ?>",
            $source
        );

        // @content (in layout)
        $source = str_replace('@content', '<?php echo $__content ?? \'\'; ?>', $source);

        // @partial('name') or @partial('name', $var) or @partial('name', key: val, ...)
        $source = preg_replace_callback(
            '/@partial\((.+?)\)(?!\s*\{)/s',
            fn($m) => "<?php echo \$__view->partial({$m[1]}); ?>",
            $source
        );

        // @component / @endcomponent / @slot / @endslot / @hasslot / @endhasslot
        $source = $this->compileComponents($source);

        // @foreach / @empty / @endforeach (with $loop variable)
        $source = $this->compileForeach($source);

        // @for / @endfor
        $source = preg_replace('/@for\((.+?)\)/', '<?php for ($1): ?>', $source);
        $source = str_replace('@endfor', '<?php endfor; ?>', $source);

        // @while / @endwhile
        $source = preg_replace('/@while\((.+?)\)/', '<?php while ($1): ?>', $source);
        $source = str_replace('@endwhile', '<?php endwhile; ?>', $source);

        // @repeat(N) / @endrepeat
        $source = preg_replace('/@repeat\((\d+)\)/', '<?php for ($__ri = 0; $__ri < $1; $__ri++): ?>', $source);
        $source = str_replace('@endrepeat', '<?php endfor; ?>', $source);

        // @first / @endfirst (inside foreach context)
        $source = str_replace('@first', '<?php if ($loop->first): ?>', $source);
        $source = str_replace('@endfirst', '<?php endif; ?>', $source);

        // @last / @endlast
        $source = str_replace('@last', '<?php if ($loop->last): ?>', $source);
        $source = str_replace('@endlast', '<?php endif; ?>', $source);

        // @if / @elseif / @else / @endif
        $source = $this->compileBalancedDirective(
            $source,
            'if',
            fn(string $expression) => "<?php if ({$expression}): ?>"
        );
        $source = $this->compileBalancedDirective(
            $source,
            'elseif',
            fn(string $expression) => "<?php elseif ({$expression}): ?>"
        );
        $source = preg_replace_callback('/@else(?!\w)/', fn() => '<?php else: ?>', $source);
        $source = str_replace('@endif', '<?php endif; ?>', $source);

        // @auth / @endauth
        $source = preg_replace_callback('/@auth(?!\w)/', fn() => '<?php if (auth()): ?>', $source);
        $source = str_replace('@endauth', '<?php endif; ?>', $source);

        // @role('name') / @endrole
        $source = preg_replace('/@role\((.+?)\)/', '<?php if (auth() && method_exists(auth(), \'hasRole\') && auth()->hasRole($1)): ?>', $source);
        $source = str_replace('@endrole', '<?php endif; ?>', $source);

        // @can('action', $obj) / @endcan
        $source = preg_replace('/@can\((.+?)\)/', '<?php if (auth() && method_exists(auth(), \'can\') && auth()->can($1)): ?>', $source);
        $source = str_replace('@endcan', '<?php endif; ?>', $source);

        // @dev / @enddev
        $source = preg_replace_callback('/@dev(?!\w)/', fn() => "<?php if (env('APP_ENV') === 'dev'): ?>", $source);
        $source = str_replace('@enddev', '<?php endif; ?>', $source);

        // @prod / @endprod
        $source = preg_replace_callback('/@prod(?!\w)/', fn() => "<?php if (env('APP_ENV') === 'production'): ?>", $source);
        $source = str_replace('@endprod', '<?php endif; ?>', $source);

        // @once / @endonce
        $source = preg_replace_callback(
            '/@once\s*(.*?)@endonce/s',
            function ($m) {
                $key = md5($m[1]);
                return "<?php if (\\View::once('{$key}')): ?>" . $m[1] . "<?php endif; ?>";
            },
            $source
        );

        // @php / @endphp
        $source = str_replace('@php', '<?php', $source);
        $source = str_replace('@endphp', '?>', $source);

        // ── Form helpers ──────────────────────────

        // @form('url', 'METHOD') / @endform
        $source = preg_replace_callback(
            '/@form\((.+?)\)/',
            fn($m) => "<?php echo \\View::openForm({$m[1]}); ?>",
            $source
        );
        $source = str_replace('@endform', '</form>', $source);

        // @input(...) @select(...) @checkbox(...) @radio(...) @file(...) @hidden(...) @submit(...)
        foreach (['input','select','checkbox','radio','file'] as $field) {
            $source = preg_replace_callback(
                "/@{$field}\((.+?)\)/s",
                fn($m) => "<?php echo \\View::field('{$field}', {$m[1]}); ?>",
                $source
            );
        }
        $source = preg_replace_callback(
            '/@hidden\((.+?)\)/',
            fn($m) => "<?php echo \\View::hiddenField({$m[1]}); ?>",
            $source
        );
        $source = preg_replace_callback(
            '/@submit\((.+?)\)/',
            fn($m) => "<?php echo \\View::submitBtn({$m[1]}); ?>",
            $source
        );
        $source = preg_replace_callback(
            '/@group\b/',
            fn() => '<?php echo \'<div class="form-group-row">\'; ?>',
            $source
        );
        $source = str_replace('@endgroup', '<?php echo \'</div>\'; ?>', $source);

        // ── Cache fragments ────────────────────────
        $source = preg_replace_callback(
            '/@cache\((.+?)\)\s*(.*?)@endcache/s',
            function ($m) {
                $args     = $m[1];
                $compiled = var_export($this->compile($m[2]), true);
                return "<?php echo \\View::cacheFragment({$args}, {$compiled}, get_defined_vars()); ?>";
            },
            $source
        );

        // ── Lazy loading ────────────────────────────
        $source = preg_replace_callback(
            '/@lazy\((.+?)\)\s*(.*?)@endlazy/s',
            function ($m) {
                $args        = $m[1];
                $placeholder = trim($m[2]);
                $id          = 'lazy-' . md5($args);
                return <<<PHP
                <?php
                \$__lazyArgs = [{$args}];
                \$__lazyUrl  = \$__lazyArgs[0];
                \$__lazyTrigger = \$__lazyArgs['trigger'] ?? 'load';
                \$__lazyDelay   = \$__lazyArgs['delay']   ?? 0;
                ?>
                <div id="{$id}" data-lazy="\<?php echo \$__lazyUrl; ?>"
                     data-trigger="\<?php echo \$__lazyTrigger; ?>"
                     data-delay="\<?php echo \$__lazyDelay; ?>">
                {$placeholder}
                </div>
                <?php echo \\View::lazyScript('{$id}'); ?>
                PHP;
            },
            $source
        );

        // ── HTML helpers ────────────────────────────

        // @active('/path') or @active('/path', 'class')
        $source = preg_replace_callback(
            '/@active\((.+?)\)/',
            fn($m) => "<?php echo \\View::activeClass({$m[1]}); ?>",
            $source
        );

        // @img('file', alt: '', ...)
        $source = preg_replace_callback(
            '/@img\((.+?)\)/',
            fn($m) => "<?php echo \\View::imgTag({$m[1]}); ?>",
            $source
        );

        // @icon('name', ...)
        $source = preg_replace_callback(
            '/@icon\((.+?)\)/',
            fn($m) => "<?php echo \\View::iconTag({$m[1]}); ?>",
            $source
        );

        // @json($data)
        $source = preg_replace_callback(
            '/@json\((.+?)\)/',
            fn($m) => "<?php echo \\View::jsonOutput({$m[1]}); ?>",
            $source
        );

        // @meta(...)
        $source = preg_replace_callback(
            '/@meta\((.+?)\)/s',
            fn($m) => "<?php echo \\View::metaTags([{$m[1]}]); ?>",
            $source
        );

        // @paginate($collection)
        $source = preg_replace_callback(
            '/@paginate\((.+?)\)/',
            fn($m) => "<?php echo \\View::pagination({$m[1]}); ?>",
            $source
        );

        return $source;
    }

    private function compileBalancedDirective(string $source, string $directive, callable $compiler): string
    {
        $needle = '@' . $directive . '(';
        $result = '';
        $offset = 0;

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $open = $start + strlen($needle) - 1;
            $close = $this->findMatchingParenthesis($source, $open);

            if ($close === null) {
                break;
            }

            $expression = substr($source, $open + 1, $close - $open - 1);

            $result .= substr($source, $offset, $start - $offset);
            $result .= $compiler($expression);
            $offset = $close + 1;
        }

        return $result . substr($source, $offset);
    }

    private function findMatchingParenthesis(string $source, int $openOffset): ?int
    {
        $depth   = 0;
        $quote   = null;
        $escaped = false;
        $length  = strlen($source);

        for ($i = $openOffset; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // Foreach with $loop variable
    // ─────────────────────────────────────────────

    private function compileForeach(string $source): string
    {
        return preg_replace_callback(
            '/@foreach\((.+?)\)\s*(.*?)(?:@empty\s*(.*?))?@endforeach/s',
            function ($matches) {
                $expr     = $matches[1];  // $users as $user
                $body     = $matches[2];
                $empty    = $matches[3] ?? '';

                // Extract collection variable
                preg_match('/^(.+?)\s+as\s+/i', $expr, $collMatch);
                $coll = trim($collMatch[1] ?? '$__items');

                $loopVar = '__loop_' . md5($expr);

                $php  = "<?php \${$loopVar} = {$coll}; ?>";
                $php .= "<?php if (!empty(\${$loopVar})): ?>";
                $php .= "<?php \$__loop_total = count(\${$loopVar}); \$__loop_idx = 0; ?>";
                $php .= "<?php foreach ({$expr}): ?>";
                $php .= "<?php \$loop = (object)[";
                $php .= "'index'     => \$__loop_idx,";
                $php .= "'iteration' => \$__loop_idx + 1,";
                $php .= "'first'     => \$__loop_idx === 0,";
                $php .= "'last'      => \$__loop_idx === \$__loop_total - 1,";
                $php .= "'even'      => \$__loop_idx % 2 === 1,";
                $php .= "'odd'       => \$__loop_idx % 2 === 0,";
                $php .= "'count'     => \$__loop_total,";
                $php .= "'remaining' => \$__loop_total - \$__loop_idx - 1,";
                $php .= "'parent'    => null,";
                $php .= "]; \$__loop_idx++; ?>";
                $php .= $body;
                $php .= "<?php endforeach; ?>";
                $php .= "<?php else: ?>";
                $php .= $empty;
                $php .= "<?php endif; ?>";

                return $php;
            },
            $source
        );
    }

    // ─────────────────────────────────────────────
    // Components
    // ─────────────────────────────────────────────

    private function compileComponents(string $source): string
    {
        // @component('name', prop: val) ... @endcomponent
        $source = preg_replace_callback(
            '/@component\((.+?)\)(.*?)@endcomponent/s',
            function ($m) {
                $args    = $m[1];
                $content = $m[2];

                // Parse @slot('name') ... @endslot blocks from content
                $slots   = [];
                $default = preg_replace_callback(
                    '/@slot\([\'"](\w+)[\'"]\)(.*?)@endslot/s',
                    function ($sm) use (&$slots) {
                        $slots[$sm[1]] = trim($sm[2]);
                        return '';
                    },
                    $content
                );
                $slots['body'] = $slots['body'] ?? trim($default);

                $slotsExport = var_export($slots, true);

                return "<?php echo \$__view->renderComponent({$args}, {$slotsExport}); ?>";
            },
            $source
        );

        // @hasslot('name') / @endhasslot
        $source = preg_replace(
            '/@hasslot\([\'"](\w+)[\'"]\)/',
            '<?php if (!empty($__slots[\'$1\'])): ?>',
            $source
        );
        $source = str_replace('@endhasslot', '<?php endif; ?>', $source);

        // @slot('name') inside component definition
        $source = preg_replace(
            '/@slot\([\'"](\w+)[\'"]\)/',
            '<?php echo $__slots[\'$1\'] ?? \'\'; ?>',
            $source
        );

        return $source;
    }

    public function renderComponent(string $name, array $props, array $slots): string
    {
        $file = $this->basePath . "/app/views/partials/{$name}.spark";
        if (!file_exists($file)) {
            throw new \RuntimeException("Component not found: {$name}");
        }

        $compiled = $this->getCompiledPath($file);
        if ($this->needsRecompile($file, $compiled)) {
            file_put_contents($compiled, $this->compile(file_get_contents($file)));
        }

        $data = array_merge($props, ['__slots' => $slots, '__view' => $this]);
        extract($data, EXTR_SKIP);
        ob_start();
        require $compiled;
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────
    // Echo compilation {{ }} and {!! !!}
    // ─────────────────────────────────────────────

    private function compileEchos(string $source): string
    {
        // {!! raw !!}
        $source = preg_replace('/\{!!\s*(.+?)\s*!!\}/', '<?php echo $1; ?>', $source);

        // {{ $var | pipe1 | pipe2 }}
        $source = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            function ($m) {
                $expr = trim($m[1]);

                // Has pipes?
                if (str_contains($expr, '|')) {
                    return $this->compilePipedEcho($expr);
                }

                return "<?php echo \\View::e({$expr}); ?>";
            },
            $source
        );

        return $source;
    }

    private function compilePipedEcho(string $expr): string
    {
        // Split on | but not inside strings or function calls
        $parts = preg_split('/\s*\|\s*(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $expr);
        $value = array_shift($parts);

        $php = $value;
        foreach ($parts as $pipe) {
            // pipe with args: date:'d/m/Y'  or  limit:100
            if (str_contains($pipe, ':')) {
                [$name, $arg] = explode(':', $pipe, 2);
                $php = "\\View::pipe({$php}, '{$name}', {$arg})";
            } else {
                $php = "\\View::pipe({$php}, '{$pipe}')";
            }
        }

        return "<?php echo \\View::e({$php}); ?>";
    }

    // ─────────────────────────────────────────────
    // Static: meta management
    // ─────────────────────────────────────────────

    public static function setMeta(string $key, mixed $value): void
    {
        self::$meta[$key] = $value;
    }

    public static function getMeta(string $key): mixed
    {
        return self::$meta[$key] ?? null;
    }

    public static function pushCss(string $asset): void
    {
        self::$cssStack[] = $asset;
    }

    public static function pushJs(string $asset): void
    {
        self::$jsStack[] = $asset;
    }

    public static function renderStack(string $type): string
    {
        $base = $_ENV['APP_URL'] ?? '';
        $items = $type === 'css' ? self::$cssStack : self::$jsStack;
        $html  = '';

        foreach ($items as $asset) {
            $url = (str_starts_with($asset, 'http') || str_starts_with($asset, '//'))
                ? $asset
                : "{$base}/public/{$type}/{$asset}.{$type}";

            $html .= $type === 'css'
                ? "<link rel=\"stylesheet\" href=\"{$url}\">\n"
                : "<script src=\"{$url}\"></script>\n";
        }

        return $html;
    }

    public static function once(string $key): bool
    {
        if (in_array($key, self::$onceKeys, true)) {
            return false;
        }
        self::$onceKeys[] = $key;
        return true;
    }

    // ─────────────────────────────────────────────
    // Static: output helpers
    // ─────────────────────────────────────────────

    /**
     * Context-aware escape.
     */
    public static function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Pipe transformer.
     */
    public static function pipe(mixed $value, string $pipe, mixed $arg = null): mixed
    {
        return match ($pipe) {
            'money'    => 'R$ ' . number_format((float) $value, 2, ',', '.'),
            'date'     => $arg
                            ? (new \DateTime($value))->format($arg)
                            : (new \DateTime($value))->format('d/m/Y'),
            'relative' => self::relativeTime($value),
            'limit'    => mb_strlen((string) $value) > (int) $arg
                            ? mb_substr((string) $value, 0, (int) $arg) . '…'
                            : $value,
            'safe_limit' => self::safeTruncate((string) $value, (int) $arg),
            'upper'    => mb_strtoupper((string) $value),
            'lower'    => mb_strtolower((string) $value),
            'title'    => mb_convert_case((string) $value, MB_CASE_TITLE),
            'initials' => self::initials((string) $value),
            'number'   => number_format((float) $value, 2, ',', '.'),
            'bytes'    => self::formatBytes((int) $value),
            'count'    => is_countable($value) ? count($value) : 0,
            'slug'     => self::slugify((string) $value),
            'nl2br'    => nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')),
            'markdown' => self::parseMarkdown((string) $value),
            'default'  => ($value === null || $value === '') ? $arg : $value,
            default    => $value,
        };
    }

    private static function relativeTime(string $value): string
    {
        $diff = time() - strtotime($value);
        if ($diff < 60)      return "há {$diff} segundos";
        if ($diff < 3600)    return 'há ' . floor($diff / 60) . ' minutos';
        if ($diff < 86400)   return 'há ' . floor($diff / 3600) . ' horas';
        if ($diff < 2592000) return 'há ' . floor($diff / 86400) . ' dias';
        if ($diff < 31536000)return 'há ' . floor($diff / 2592000) . ' meses';
        return 'há ' . floor($diff / 31536000) . ' anos';
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $result = '';
        foreach ($words as $word) {
            if ($word !== '') {
                $result .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }
        return $result;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        return "{$bytes} B";
    }

    private static function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[àáâãäå]/u', 'a', $text);
        $text = preg_replace('/[èéêë]/u',   'e', $text);
        $text = preg_replace('/[ìíîï]/u',   'i', $text);
        $text = preg_replace('/[òóôõö]/u',  'o', $text);
        $text = preg_replace('/[ùúûü]/u',   'u', $text);
        $text = preg_replace('/[ç]/u',      'c', $text);
        $text = preg_replace('/[^a-z0-9\s-]/u', '', $text);
        $text = preg_replace('/[\s-]+/', '-', trim($text));
        return trim($text, '-');
    }

    private static function safeTruncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $truncated = mb_substr($text, 0, $limit);
        // Don't cut in the middle of an HTML tag
        $truncated = preg_replace('/<[^>]*$/', '', $truncated);
        return $truncated . '…';
    }

    private static function parseMarkdown(string $text): string
    {
        // Basic markdown: bold, italic, links, code, headings, lists
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = nl2br($text);
        return $text;
    }

    // ─────────────────────────────────────────────
    // Static: form helpers
    // ─────────────────────────────────────────────

    public static function openForm(string $action, string $method = 'POST', array $attrs = []): string
    {
        $method  = strtoupper($method);
        $htmlMethod = in_array($method, ['GET', 'POST']) ? $method : 'POST';
        $spoof   = !in_array($method, ['GET', 'POST'])
            ? "<input type=\"hidden\" name=\"_method\" value=\"{$method}\">"
            : '';
        $csrf    = $method !== 'GET'
            ? '<input type="hidden" name="_csrf" value="' . csrf() . '">'
            : '';

        $attrStr = '';
        foreach ($attrs as $k => $v) {
            $attrStr .= " {$k}=\"" . htmlspecialchars($v, ENT_QUOTES) . '"';
        }

        return "<form method=\"{$htmlMethod}\" action=\"{$action}\"{$attrStr}>{$spoof}{$csrf}";
    }

    public static function field(string $type, string $name, mixed ...$args): string
    {
        // Collect named args
        $opts = [];
        foreach ($args as $key => $val) {
            if (is_string($key)) {
                $opts[$key] = $val;
            }
        }

        $label    = $opts['label']    ?? ucfirst($name);
        $value    = $opts['value']    ?? old($name, '');
        $required = !empty($opts['required']) ? ' required' : '';
        $disabled = !empty($opts['disabled']) ? ' disabled' : '';
        $hint     = $opts['hint']     ?? '';
        $class    = $opts['class']    ?? '';
        $id       = $opts['id']       ?? $name;
        $error    = errors($name);
        $errorCls = $error ? ' is-invalid' : '';
        $errorHtml= $error ? "<span class=\"field-error\">" . self::e($error) . "</span>" : '';
        $hintHtml = $hint  ? "<small class=\"field-hint\">" . self::e($hint) . "</small>" : '';

        $labelHtml = "<label for=\"{$id}\">" . self::e($label) . ($required ? ' <span>*</span>' : '') . "</label>";

        $inputHtml = match ($type) {
            'textarea' => self::textareaHtml($name, $id, $value, $opts, $required, $disabled, $errorCls),
            'select'   => self::selectHtml($name, $id, $value, $opts, $required, $disabled, $errorCls),
            'checkbox' => self::checkboxHtml($name, $id, $opts, $disabled, $errorCls),
            'radio'    => self::radioGroupHtml($name, $opts, $errorCls),
            'file'     => "<input type=\"file\" name=\"{$name}\" id=\"{$id}\" class=\"field-input {$errorCls} {$class}\"{$required}{$disabled}>",
            default    => "<input type=\"{$type}\" name=\"{$name}\" id=\"{$id}\" value=\"" . self::e($value) . "\" class=\"field-input {$errorCls} {$class}\"{$required}{$disabled}>",
        };

        return "<div class=\"field{$errorCls}\">{$labelHtml}{$inputHtml}{$errorHtml}{$hintHtml}</div>";
    }

    private static function textareaHtml(string $name, string $id, mixed $value, array $opts, string $required, string $disabled, string $errCls): string
    {
        $rows = $opts['rows'] ?? 4;
        return "<textarea name=\"{$name}\" id=\"{$id}\" rows=\"{$rows}\" class=\"field-input {$errCls}\"{$required}{$disabled}>" . self::e($value) . "</textarea>";
    }

    private static function selectHtml(string $name, string $id, mixed $current, array $opts, string $required, string $disabled, string $errCls): string
    {
        $options = $opts['options'] ?? [];
        $html    = "<select name=\"{$name}\" id=\"{$id}\" class=\"field-input {$errCls}\"{$required}{$disabled}>";
        foreach ($options as $val => $label) {
            $sel   = (string) $val === (string) $current ? ' selected' : '';
            $html .= "<option value=\"" . self::e($val) . "\"{$sel}>" . self::e($label) . "</option>";
        }
        return $html . '</select>';
    }

    private static function checkboxHtml(string $name, string $id, array $opts, string $disabled, string $errCls): string
    {
        $checked = !empty($opts['checked']) ? ' checked' : '';
        return "<input type=\"checkbox\" name=\"{$name}\" id=\"{$id}\" value=\"1\" class=\"field-checkbox {$errCls}\"{$checked}{$disabled}>";
    }

    private static function radioGroupHtml(string $name, array $opts, string $errCls): string
    {
        $options = $opts['options'] ?? [];
        $current = old($name, $opts['value'] ?? '');
        $html    = '<div class="radio-group">';
        foreach ($options as $val => $label) {
            $checked = (string) $val === (string) $current ? ' checked' : '';
            $html   .= "<label><input type=\"radio\" name=\"{$name}\" value=\"" . self::e($val) . "\"{$checked}> " . self::e($label) . "</label>";
        }
        return $html . '</div>';
    }

    public static function hiddenField(string $name, mixed $value = ''): string
    {
        return "<input type=\"hidden\" name=\"" . self::e($name) . "\" value=\"" . self::e($value) . "\">";
    }

    public static function submitBtn(string $text = 'Enviar', array $attrs = []): string
    {
        $cls = $attrs['class'] ?? 'btn btn-primary';
        return "<button type=\"submit\" class=\"{$cls}\">" . self::e($text) . "</button>";
    }

    // ─────────────────────────────────────────────
    // Static: HTML helpers
    // ─────────────────────────────────────────────

    public static function activeClass(string $path, string $class = 'active'): string
    {
        $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $current === $path ? "class=\"{$class}\"" : '';
    }

    public static function imgTag(string $src, mixed ...$attrs): string
    {
        $base = $_ENV['APP_URL'] ?? '';
        $url  = str_starts_with($src, 'http') ? $src : "{$base}/public/images/{$src}";

        $attrStr = " src=\"{$url}\"";
        foreach ($attrs as $key => $val) {
            if (is_string($key)) {
                $attrStr .= " {$key}=\"" . self::e($val) . '"';
            }
        }

        return "<img{$attrStr}>";
    }

    public static function iconTag(string $name, mixed ...$attrs): string
    {
        $class = $attrs['class'] ?? '';
        $size  = $attrs['size']  ?? 24;
        // Inline SVG use — assumes sprite or fallback to text
        return "<span class=\"icon icon-{$name} {$class}\" style=\"font-size:{$size}px;width:{$size}px;height:{$size}px;\"></span>";
    }

    public static function jsonOutput(mixed $data): string
    {
        return htmlspecialchars(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    public static function metaTags(array $opts): string
    {
        $html = '';
        if (isset($opts['description'])) {
            $html .= '<meta name="description" content="' . self::e($opts['description']) . '">' . "\n";
        }
        if (isset($opts['keywords'])) {
            $html .= '<meta name="keywords" content="' . self::e($opts['keywords']) . '">' . "\n";
        }
        if (isset($opts['og_image'])) {
            $html .= '<meta property="og:image" content="' . self::e($opts['og_image']) . '">' . "\n";
        }
        if (isset($opts['og_title'])) {
            $html .= '<meta property="og:title" content="' . self::e($opts['og_title']) . '">' . "\n";
        }
        return $html;
    }

    public static function pagination(object $paginator): string
    {
        if ($paginator->last_page <= 1) {
            return '';
        }

        $url     = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $current = $paginator->current_page;
        $last    = $paginator->last_page;
        $html    = '<nav class="pagination">';

        for ($i = 1; $i <= $last; $i++) {
            $active = $i === $current ? ' class="active"' : '';
            $html  .= "<a href=\"{$url}?page={$i}\"{$active}>{$i}</a>";
        }

        return $html . '</nav>';
    }

    public static function cacheFragment(string $key, int $ttl, string $compiled, array $data = []): string
    {
        $cache = app()->getContainer()->make(Cache::class);
        return $cache->remember($key, $ttl, fn() => self::renderInline($compiled, $data));
    }

    public static function renderInline(string $compiled, array $data = []): string
    {
        $data['__data'] = $data;

        extract($data, EXTR_SKIP);
        ob_start();
        eval('?>' . $compiled);
        return ob_get_clean();
    }

    public static function lazyScript(string $id): string
    {
        return <<<HTML
        <script>
        (function(){
            var el = document.getElementById('{$id}');
            if (!el) return;
            var trigger = el.dataset.trigger || 'load';
            var delay   = parseInt(el.dataset.delay) || 0;
            function loadIt() {
                setTimeout(function(){
                    fetch(el.dataset.lazy).then(r => r.text()).then(html => { el.outerHTML = html; });
                }, delay);
            }
            if (trigger === 'visible' && 'IntersectionObserver' in window) {
                new IntersectionObserver(function(entries, obs){
                    if (entries[0].isIntersecting) { loadIt(); obs.disconnect(); }
                }).observe(el);
            } else {
                if (document.readyState === 'complete') loadIt();
                else window.addEventListener('load', loadIt);
            }
        })();
        </script>
        HTML;
    }
}
