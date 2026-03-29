# Benchmarks

Benchmark bom nao e propaganda. E instrumento de decisao.

O SparkPHP trata benchmark como parte do produto porque a pergunta certa nao e
“qual framework ganhou um hello world sintetico?”. A pergunta certa e:

- como o runtime se comporta em cenarios repetiveis?
- como isso muda entre releases?
- o que piorou, o que melhorou e por que?

## O que o CLI já entrega

```bash
php spark benchmark
php spark benchmark --iterations=20 --warmup=3
php spark benchmark --json --no-save
```

O relatorio inclui:

- versao do Spark
- release line
- perfil benchmarkado
- cenarios HTML e JSON
- dados cold/warm
- saida versionada para comparacao historica

## Como benchmarkar com honestidade

### Compare no mesmo ambiente

Nao compare:

- maquinas diferentes
- versoes diferentes de PHP
- bancos diferentes
- caches habilitados num lado e desabilitados no outro

### Compare cenários equivalentes

O comparativo justo entre Spark e Laravel deve usar:

- mesma versao de PHP
- mesmo banco
- mesmas consultas
- mesmo tipo de resposta
- mesmo workload de middleware

### Compare o que importa

Em vez de olhar so para “request vazia”, compare:

- cold boot
- rota HTML
- rota JSON
- render de view
- consulta ao banco
- impacto de cache
- previsibilidade operacional do debug

## O que o Spark quer provar com benchmark

Nao e “ser o numero mais alto em qualquer grafico”.

O que o Spark quer provar e:

- menos overhead estrutural
- boot mais curto
- fluxo operacional mais visivel
- regressao mais facil de detectar entre releases

## Como usar benchmark no time

Fluxo recomendado:

1. rode benchmark antes de mudar uma area critica
2. rode de novo depois da mudanca
3. salve o JSON no historico interno do time
4. interprete junto com Inspector e testes, nao isoladamente

## Spark vs Laravel: como comparar sem distorcer

Use o benchmark para responder perguntas como:

- a nova feature deixou o caso comum mais pesado?
- o ganho de DX compensou o custo de runtime?
- o Spark continua mais curto e mais previsivel nessa area?

Evite transformar o benchmark em:

- disputa de hello world
- print de um unico numero
- comparacao com workload nao equivalente

## O benchmark não anda sozinho

No Spark, benchmark deve ser lido junto com:

- `php spark about`
- `php spark inspector:status`
- `/_spark`
- suite de testes

Essa combinacao e que faz o produto ficar observavel de verdade.
