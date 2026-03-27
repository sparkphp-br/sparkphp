# SparkPHP — Identidade e Filosofia

---

## O que é o SparkPHP

SparkPHP é um framework PHP construído sobre uma premissa: **o desenvolvedor deveria escrever apenas o código que importa.**

Não é um micro-framework. Não é um clone do Laravel. É uma abordagem diferente para o mesmo problema — construir aplicações web com PHP — onde a convenção substitui a configuração em todas as camadas, do roteamento à renderização.

---

## Manifesto

1. **Se precisa registrar, o design falhou.**
   Rotas, middlewares, services, events — nada é registrado manualmente. O framework descobre sozinho pelo sistema de arquivos e por type-hints.

2. **O nome do arquivo já diz o que ele faz.**
   `routes/api/users.php` é uma rota. `middleware/auth.php` é um middleware chamado `auth`. `events/user.created.php` dispara quando um usuário é criado. Sem mapa, sem dicionário, sem provider.

3. **Zero é o número certo de arquivos de configuração.**
   Toda a configuração cabe em um `.env`. Se o framework precisa de mais que isso, a abstração está errada.

4. **A view é a página, não um pedaço dela.**
   O arquivo `.spark` representa a tela inteira. Não é uma seção encaixada num layout — é o conteúdo que o layout envolve automaticamente.

5. **Segurança é default, não opt-in.**
   CSRF é automático em formulários. Escape é automático em templates. Nenhum desses deveria depender do desenvolvedor lembrar de ativá-los.

6. **Menos abstrações, mais convenções.**
   Service providers, facades, contracts, kernels — camadas que existem para conectar partes do framework entre si. No SparkPHP, as partes se conectam por convenção. A camada de cola não precisa existir.

7. **O framework trabalha para o desenvolvedor, não o contrário.**
   Se o dev está escrevendo boilerplate, copiando estrutura, ou abrindo documentação para lembrar como registrar algo, o framework está falhando no seu trabalho.

---

## Princípios de design

### Convenção sobre configuração (radical)

Outros frameworks dizem que seguem esse princípio. O SparkPHP o leva ao extremo: **não existe mecanismo de configuração para nenhuma camada que pode ser resolvida por convenção.**

| O que outros fazem | O que o SparkPHP faz |
|---|---|
| Arquivo `routes/web.php` com rotas declaradas | Diretório `app/routes/` com rotas por arquivo |
| Middleware registrado em `Kernel.php` | Middleware por nome de arquivo em `app/middleware/` |
| Service registrado em `AppServiceProvider` | Service resolvido por type-hint automaticamente |
| Evento registrado em `EventServiceProvider` | Evento por nome de arquivo em `app/events/` |
| Model com `$fillable` declarado manualmente | Fillable inferido das colunas da tabela |
| Relacionamentos declarados método por método | Relacionamentos inferidos das foreign keys |
| View conectada ao layout via `@extends` + `@section` | View envolvida no layout automaticamente |
| Config em múltiplos arquivos PHP | Um `.env` e acabou |

### Performance por design

O SparkPHP não é rápido por otimização tardia — é rápido porque carrega menos coisas.

- **Sem service providers:** não existe boot de dezenas de providers a cada request.
- **Sem facades:** não existe resolução dinâmica de aliases em runtime.
- **Sem middleware global implícito:** só executa o middleware que a rota precisa.
- **Cache agressivo:** rotas, classes, views e schema são cacheados em arrays PHP nativos.
- **Lazy loading real:** a conexão com o banco só abre na primeira query. Services só instanciam quando usados.

### API mínima

Cada helper e função do framework foi pensado para resolver o caso mais comum com o menor número de caracteres possível, sem sacrificar clareza.

```php
// Input
input('name')                    // não: Request::input('name')
                                  // não: $request->input('name')

// Banco
db('users')->find(1)             // não: DB::table('users')->find(1)
                                  // não: User::query()->find(1)

// Validação
validate(['name' => 'required']) // não: $request->validate([...])
                                  // não: Validator::make($data, $rules)

// Sessão
session('key')                   // não: Session::get('key')
                                  // não: $request->session()->get('key')
```

A regra é: **se o helper precisa de mais de uma chamada para o caso comum, está verboso demais.**

### Descoberta implícita

O desenvolvedor não precisa dizer ao framework o que existe. O framework descobre.

- **Classes:** escaneadas automaticamente de `/app` (exceto `/routes`).
- **Rotas:** escaneadas de `/app/routes/` com path = URL.
- **Middlewares:** escaneados de `/app/middleware/` com nome = apelido.
- **Events:** escaneados de `/app/events/` com nome = gatilho.
- **Views:** escaneadas de `/app/views/` com path = rota espelho.
- **Migrations:** escaneadas de `/database/migrations/` com prefixo = ordem.

**Nada é registrado.** Se o arquivo existe, o framework sabe que ele existe.

---

## Comparativo filosófico

| Aspecto | Laravel | SparkPHP |
|---|---|---|
| **Filosofia** | Expressivo, elegante, completo | Mínimo, implícito, zero-config |
| **Roteamento** | Declarativo em arquivo centralizado | Sistema de arquivos |
| **Middleware** | Registrado no Kernel, aplicado na rota | Arquivo = apelido, diretório = aplicação |
| **Views** | Blade com `@extends` + `@section` | Spark com conteúdo direto, layout automático |
| **Config** | 15+ arquivos em `/config` | Um `.env` |
| **Service container** | Bind/singleton explícito | Type-hint = auto-resolução |
| **Events** | Listener registrado em provider | Arquivo com nome do evento |
| **ORM** | Eloquent (explícito, completo) | Model com inferência de schema |
| **Boot** | Service providers (40+ no default) | Bootstrap único, sem providers |
| **Curva** | Suave pra começar, complexa pra dominar | Flat: pouco pra aprender, pouco pra lembrar |

O SparkPHP não é "anti-Laravel". É uma resposta diferente para a mesma pergunta: **quanto do trabalho o framework deveria fazer sozinho?**

A resposta do SparkPHP: **quase tudo.**

---

## Público-alvo

O SparkPHP é para desenvolvedores que:

- Sabem PHP e não precisam que o framework ensine boas práticas via abstração forçada.
- Valorizam produtividade medida em código que NÃO precisam escrever.
- Preferem convenção previsível a configuração flexível.
- Querem um framework que cabe na cabeça — poucas regras, aplicadas uniformemente.
- Constroem APIs, painéis, CRMs, SaaS — aplicações de negócio onde o diferencial não está no framework, mas no produto.

**Não é para** quem precisa de personalização extrema em cada camada, ou quem depende de um ecossistema imenso de pacotes first-party (como Forge, Vapor, Nova, Horizon).

---

## Convenções gerais unificadas

Todas as convenções do SparkPHP seguem o mesmo padrão mental:

### O arquivo diz o que é

| Arquivo | O que o framework entende |
|---|---|
| `app/routes/api/users.php` | Rota para `/api/users` |
| `app/middleware/auth.php` | Middleware chamado `auth` |
| `app/models/User.php` | Model da tabela `users` |
| `app/services/PaymentService.php` | Service injetável por type-hint |
| `app/events/user.created.php` | Evento disparado em `User::create()` |
| `app/jobs/SendReport.php` | Job despachável com `dispatch()` |
| `app/views/about.spark` | View da rota `/about` |
| `app/views/layouts/main.spark` | Layout padrão |
| `app/views/partials/header.spark` | Partial chamado com `@partial('header')` |
| `app/views/errors/404.spark` | Página de erro 404 |
| `database/migrations/001_create_users.php` | Primeira migration a executar |

### Onde o arquivo está diz como se aplica

| Localização | Comportamento |
|---|---|
| `routes/[auth]/users.php` | Rota protegida por middleware `auth` |
| `routes/[auth+throttle]/` | Todas as rotas da pasta protegidas por ambos |
| `routes/[auth]/[admin]/` | Middlewares aninhados: `auth` primeiro, `admin` depois |
| `views/users/index.spark` | View espelho de `routes/users/index.php` |
| `views/layouts/admin.spark` | Layout selecionável com `@layout('admin')` |
| `views/partials/user/card.spark` | Partial chamado com `@partial('user/card')` |

### O nome do arquivo define parâmetros

| Nome | Resolução |
|---|---|
| `users.[id].php` | `/users/:id` — `$id` disponível no handler |
| `orders.[orderId].items.[itemId].php` | `/orders/:orderId/items/:itemId` |
| `user.created.php` | Evento do model `User`, ação `created` |
| `001_create_users.php` | Migration na posição 1 |

### O tipo de retorno define a resposta

| Retorno | Resultado |
|---|---|
| `array` ou `object` (request JSON) | Response JSON |
| `array` ou `object` (request HTML) | Renderiza view espelho |
| `string` | HTML direto |
| `null` em GET | 404 |
| Retorno em POST | 201 (não 200) |
| `redirect('/url')` | Redirect HTTP |
| `view('nome', $data)` | View explícita |

### O type-hint resolve a dependência

| Declaração | O que acontece |
|---|---|
| `fn($id)` | `$id` vem do parâmetro da URL |
| `fn(User $user)` | Instância de User resolvida pelo Container |
| `fn(PaymentService $p)` | PaymentService instanciado com dependências |
| `fn(Request $req)` | Objeto Request da request atual |

---

## Identidade visual (sugestão)

| Elemento | Definição |
|---|---|
| **Nome** | SparkPHP |
| **Tagline** | Write what matters. |
| **CLI** | `spark` |
| **Extensão de template** | `.spark` |
| **Tom de comunicação** | Direto, técnico, sem adjetivos vazios |
| **Cor primária** | Amber/laranja (#F59E0B) — energia, velocidade, faísca |
| **Cor secundária** | Slate escuro (#1E293B) — seriedade, código |
| **Ícone** | Faísca geométrica minimalista |

---

## Resumo em uma frase

> SparkPHP é um framework PHP onde o sistema de arquivos é a configuração, o type-hint é o container, e o desenvolvedor só escreve o código que importa.
