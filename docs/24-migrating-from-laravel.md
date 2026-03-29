# Migração a partir do Laravel

Migrar de Laravel para SparkPHP faz sentido em alguns cenarios. Em outros, nao.

O objetivo deste guia e ajudar a tomar a decisao certa antes de escrever qualquer codigo.

## Quando vale considerar migração

- voce vai abrir um produto novo e nao quer repetir a mesma pilha
- a base atual sofre mais com wiring e estrutura do que com falta de feature
- o time quer menos overhead de framework
- o projeto nao depende pesadamente de produtos Laravel-first

## Quando nao vale migrar

- o sistema atual depende fortemente de Nova, Horizon, Cashier, Forge ou Vapor
- a equipe precisa preservar ecossistema Laravel sem desvio
- o custo de troca e maior do que o ganho operacional previsto

## Estratégia mais segura

A migracao recomendada quase nunca e “parar tudo e reescrever”.

Os caminhos mais seguros costumam ser:

1. criar um novo servico em Spark
2. migrar um modulo novo primeiro
3. usar Spark em painéis, APIs internas ou portais satelite

## Mapa mental: Laravel -> Spark

| Laravel | SparkPHP |
|---------|----------|
| `routes/web.php` / `api.php` | `app/routes/**` |
| middleware registrado | `app/middleware/*.php` + `_middleware.php` |
| Blade com `@extends` | `.spark` com layout automatico |
| controllers densos | closures file-based ou services injetados |
| resources dedicados | serializacao por convencao no Model |
| providers / container binding | convencao + type-hint |
| config espalhada em `/config` | `.env` + `app/config` opcional |

## Fricções mais comuns

### Service providers e facades

No Spark, a ideia e remover a cola, nao recria-la. Isso significa que parte da
arquitetura baseada em provider/facade simplesmente deixa de existir.

### Blade muito componentizado

Views Spark favorecem tela inteira + layout automatico. Migracoes com Blade muito
fragmentado pedem revisao de estrutura, nao traducao literal.

### Pacotes Laravel-first

Alguns pacotes vao continuar valendo a pena no Laravel e nao no Spark. Esse e um dos
principais pontos a avaliar antes de migrar.

## Plano recomendado

### Fase 1: avaliar dependências

Liste:

- pacotes acoplados ao Laravel
- features first-party insubstituiveis
- pontos de observabilidade necessarios

### Fase 2: escolher um piloto

Boas opcoes:

- API nova
- painel interno
- portal de docs

### Fase 3: escolher um starter

```bash
php spark starter:list
php spark new ../novo-servico --starter=api
```

### Fase 4: migrar conceitos, não arquivos

Em vez de portar arquivo por arquivo:

- redesenhe as rotas para o modelo file-based
- converta views para o fluxo Spark
- mova a serializacao para convencao de model
- simplifique middlewares e policies

### Fase 5: comparar operação

Depois do piloto, compare:

- clareza da arvore
- tempo de onboarding
- tempo de debug
- benchmark
- volume de boilerplate

## Regra prática

Se a migracao existe apenas para “trocar de framework”, pare.

Se ela existe para reduzir atrito estrutural e simplificar operacao, ai sim ela pode
valer muito a pena.
