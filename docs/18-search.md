# Semantic Search & Retrieval

O SparkPHP conecta embeddings, banco e AI flow sem depender de uma colagem de
pacotes. A ideia e simples:

- o `QueryBuilder` entende busca vetorial
- o `Model` ganha atalhos para semantic search
- `ai()->retrieve(...)` vira a ponte curta entre dado e prompt

## Quando isso brilha

- FAQ semantico
- busca por documentos e snippets
- recomendacao por similaridade
- RAG curto para agentes e respostas assistidas

## Conceitos da API

### Ranking

Ordenar resultados pela proximidade vetorial.

### Threshold

Filtrar apenas resultados acima de uma similaridade minima.

### Retrieval

Gerar o vetor da pergunta, consultar o banco e devolver um contexto pronto para
injetar em `text()` ou `agent()`.

## Busca vetorial no QueryBuilder

### Ranking rapido

```php
$documents = db('documents')
    ->select('id', 'title', 'content')
    ->selectVectorSimilarity('embedding', 'Como configuro cache?')
    ->whereVectorSimilarTo('embedding', 'Como configuro cache?')
    ->limit(5)
    ->get();
```

Cada item pode expor `vector_score` quando `selectVectorSimilarity(...)` e usado.

### Threshold minimo

```php
$documents = db('documents')
    ->whereVectorSimilarTo('embedding', 'Como configuro cache?', 0.82)
    ->get();
```

### Ordenacao explicita

```php
$documents = db('documents')
    ->select('id', 'title')
    ->orderByVectorSimilarity('embedding', 'Como configuro cache?')
    ->limit(10)
    ->get();
```

### Atalho de intencao

```php
$documents = db('documents')
    ->nearestTo('embedding', 'SparkPHP vector search')
    ->limit(3)
    ->get();
```

`nearestTo(...)` e um shorthand quando voce quer ranking direto, sem se preocupar
com os operadores mais baixos.

## Semantic search em Models

Quando existe um model para a tabela, o fluxo fica ainda menor:

```php
$articles = Article::semanticSearch('embedding', 'Como funciona o cache?')
    ->limit(5)
    ->get();
```

Tambem existe `Article::nearestTo(...)` para ranking sem threshold.

## Retrieval no fluxo de AI

O cliente de AI sobe um builder de retrieval:

```php
$retrieval = ai()->retrieve('Como configuro cache?')
    ->from('documents', 'embedding')
    ->select('id', 'title', 'content')
    ->take(3)
    ->get();
```

### O que `from(...)` aceita

- nome da tabela
- `QueryBuilder`
- classe `Model`

Exemplos:

```php
->from('documents')
->from(Article::class, 'embedding')
->from(Article::query()->where('published', true), 'embedding')
```

### Ajustando a busca

```php
$retrieval = ai()->retrieve('Como configuro cache?')
    ->from('documents', 'embedding')
    ->metric('cosine')
    ->threshold(0.80)
    ->take(5)
    ->select('id', 'title', 'content')
    ->get();
```

Opcoes disponiveis:

- `column('embedding')`
- `metric('cosine' | 'l2' | 'inner_product')`
- `threshold(0.8)`
- `take(5)`
- `select(...)`

## O que `AiRetrievalResult` devolve

`AiRetrievalResult` traz:

- `items`
- `provider`
- `model`
- `meta.metric`
- `meta.vector_column`
- `meta.limit`
- `meta.result_count`
- `meta.vector_dimensions`
- `meta.usage`
- `meta.cost_usd`

Metodos uteis:

```php
$retrieval->first();
$retrieval->toArray();
$retrieval->toPromptContext('content');
```

### Transformando em contexto para prompt

```php
$context = $retrieval->toPromptContext('content');
```

Se um item trouxer `vector_score`, o helper adiciona o score ao bloco de contexto.

Voce tambem pode customizar:

```php
$context = $retrieval->toPromptContext(function ($item, $index) {
    return "#".($index + 1) . ' ' . $item->title . "\n" . $item->content;
});
```

## Exemplo de RAG curto

```php
$retrieval = ai()->retrieve('Como configuro cache?')
    ->from(Article::class, 'embedding')
    ->take(3)
    ->get();

$answer = ai()->agent('support')
    ->prompt(
        "Contexto:\n"
        . $retrieval->toPromptContext('content')
        . "\n\nPergunta: Como configuro cache?"
    )
    ->run();
```

Sem registries extras. Sem pipeline paralelo obrigatorio. O dado vem do banco e o
contexto volta para o fluxo de AI na mesma linguagem do framework.

## PostgreSQL + pgvector

Quando o driver ativo e `pgsql`, o Spark tenta expressar a busca vetorial com
operadores de `pgvector`.

O alvo inicial e:

- `cosine` por default
- `l2`
- `inner_product`

Para esse modo, a expectativa operacional e:

- PostgreSQL 13+
- extensao `pgvector` instalada
- coluna de embedding preparada no banco

## SQLite / MySQL em dev

Fora do PostgreSQL, o Spark faz fallback para ranking em memoria a partir de vetores
serializados em JSON ou texto.

Isso existe para dois objetivos:

- manter a DX local previsivel
- permitir suite e smoke tests sem depender de `pgvector`

Em producao, para bases grandes, o caminho recomendado continua sendo PostgreSQL com
`pgvector`.

## Modelagem recomendada

Um formato inicial simples para tabelas semanticamente pesquisaveis:

```sql
id
title
content
embedding
created_at
updated_at
```

Onde `embedding` pode ser:

- coluna vetorial real em PostgreSQL
- JSON/texto serializado em dev

## Quando usar cada camada

### `db(...)->whereVectorSimilarTo(...)`

Quando voce quer controle baixo nivel no query builder.

### `Model::semanticSearch(...)`

Quando o dominio ja vive em models e relacionamentos.

### `ai()->retrieve(...)`

Quando o objetivo final e alimentar um prompt, agente ou fluxo de resposta assistida.

## Observabilidade

Chamadas de retrieval tambem entram no Spark Inspector:

- latencia
- provider/model
- tokens e custo do embedding da pergunta
- numero de resultados
- preview do contexto gerado

Para diagnostico operacional:

```bash
php spark ai:smoke-test --capability=retrieval
```

Para o painel interno e mascaramento dos traces, veja
[19-ai-observability.md](19-ai-observability.md).

## Resumo

- `QueryBuilder` faz ranking e threshold por similaridade
- `Model::semanticSearch(...)` encurta o fluxo em ORM
- `ai()->retrieve(...)` liga embeddings + banco + prompt
- PostgreSQL usa `pgvector`; SQLite/MySQL servem bem para DX local via fallback
