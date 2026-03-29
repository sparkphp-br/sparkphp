# AI Observability

O SparkPHP agora trata AI como uma superficie observavel por default. Quando o
Spark Inspector esta ativo, chamadas de:

- `text()`
- `embeddings()`
- `image()`
- `audio()`
- `agent()`
- `retrieve()`

passam a registrar trace automaticamente.

## O que o Inspector captura

Para cada chamada de AI, o runtime registra:

- tipo da operacao
- driver
- provider
- model
- latencia
- tokens (`input`, `output`, `total`)
- custo estimado
- tool calls
- preview do request
- preview do response

No painel interno isso aparece:

- na aba `AI`
- no overview
- no pipeline consolidado
- nos bottlenecks (`slowest_ai_call`, `most_expensive_ai_call`)

## Configuracao

No `.env`:

```env
SPARK_INSPECTOR=auto
SPARK_INSPECTOR_PREFIX=/_spark
SPARK_INSPECTOR_HISTORY=150
SPARK_INSPECTOR_MASK=false
SPARK_INSPECTOR_SLOW_MS=250

SPARK_AI_MASK=true
SPARK_AI_TRACE_PREVIEW=240
```

### Como ler esses knobs

- `SPARK_INSPECTOR`: liga ou desliga o painel inteiro
- `SPARK_AI_MASK`: mascara campos de AI sensiveis
- `SPARK_AI_TRACE_PREVIEW`: corta previews longos para o painel nao explodir de tamanho

## Mascaramento de dados

Com `SPARK_AI_MASK=true`, o Spark mascara automaticamente campos como:

- `prompt`
- `system`
- `input`
- `instructions`
- `text`
- `content`
- `context`
- `structured`
- `tool_results`
- `query`

Exemplo visual de payload mascarado:

```txt
[masked prompt len=128]
[masked text len=92]
[masked context items=3]
```

Isso permite usar o Inspector em desenvolvimento sem despejar prompt completo,
contexto interno ou output estruturado bruto na UI.

## Status operacional via CLI

### Ver configuracao de AI

```bash
php spark ai:status
php spark ai:status --json
```

Esse comando mostra:

- driver atual
- provider resolvido
- modelos por capacidade
- defaults de imagem e audio
- estado do Inspector
- mascaramento e preview de trace

## Smoke tests de AI

### Rodar tudo

```bash
php spark ai:smoke-test
```

Por default, o comando cobre:

- `text`
- `embeddings`
- `image`
- `audio`
- `agent`

`retrieval` fica como cheque explicito, porque tambem depende de banco e busca vetorial.

### Rodar uma capacidade especifica

```bash
php spark ai:smoke-test --capability=text
php spark ai:smoke-test --capability=agent
php spark ai:smoke-test --capability=retrieval
```

### Saida em JSON

```bash
php spark ai:smoke-test --json
```

O smoke test retorna:

- status
- latencia
- tokens
- custo
- resumo curto da operacao

Isso e util para:

- validar o provider apos trocar `.env`
- checar se retrieval continua funcional
- confirmar que o driver fake ou custom continua respeitando o contrato do framework

## O que aparece no pipeline de AI

O pipeline consolidado do Inspector resume:

- total de operacoes
- tempo acumulado
- tokens de entrada e saida
- custo estimado
- total de tool calls
- providers observados
- models observados

As chamadas mais pesadas aparecem no topo, ordenadas por:

1. latencia
2. custo
3. tool calls

## Leitura pratica dos gargalos

### `slowest_ai_call`

Mostra a chamada de AI com maior latencia.

Use para descobrir:

- prompts grandes demais
- retrieval trazendo contexto demais
- tools lentas

### `most_expensive_ai_call`

Mostra a chamada com maior custo estimado.

Use para descobrir:

- agentes verbosos
- prompts mal controlados
- respostas estruturadas grandes demais

## Boas praticas

### 1. Mantenha `SPARK_AI_MASK=true` por default

Mesmo em desenvolvimento, isso reduz chance de vazamento acidental de prompt e
contexto sensivel no historico do Inspector.

### 2. Use `SPARK_AI_TRACE_PREVIEW` para conter payloads grandes

Se sua equipe trabalha com prompts extensos, reduza o preview:

```env
SPARK_AI_TRACE_PREVIEW=120
```

### 3. Rode `ai:smoke-test` no setup do ambiente

Antes de testar features maiores, valide o provider:

```bash
php spark ai:status
php spark ai:smoke-test
```

### 4. Observe retrieval como parte da experiencia de AI

Quando o problema parece estar "no agente", muitas vezes o gargalo real esta em:

- embedding da pergunta
- filtro vetorial
- contexto mal selecionado

Por isso retrieval tambem entra no trace de AI.

## Relacao com o driver fake

O driver `fake` tambem expone:

- `usage`
- `cost_usd`
- `tool_calls`

Isso foi intencional. O objetivo e permitir que:

- a suite teste o contrato de observabilidade
- o smoke test funcione localmente
- a UI do Inspector seja validada sem provider externo

## Fluxo recomendado para times

1. deixe `AI_DRIVER=fake` em desenvolvimento inicial
2. modele prompts, tools e agentes em `app/ai/*`
3. valide o contrato com `php spark ai:smoke-test`
4. observe o trace no `/_spark`
5. so depois conecte provider real

## Referencias relacionadas

- [16-ai.md](16-ai.md)
- [17-ai-conventions.md](17-ai-conventions.md)
- [18-search.md](18-search.md)
- [13-cli.md](13-cli.md)
