# AI Conventions

Depois da SDK unificada, o SparkPHP trata AI com a mesma filosofia do resto do
framework: arquivo, convencao e o minimo de wiring manual.

## Estrutura

```text
app/
  ai/
    agents/
    prompts/
    tools/
```

Esses diretorios sao preparados por `php spark init`.

## Como pensar a camada `app/ai/*`

- `prompts/`: texto reutilizavel, templates nomeados e instrucoes compartilhadas
- `tools/`: integracoes pequenas e reutilizaveis que um agente pode chamar
- `agents/`: defaults por caso de uso, juntando prompt, tools, contexto e schema

O objetivo e simples: o codigo da feature continua curto na rota ou no service, mas
o conhecimento reutilizavel sai do inline e vai para arquivos com nome estavel.

## Prompts nomeados

Qualquer arquivo em `app/ai/prompts` pode virar um prompt reutilizavel.

Exemplo:

```text
app/ai/prompts/support/reply.spark
```

```txt
Responda sobre {{topic}} para o cliente {{customer}}.
Idioma: {{locale}}
```

Uso:

```php
$prompt = ai_prompt('support/reply', [
    'topic' => 'cache',
    'customer' => 'Acme',
    'locale' => 'pt-BR',
]);

$response = ai()->text()
    ->usingPrompt('support/reply', [
        'topic' => 'cache',
        'customer' => 'Acme',
        'locale' => 'pt-BR',
    ])
    ->generate();
```

### Placeholders

Os placeholders usam a sintaxe:

```txt
{{ customer }}
{{ order.id }}
{{ ticket.status }}
```

Arrays e objetos podem ser acessados por dot-notation.

### Extensoes suportadas

- `.spark`
- `.md`
- `.txt`
- `.prompt`
- `.php`

Arquivos `.php` podem retornar:

- uma string
- uma closure que recebe os dados do prompt

Exemplo:

```php
<?php

return function (array $data) {
    return "Cliente {$data['customer']} em {$data['locale']}";
};
```

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

### Formatos aceitos

Um arquivo de tool pode retornar:

- um `AiTool`
- uma closure
- um array com `handle`, `description` e `schema`

### Como argumentos sao resolvidos

No driver `fake`, argumentos podem vir de:

```php
[
    'tool_arguments' => [
        'lookup-order' => ['id' => 123],
    ],
]
```

Se nao existir um bloco nomeado em `tool_arguments`, o Spark usa o `context` como
fallback.

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

### Chaves suportadas no array do agente

- `model`
- `temperature`
- `max_steps`
- `options`
- `context`
- `schema`
- `instructions`
- `instructions_prompt`
- `prompt`
- `prompt_template`
- `tools`

### Ordem de resolucao

1. defaults do arquivo em `app/ai/agents/<name>.php`
2. prompt homonimo em `app/ai/prompts/<name>.*`, se existir
3. overrides fluentes no builder (`prompt()`, `instructions()`, `tool()`, `schema()`, etc.)

Assim o arquivo define a base e a chamada local continua podendo sobrescrever o que
for necessario.

## Structured output por convencao

Tanto `text()` quanto `agent()` aceitam `schema([...])`, mas em agentes file-based o
mais natural costuma ser deixar o schema no proprio arquivo do agente:

```php
return [
    'prompt_template' => 'leads/extract',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'qualified' => ['type' => 'boolean'],
        ],
    ],
];
```

Depois a chamada local fica pequena:

```php
$result = ai()->agent('extract-lead')
    ->context(['lead' => 'Alice / alice@example.com'])
    ->run();

return $result->structured;
```

## Descoberta automatica

O cliente expoe a descoberta por convencao:

```php
ai()->discoverPrompts();
ai()->discoverTools();
ai()->discoverAgents();
```

Isso ajuda em:

- paginas internas de debug
- registries
- smoke tests
- validacao de bootstrap do projeto

## Boas praticas

### 1. Prompts pequenos e nomeados

Evite prompts inline gigantes em controllers. Mova o texto reutilizavel para
`app/ai/prompts`.

### 2. Tools estreitos

Uma tool boa faz uma coisa so:

- consultar pedido
- buscar lead
- abrir ticket

Nao transforme uma tool em mini-service container com 20 responsabilidades.

### 3. Contexto como dados, nao como string montada

Prefira:

```php
->context([
    'customer' => $customer->toApi(),
    'order' => $order->toApi(),
])
```

Em vez de concatenar tudo em uma string longa antes da hora.

### 4. Use `schema()` quando o payload for integrado a codigo

Se a resposta vai alimentar:

- API
- workflow
- fila
- persistencia

prefira `schema([...])` em vez de parse manual de texto.

## Diagnostico

Para verificar descoberta e runtime:

```bash
php spark ai:status
php spark ai:smoke-test
```

Para observar latencia, tokens, custo e previews no painel interno:

veja [19-ai-observability.md](19-ai-observability.md).

## Relacao com semantic search

Prompts, tools e agentes ficam em `app/ai/*`. Retrieval e vector search vivem na
camada de dados e se conectam a esses builders via `ai()->retrieve(...)`.

Para esse lado do fluxo, veja [18-search.md](18-search.md).
