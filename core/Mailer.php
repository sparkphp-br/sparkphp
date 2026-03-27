<?php

class Mailer
{
    private string $fromEmail;
    private string $fromName;
    private array  $to          = [];
    private array  $cc          = [];
    private array  $bcc         = [];
    private array  $replyTo     = [];
    private string $subject     = '';
    private string $body        = '';
    private bool   $isHtml      = true;
    private array  $attachments = [];

    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $encryption;

    public function __construct()
    {
        $this->host       = $_ENV['MAIL_HOST']       ?? 'localhost';
        $this->port       = (int)($_ENV['MAIL_PORT'] ?? 587);
        $this->user       = $_ENV['MAIL_USER']       ?? '';
        $this->pass       = $_ENV['MAIL_PASS']       ?? '';
        $this->encryption = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
        $this->fromEmail  = $_ENV['MAIL_FROM']       ?? $this->user;
        $this->fromName   = $_ENV['MAIL_FROM_NAME']  ?? ($_ENV['APP_NAME'] ?? 'SparkPHP');
    }

    // ── Fluent setters ────────────────────────────────────────────────────────

    public function to(string $email, string $name = ''): static
    {
        $this->to[] = $name ? "\"{$name}\" <{$email}>" : $email;
        return $this;
    }

    public function cc(string $email, string $name = ''): static
    {
        $this->cc[] = $name ? "\"{$name}\" <{$email}>" : $email;
        return $this;
    }

    public function bcc(string $email, string $name = ''): static
    {
        $this->bcc[] = $name ? "\"{$name}\" <{$email}>" : $email;
        return $this;
    }

    public function replyTo(string $email, string $name = ''): static
    {
        $this->replyTo[] = $name ? "\"{$name}\" <{$email}>" : $email;
        return $this;
    }

    public function from(string $email, string $name = ''): static
    {
        $this->fromEmail = $email;
        if ($name) $this->fromName = $name;
        return $this;
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function html(string $body): static
    {
        $this->body   = $body;
        $this->isHtml = true;
        return $this;
    }

    public function text(string $body): static
    {
        $this->body   = $body;
        $this->isHtml = false;
        return $this;
    }

    public function view(string $view, array $data = []): static
    {
        $engine     = new View(app()->getBasePath());
        $this->body   = $engine->render($view, $data);
        $this->isHtml = true;
        return $this;
    }

    public function attach(string $filePath, string $name = ''): static
    {
        $this->attachments[] = [
            'path' => $filePath,
            'name' => $name ?: basename($filePath),
        ];
        return $this;
    }

    // ── Send ──────────────────────────────────────────────────────────────────

    public function send(): bool
    {
        $payload = [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'html' => $this->isHtml,
            'attachments' => array_map(fn(array $attachment): string => $attachment['name'], $this->attachments),
        ];

        if (empty($this->to)) {
            if (class_exists('SparkInspector')) {
                SparkInspector::recordMail(array_merge($payload, ['status' => 'failed', 'error' => 'No recipients specified.']));
            }
            throw new \RuntimeException('No recipients specified.');
        }

        $fromHeader = $this->fromName
            ? "\"{$this->fromName}\" <{$this->fromEmail}>"
            : $this->fromEmail;

        $boundary = md5(uniqid((string)mt_rand(), true));

        // Build headers
        $headers  = "From: {$fromHeader}\r\n";
        $headers .= 'To: ' . implode(', ', $this->to) . "\r\n";
        if ($this->cc)      $headers .= 'Cc: ' . implode(', ', $this->cc) . "\r\n";
        if ($this->bcc)     $headers .= 'Bcc: ' . implode(', ', $this->bcc) . "\r\n";
        if ($this->replyTo) $headers .= 'Reply-To: ' . implode(', ', $this->replyTo) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= 'Date: ' . date('r') . "\r\n";
        $headers .= "X-Mailer: SparkPHP\r\n";

        // Build body
        if (!empty($this->attachments)) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
            $body     = "--{$boundary}\r\n";
            $body    .= 'Content-Type: ' . ($this->isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
            $body    .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body    .= chunk_split(base64_encode($this->body)) . "\r\n";

            foreach ($this->attachments as $att) {
                if (!file_exists($att['path'])) continue;
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Type: application/octet-stream; name=\"{$att['name']}\"\r\n";
                $body .= "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode((string)file_get_contents($att['path']))) . "\r\n";
            }
            $body .= "--{$boundary}--";
        } else {
            $headers .= 'Content-Type: ' . ($this->isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $body     = chunk_split(base64_encode($this->body));
        }

        try {
            $sent = $this->smtp($headers, $body);

            if (class_exists('SparkInspector')) {
                SparkInspector::recordMail(array_merge($payload, ['status' => 'sent']));
            }

            return $sent;
        } catch (\Throwable $e) {
            if (class_exists('SparkInspector')) {
                SparkInspector::recordMail(array_merge($payload, ['status' => 'failed', 'error' => $e->getMessage()]));
            }

            throw $e;
        }
    }

    // ── SMTP transport ────────────────────────────────────────────────────────

    private function smtp(string $headers, string $body): bool
    {
        $ssl  = $this->encryption === 'ssl';
        $host = ($ssl ? 'ssl://' : '') . $this->host;

        $socket = @fsockopen($host, $this->port, $errno, $errstr, 10);
        if (!$socket) {
            throw new \RuntimeException("SMTP connection failed [{$this->host}:{$this->port}]: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 10);

        $this->smtpRead($socket); // greeting

        $domain = parse_url($_ENV['APP_URL'] ?? 'http://localhost', PHP_URL_HOST) ?? 'localhost';
        $this->smtpCmd($socket, "EHLO {$domain}");

        if ($this->encryption === 'tls') {
            $this->smtpCmd($socket, 'STARTTLS');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpCmd($socket, "EHLO {$domain}");
        }

        if ($this->user && $this->pass) {
            $this->smtpCmd($socket, 'AUTH LOGIN');
            $this->smtpCmd($socket, base64_encode($this->user));
            $this->smtpCmd($socket, base64_encode($this->pass));
        }

        $this->smtpCmd($socket, "MAIL FROM:<{$this->fromEmail}>");

        foreach (array_merge($this->to, $this->cc, $this->bcc) as $rcpt) {
            preg_match('/<(.+?)>/', $rcpt, $m);
            $email = $m[1] ?? $rcpt;
            $this->smtpCmd($socket, "RCPT TO:<{$email}>");
        }

        $this->smtpCmd($socket, 'DATA');
        fwrite($socket, "Subject: {$this->subject}\r\n{$headers}\r\n{$body}\r\n.\r\n");
        $this->smtpRead($socket);

        $this->smtpCmd($socket, 'QUIT');
        fclose($socket);

        return true;
    }

    private function smtpCmd($socket, string $cmd): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->smtpRead($socket);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int) substr($response, 0, 3);
        if ($code >= 400) {
            throw new \RuntimeException("SMTP error {$code}: " . trim($response));
        }
        return $response;
    }
}
