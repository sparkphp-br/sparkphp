# Guia de Adoção

Este guia existe para responder: **como adotar SparkPHP com risco controlado?**

## Perfil ideal de adoção

O Spark encaixa melhor quando:

- o time quer padronizacao forte
- o produto e novo ou ainda esta no inicio
- a equipe quer reduzir overhead de framework
- a aplicacao e mais “produto de negocio” do que “pilha de integracoes exoticas”

## Sinais bons para adotar

- voce ja sofre com excesso de wiring
- o time consulta docs do framework toda hora para lembrar “como registrar”
- a base precisa ficar mais previsivel para onboarding
- voce quer benchmarks, CLI e observabilidade nativos

## Sinais ruins para adotar agora

- o projeto atual depende profundamente do ecossistema Laravel
- voce precisa contratar muita gente Laravel e on-boardear sem desvio
- seu valor esta hoje em cima de produtos first-party externos ao core
- o time nao quer aceitar convencao forte

## Estratégia recomendada

### 1. Comece com um projeto novo

Evite começar pelo sistema mais sensivel da empresa. O melhor ponto de entrada costuma ser:

- API interna
- painel administrativo novo
- portal de documentacao
- produto satelite

Os starters ajudam nisso:

```bash
php spark starter:list
php spark new ../meu-admin --starter=admin
```

### 2. Escolha um objetivo de adoção

Nao adote “porque o framework parece legal”.

Defina um alvo claro:

- reduzir boilerplate
- reduzir tempo de setup
- melhorar observabilidade
- simplificar operacao

Sem isso, a adocao vira debate de preferencia.

### 3. Congele as convenções do time

Antes de abrir muito a base:

- defina qual starter sera usado
- padronize naming de rotas e models
- defina se a equipe usa mais views Spark, JSON APIs ou ambos
- documente o fluxo oficial de benchmark, release e upgrade

### 4. Faça um piloto de 2 a 4 semanas

Nesse piloto, meça:

- tempo para gerar projeto novo
- tempo para um dev novo entender a arvore
- numero de arquivos tocados por feature comum
- latencia e clareza do debug
- uso real de `spark about`, `spark benchmark` e Inspector

## Checklist de adoção

- baseline de PHP e banco validada
- `.env` padronizado
- starter escolhido
- smoke test do CLI validado
- benchmark inicial salvo
- docs internas do time alinhadas ao framework
- politica de release e upgrade conhecida

## O que ensinar primeiro ao time

Na ordem:

1. estrutura de pastas
2. rotas file-based
3. smart resolver de retorno
4. middleware por arquivo e por pasta
5. CLI e Inspector
6. modelos, QueryBuilder e serializacao
7. starter kits e upgrade

## Como saber se a adoção deu certo

Adoção bem-sucedida costuma mostrar estes sintomas:

- menos discussao sobre “onde registrar”
- menos boilerplate por feature
- menos tempo para localizar o codigo de uma rota
- mais uso de observabilidade nativa
- mais consistencia entre projetos

## Resumo

Adote Spark quando o ganho buscado e estrutural:

- menos atrito
- menos ambiguidade
- mais visibilidade

Se o ganho buscado e apenas “ter outra stack”, nao vale a troca.
