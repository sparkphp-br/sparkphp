# Releases & Compatibilidade

Esta pagina define a politica publica de versionamento, compatibilidade, suporte e deprecacoes do SparkPHP.

Linha publicada atual: `0.5.0` (`0.5.x`).

## Estado atual do projeto

O SparkPHP esta atualmente na linha `0.x`, que deve ser tratada como **pre-1.0**.

Isso significa:

- a API publica ja busca previsibilidade
- patches nao devem introduzir quebra intencional
- minors `0.x` **podem** introduzir breaking changes quando isso for necessario para consolidar o core
- toda quebra relevante precisa vir acompanhada de documentacao e guia de upgrade

## Versionamento

O SparkPHP segue **Semantic Versioning** com uma regra adicional para a fase pre-1.0:

- `PATCH` (`0.1.1` -> `0.1.2`): correcoes e ajustes pequenos, sem quebra intencional
- `MINOR` (`0.1` -> `0.2`): novas features, refactors maiores e, enquanto o projeto estiver em `0.x`, possiveis breaking changes documentadas
- `MAJOR` (`1.x` -> `2.x`): quebra deliberada da API publica

### Compromisso a partir do 1.0

Quando o SparkPHP chegar em `1.0`, a politica passa a ser:

- `PATCH` nunca quebra contrato publico
- `MINOR` adiciona features e deprecacoes sem remocoes
- `MAJOR` concentra remocoes e mudancas incompatíveis

### Fonte unica da versao publicada

O numero de versao publicado do SparkPHP fica no arquivo `VERSION` na raiz do projeto.

Esse arquivo e consumido por:

- `php spark version`
- `php spark about`
- helpers como `spark_version()` e `spark_release_line()`
- rota raiz padrao do projeto
- `php spark api:spec`, preenchendo `info.version`

Ao preparar um release, a alteracao de versao deve acontecer primeiro nesse arquivo.
CLI, docs geradas e superficies publicas do framework passam a refletir o novo valor
sem hardcodes espalhados.

### Changelog oficial

Toda release publica relevante tambem deve atualizar `CHANGELOG.md` na raiz do projeto.

Esse arquivo e o historico humano de produto, enquanto `VERSION` continua sendo a
fonte unica do numero publicado no runtime.

## Politica de suporte

### Linha `0.x`

Enquanto o projeto estiver em `0.x`:

- apenas a **minor mais recente** recebe correcoes
- apenas a **minor mais recente** recebe correcoes de seguranca
- a documentacao da branch principal e a fonte de verdade

### A partir do `1.0`

Depois do `1.0`, a politica alvo sera:

- correcoes de bug para a major atual por 12 meses
- correcoes de seguranca para a major atual por 18 meses
- a major anterior pode receber correcao de seguranca por uma janela curta de transicao, quando isso for viavel

## Politica de baseline

Mudancas de baseline de runtime ou banco devem seguir estas regras:

- em `0.x`, podem acontecer em releases minor, desde que venham com upgrade guide
- a partir do `1.0`, devem acontecer preferencialmente apenas em releases major
- a baseline publicada em `docs/01-installation.md` e `docs/05-database.md` e a referencia oficial

Baseline atual:

- PHP 8.3+
- SQLite 3.35+
- MySQL 8.0+
- PostgreSQL 13+

## Deprecacoes

Toda deprecacao do SparkPHP deve obedecer estas regras:

- ser documentada nesta pagina ou no guia de upgrade correspondente
- explicar o que mudou, por que mudou e qual o caminho de substituicao
- nao ficar "silenciosa": se uma convencao antiga deixar de ser recomendada, isso precisa aparecer nos docs

Enquanto o projeto estiver em `0.x`, nem toda deprecacao tera um ciclo longo. Mesmo assim, a equipe do framework assume o compromisso de:

- avisar antes de remover
- documentar o substituto
- evitar renomeacoes arbitrarias sem ganho real de DX, seguranca ou coerencia

## O que conta como quebra

Sao tratados como breaking changes:

- mudar comportamento de helpers, Router, Response, Middleware e Model sem fallback
- elevar baseline minima de PHP ou banco
- remover convencao publica documentada
- alterar assinatura esperada de comandos ou arquivos convencionais

Nao contam como quebra por si so:

- correcoes de bug em comportamento que a documentacao ja definia
- melhoria interna sem mudar contrato publico
- documentar explicitamente uma limitacao que ja existia no runtime

## Fonte oficial de compatibilidade

Ao avaliar se algo e suportado, a ordem de referencia oficial passa a ser:

1. esta politica de releases
2. `docs/01-installation.md`
3. `docs/05-database.md`
4. `docs/15-upgrade-guide.md`
5. a suite de testes do core
