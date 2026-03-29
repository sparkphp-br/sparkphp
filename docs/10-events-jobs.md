# Events & Jobs

## Events

O SparkPHP usa um EventEmitter file-based: o nome do arquivo em `app/events/` e o nome do evento. Sem registro, sem classes de evento.

### Criando um event handler

```bash
php spark make:event UserRegistered
```

Cria `app/events/UserRegistered.php`:

```php
// app/events/UserRegistered.php
<?php

// $data contem o que foi passado no dispatch
// Exemplo: ['user' => $user]

$user = $data['user'];

// Enviar email de boas-vindas
mailer()
    ->to($user->email)
    ->subject('Bem-vindo!')
    ->view('emails.welcome', ['user' => $user])
    ->send();

// Registrar log
log_info("Novo usuario registrado: {$user->email}");
```

### Disparando eventos

```php
event('UserRegistered', ['user' => $user]);
```

`event()` e um alias de `emit()`. Ambos localizam e executam `app/events/UserRegistered.php`, passando os dados como `$data`.

### Listeners in-memory

Para handlers temporarios (uteis em testes ou logica pontual):

```php
// Registrar listener
on('OrderPlaced', function ($data) {
    log_info("Pedido #{$data['order']->id} realizado");
});

// Remover listener
off('OrderPlaced', $callback);

// Disparar
event('OrderPlaced', ['order' => $order]);
```

### Eventos do Model

Models ja disparam eventos de ciclo de vida automaticamente em operacoes CRUD. O nome segue a tabela do model:

```text
users.created
users.updating
users.updated
users.deleting
users.deleted
```

Exemplo:

```php
// app/events/users.created.php
<?php

$user = $data;

log_info("Novo usuario criado: {$user->email}");
```

### Exemplo: sistema de notificacoes

```php
// app/events/CommentCreated.php
<?php

$comment = $data['comment'];
$post    = Post::find($comment->post_id);
$author  = User::find($post->user_id);

// Notificar o autor do post
if ($author->id !== $comment->user_id) {
    db('notifications')->insert([
        'user_id' => $author->id,
        'type'    => 'new_comment',
        'data'    => json_encode([
            'post_id'    => $post->id,
            'comment_id' => $comment->id,
            'commenter'  => $comment->user_name,
        ]),
    ]);
}
```

---

## Jobs & Queues

Jobs permitem processar tarefas pesadas em background, sem travar a resposta HTTP.

### Configuracao

```env
# sync = executa imediatamente (dev)
# file = fila assincrona em storage/queue/ (production)
QUEUE=sync
```

`QUEUE=sync` continua sendo o default mais simples para desenvolvimento.
Quando `QUEUE=file`, o Spark grava os payloads em `storage/queue/*.json` e o worker
`php spark queue:work` passa a consumir esses arquivos.

### Criando um job

```bash
php spark make:job SendWelcomeEmail
```

```php
// app/jobs/SendWelcomeEmail.php
<?php

class SendWelcomeEmail
{
    public string $queue = 'mail';
    public int $tries = 5;
    public array|int $backoff = [10, 30, 90];
    public int|float $timeout = 30;
    public bool $failOnTimeout = true;

    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        $user = User::find($this->data['user_id']);

        mailer()
            ->to($user->email)
            ->subject('Bem-vindo ao ' . env('APP_NAME'))
            ->view('emails.welcome', ['user' => $user])
            ->send();
    }
}
```

Essas propriedades sao opcionais. Se preferir, voce tambem pode usar atributos PHP:

```php
<?php

#[OnQueue('mail')]
#[Tries(5)]
#[Backoff([10, 30, 90])]
#[Timeout(30)]
#[FailOnTimeout]
class SendWelcomeEmail
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        // ...
    }
}
```

### Roteamento central por job

O lugar oficial para defaults e rotas de fila do projeto e `app/jobs/_queue.php`.
Esse arquivo e carregado tanto no HTTP quanto no CLI, entao o comportamento fica
consistente entre `dispatch()` e `queue:work`.

```php
<?php
// app/jobs/_queue.php

return [
    'defaults' => [
        'tries' => 3,
        'backoff' => [60, 120, 300],
        'timeout' => 0,
        'fail_on_timeout' => false,
    ],

    'routes' => [
        SendWelcomeEmail::class => [
            'queue' => 'mail',
            'tries' => 5,
            'backoff' => [10, 30, 90],
            'timeout' => 30,
            'fail_on_timeout' => true,
        ],

        ProcessAvatar::class => [
            'queue' => 'images',
            'connection' => 'file',
        ],
    ],
];
```

Precedencia usada pelo Spark:

1. defaults internos do framework
2. `app/jobs/_queue.php` em `defaults`
3. propriedades / atributos do job
4. `app/jobs/_queue.php` em `routes`
5. overrides inline no `dispatch(..., q: ...)` ou `queue()->push(..., options: ...)`

Se voce precisar sobrescrever em runtime, por exemplo em teste ou diagnostico local,
tambem pode usar `Queue::route(...)` no mesmo processo:

```php
Queue::route(ProcessAvatar::class, queue: 'images-high', tries: 5);
```

### Despachando jobs

```php
// Executar usando a rota/default do job
dispatch(SendWelcomeEmail::class, ['user_id' => $user->id]);

// Forcar uma fila especifica nessa chamada
dispatch(SendWelcomeEmail::class, ['user_id' => $user->id], 'priority');

// Executar com delay
dispatch_later(SendWelcomeEmail::class, ['user_id' => $user->id], 300); // 5 min

// Enfileirar manualmente pelo helper da fila
queue(SendWelcomeEmail::class, ['user_id' => $user->id]);
```

### Processando a fila

```bash
# Worker que processa jobs continuamente
php spark queue:work

# Com fila especifica
php spark queue:work --queue=emails

# Listar filas com ready/delayed/total
php spark queue:list

# Inspecionar um job especifico
php spark queue:inspect job_123 --queue=failed

# Reprocessar um job com falha
php spark queue:retry job_123

# Reprocessar toda a fila de falhas
php spark queue:retry --all

# Limpar todos os jobs da fila
php spark queue:clear

# Limpar seletivamente por job ou id
php spark queue:clear failed --job=SendWelcomeEmail
php spark queue:clear default --id=job_123
```

### Como a fila funciona (driver file)

1. `dispatch()` resolve a configuracao final do job e grava o payload em `storage/queue/<fila>.json`
2. `queue:work` le a fila, instancia a classe e chama `handle()`
3. Se `handle()` falha, o job volta para a fila com o `backoff` configurado
4. Se o timeout estoura e `failOnTimeout = true`, o job vai direto para a fila `failed`
5. Quando esgota `tries`, o Spark move o payload completo para `storage/queue/failed.json`

O payload persistido guarda metadados como:

- `id`
- `queue`
- `attempts`
- `tries`
- `backoff`
- `timeout`
- `fail_on_timeout`
- `available_at`
- `last_error`
- `failure_reason`
- `failed_at`

Isso permite retry, inspect e limpeza seletiva sem depender de banco ou pacote externo.

### Exemplo pratico: processamento de imagem

```php
// app/jobs/ProcessAvatar.php
<?php

class ProcessAvatar
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        $path = $this->data['path'];

        // Redimensionar imagem
        $img = imagecreatefromjpeg($path);
        $thumb = imagescale($img, 200, 200);
        imagejpeg($thumb, str_replace('.jpg', '_thumb.jpg', $path));

        imagedestroy($img);
        imagedestroy($thumb);
    }
}

// Na rota de upload
post(function () {
    $file = request()->file('avatar');
    $path = 'storage/uploads/' . $file['name'];
    move_uploaded_file($file['tmp_name'], $path);

    dispatch(ProcessAvatar::class, ['path' => $path]);

    return json(['message' => 'Upload recebido, processando...']);
});
```

### DI no handle

O Spark continua passando o payload pelo construtor, mas o metodo `handle()` tambem
pode receber dependencias do container:

```php
<?php

class SendDigest
{
    public function __construct(private mixed $data = null) {}

    public function handle(Mailer $mailer, Logger $logger): void
    {
        $mailer
            ->to($this->data['email'])
            ->subject('Resumo diario')
            ->view('emails.digest', ['items' => $this->data['items']])
            ->send();

        $logger->info('Digest enviado', ['email' => $this->data['email']]);
    }
}
```

### Combinando Events + Jobs

```php
// app/events/OrderPlaced.php
<?php

// Despachar jobs para processar em background
dispatch(SendOrderConfirmation::class, ['order_id' => $data['order']->id]);
dispatch(NotifyWarehouse::class, ['order_id' => $data['order']->id]);
dispatch(UpdateInventory::class, ['items' => $data['order']->items]);
```

## Proximo passo

→ [Mail](11-mail.md)
