# SparkPHP

**Write what matters.**

SparkPHP e um framework PHP file-based, zero-config e observavel por default.
Ele existe para reduzir wiring, cortar boilerplate e deixar o comportamento da
aplicacao visivel no CLI, no Inspector e na propria estrutura de arquivos.

Versao publicada atual: `0.9.0` (`0.9.x`).

## O que o Spark otimiza

- **Mais simples**: menos arquivos de cola, menos registro manual, menos abstrações para ligar o proprio framework.
- **Mais previsivel**: o arquivo e a convencao dizem o que acontece.
- **Mais observavel**: request, cache, queue, AI e benchmark fazem parte do produto.

## SparkPHP vs Laravel

O Spark nao tenta ganhar do Laravel por “ter mais coisas”.

Ele tenta ser melhor em outro eixo:

- **menos wiring para o caso comum**
- **menos superficie para lembrar**
- **mais visibilidade operacional sem setup extra**

Quando o problema pede ecossistema enorme, pacotes first-party maduros e ampla
disponibilidade de time, o Laravel continua excelente.

Quando o problema pede previsibilidade, baixo atrito e um framework que cabe na
cabeca do time, o Spark entra muito forte.

Guia honesto de comparacao:

- [SparkPHP vs Laravel](docs/21-spark-vs-laravel.md)
- [Guia de Adoção](docs/22-adoption-guide.md)
- [Benchmarks](docs/23-benchmarking.md)
- [Migração a partir do Laravel](docs/24-migrating-from-laravel.md)

## Quick Start

```bash
composer install
php spark init
php spark serve
```

Ou gere um projeto novo ja com um starter first-party:

```bash
php spark starter:list
php spark new ../meu-saas --starter=saas
```

## Starter kits

O runtime atual publica quatro presets oficiais:

- `api`
- `saas`
- `admin`
- `docs`

Todos continuam sendo Spark puro: rotas em arquivo, templates `.spark`, CLI
versionada, docs em Markdown e observabilidade nativa.

Guia completo:

- [Starter Kits](docs/20-starter-kits.md)

## Documentacao

O indice principal da documentacao fica em:

- [docs/README.md](docs/README.md)

Topicos principais:

- [Instalacao](docs/01-installation.md)
- [Routing](docs/02-routing.md)
- [Request & Response](docs/03-request-response.md)
- [Database](docs/05-database.md)
- [CLI](docs/13-cli.md)
- [AI SDK](docs/16-ai.md)

## Benchmarks e observabilidade

O Spark publica benchmark e diagnostico como partes do produto:

```bash
php spark about
php spark benchmark
php spark inspector:status
```

O objetivo nao e vender microbenchmark isolado. O objetivo e medir ciclo HTTP,
DX e operacao de forma repetivel.

## Estado do projeto

O SparkPHP esta na linha `0.x`, ainda consolidando contrato publico. Isso significa:

- a linha ja busca previsibilidade real
- minors ainda podem trazer mudancas estruturais documentadas
- toda release relevante deve atualizar `VERSION`, `CHANGELOG.md` e os guias de upgrade

Mais detalhes:

- [Releases & Compatibilidade](docs/14-releases.md)
- [Upgrade Guide](docs/15-upgrade-guide.md)
