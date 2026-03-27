<?php

class Logger
{
    private const LEVELS = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    private string $logPath;
    private int $minLevel;

    public function __construct(string $basePath)
    {
        $this->logPath  = $basePath . '/storage/logs';
        $level          = strtolower($_ENV['LOG_LEVEL'] ?? 'debug');
        $this->minLevel = self::LEVELS[$level] ?? 0;

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    public function debug(string $message, array $context = []): void     { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void      { $this->log('info', $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->log('notice', $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->log('warning', $message, $context); }
    public function error(string $message, array $context = []): void     { $this->log('error', $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->log('critical', $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->log('alert', $message, $context); }
    public function emergency(string $message, array $context = []): void { $this->log('emergency', $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        if (class_exists('SparkInspector')) {
            SparkInspector::recordLog($level, $message, $context);
        }

        $message = $this->interpolate($message, $context);
        $date    = date('Y-m-d');
        $time    = date('H:i:s');
        $lvl     = strtoupper(str_pad($level, 9));
        $line    = "[{$date} {$time}] {$lvl} {$message}";

        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents(
            "{$this->logPath}/spark-{$date}.log",
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public function exception(\Throwable $e, string $level = 'error'): void
    {
        $this->log($level, get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => array_slice(array_map(
                fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'),
                $e->getTrace()
            ), 0, 10),
        ]);
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val)) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return strtr($message, $replace);
    }
}
