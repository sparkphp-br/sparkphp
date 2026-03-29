# AI Conventions

Depois da SDK unificada, o SparkPHP passa a tratar AI com a mesma filosofia do resto
do framework: arquivos, convencao e o minimo de wiring manual.

## Estrutura

```text
app/
  ai/
    agents/
    prompts/
    tools/
```

Esses diretorios sao preparados por `php spark init`.

## Prompts nomeados

Qualquer arquivo em `app/ai/prompts` pode virar um prompt reutilizavel.

Exemplo:

```text
app/ai/prompts/support/reply.spark
```

```txt
Responda sobre {{topic}} para o cliente {{customer}}.
```

Uso:

```php
$prompt = ai_prompt('support/reply', [
    'topic' => 'cache',
    'customer' => 'Acme',
]);

$response = ai()->text()
    ->usingPrompt('support/reply', [
        'topic' => 'cache',
        'customer' => 'Acme',
    ])
    ->generate();
```

Extensoes suportadas:

- `.spark`
- `.md`
- `.txt`
- `.prompt`
- `.php`

Arquivos `.php` podem retornar uma string ou uma closure que recebe os dados do prompt.

## Tools file-based

Tools reutilizaveis vivem em `app/ai/tools`.

Exemplo:

```php
// app/ai/tools/lookup-order.php
<?php

return [
    'description' => 'Consulta pedido',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
        ],
    ],
    'handle' => fn(array $arguments) => [
        'id' => $arguments['id'] ?? null,
        'status' => 'processing',
    ],
];
```

Uso:

```php
$tool = ai_tool('lookup-order');

$agent = ai()->agent('support')
    ->tool('lookup-order')
    ->context([
        'tool_arguments' => [
            'lookup-order' => ['id' => 123],
        ],
    ])
    ->prompt('Verifique o pedido 123.')
    ->run();
```

No driver `fake`, os tools registrados sao executados durante o run e aparecem em
`$response->meta['tool_results']`.

## Agentes por convencao

Agentes ficam em `app/ai/agents` e retornam um array simples com os defaults do fluxo.

Exemplo:

```php
// app/ai/agents/support.php
<?php

return [
    'instructions_prompt' => 'support/system',
    'prompt_template' => 'support/request',
    'tools' => ['lookup-order'],
    'context' => ['team' => 'support'],
    'schema' => [
        'type' => 'object',
        'properties' => [
            'answer' => ['type' => 'string'],
            'priority' => ['type' => 'string'],
        ],
    ],
];
```

Uso:

```php
$response = ai()->agent('support')
    ->context([
        'customer' => ['name' => 'Globex'],
        'order_id' => 123,
        'tool_arguments' => [
            'lookup-order' => ['id' => 123],
        ],
    ])
    ->run();
```

Ordem de resolucao:

1. defaults do arquivo em `app/ai/agents/<name>.php`
2. prompt homonimo em `app/ai/prompts/<name>.*`, se existir
3. overrides fluentes no builder (`prompt()`, `instructions()`, `tool()`, `schema()`, etc.)

Assim o arquivo define a base e a chamada local continua podendo sobrescrever o que for
necessario.

## Structured output

Texto e agentes aceitam `schema([...])` para pedir payload estruturado.

```php
$result = ai()->text('Extraia nome e email')
    ->schema([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email'],
        ],
    ])
    ->generate();

return $result->structured;
```

O mesmo vale para agentes:

```php
$result = ai()->agent('support')
    ->schema([
        'type' => 'object',
        'properties' => [
            'answer' => ['type' => 'string'],
            'priority' => ['type' => 'string'],
        ],
    ])
    ->prompt('Resuma o caso.')
    ->run();
```

No driver `fake`, o Spark gera uma estrutura deterministica para facilitar suite,
smoke test e DX local.

## Descoberta automatica

O cliente expoe a descoberta por convencao:

```php
ai()->discoverPrompts();
ai()->discoverTools();
ai()->discoverAgents();
```

Isso permite buildar debug pages, smoke tests e registries sem precisar manter listas
manuais.

## Resumo

- `app/ai/prompts` guarda templates nomeados
- `app/ai/tools` concentra tools reutilizaveis
- `app/ai/agents` define defaults de agentes
- `schema([...])` padroniza structured output
- o driver `fake` continua suficiente para desenvolvimento e testes

Para a camada de busca vetorial e retrieval em cima dessas convencoes, veja
[18-search.md](18-search.md).
