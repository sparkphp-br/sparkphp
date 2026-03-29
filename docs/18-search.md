# Semantic Search & Retrieval

O SparkPHP agora conecta embeddings, banco e AI flow sem depender de uma colagem de
pacotes. A ideia e simples:

- o `QueryBuilder` entende busca vetorial
- o `Model` ganha um atalho para semantic search
- `ai()->retrieve(...)` vira a ponte curta entre dado e prompt

## Quando isso brilha

- FAQ semantico
- busca por documentos e snippets
- recomendacao por similaridade
- RAG curto para agentes e respostas assistidas

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

## Semantic search em Models

Quando existe um model para a tabela, o fluxo fica ainda menor:

```php
$articles = Article::semanticSearch('embedding', 'Como funciona o cache?')
    ->limit(5)
    ->get();
```

Tambem existe `Article::nearestTo(...)` para ranking sem threshold.

## Retrieval no fluxo de AI

O cliente de AI agora sobe um builder de retrieval:

```php
$retrieval = ai()->retrieve('Como configuro cache?')
    ->from('documents', 'embedding')
    ->select('id', 'title', 'content')
    ->take(3)
    ->get();
```

O resultado devolve:

- `items`
- `provider`
- `model`
- `meta`

E principalmente:

```php
$context = $retrieval->toPromptContext('content');
```

Isso gera um bloco de contexto pronto para ser injetado em `text()` ou `agent()`.

## Exemplo de RAG curto

```php
$retrieval = ai()->retrieve('Como configuro cache?')
    ->from(Article::class, 'embedding')
    ->take(3)
    ->get();

$answer = ai()->agent('support')
    ->prompt("Contexto:\n" . $retrieval->toPromptContext('content') . "\n\nPergunta: Como configuro cache?")
    ->run();
```

Sem registries extras. Sem pipeline paralelo obrigatorio. O dado vem do banco e o
contexto volta para o fluxo de AI na mesma linguagem do framework.

## PostgreSQL + pgvector

Quando o driver ativo e `pgsql`, o Spark tenta expressar a busca vetorial com
operadores de `pgvector`.

O alvo inicial e:

- `cosine` por default
- `l2` / `euclidean`
- `inner_product`

Para esse modo, a expectativa operacional e:

- PostgreSQL 13+
- extensao `pgvector` instalada
- coluna de embedding preparada no banco

## SQLite / MySQL em dev

Fora do PostgreSQL, o Spark faz fallback para ranking em memoria a partir de vetores
serializados em JSON/texto.

Isso existe para dois objetivos:

- manter a DX local previsivel
- permitir suite e smoke tests sem depender de `pgvector`

Em producao, para bases grandes, o caminho recomendado continua sendo PostgreSQL com
`pgvector`.

## Metricas suportadas

- `cosine`
- `l2`
- `inner_product`

Exemplo:

```php
$documents = db('documents')
    ->whereVectorSimilarTo('embedding', 'Como configuro cache?', 0.8, 'cosine')
    ->get();
```

## Resumo

- `QueryBuilder` faz ranking e threshold por similaridade
- `Model::semanticSearch(...)` encurta o fluxo em ORM
- `ai()->retrieve(...)` liga embeddings + banco + prompt
- PostgreSQL usa `pgvector`; SQLite/MySQL servem bem para DX local via fallback
