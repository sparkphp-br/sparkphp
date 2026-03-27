# Spark Template

Template engine do **SparkPHP**.

O Spark Template foi pensado para deixar a camada de view mais direta, previsível e próxima de HTML puro.

A proposta é simples: a view já é o conteúdo da página.
Não há necessidade de `@section` ou `@yield`. O layout envolve a view automaticamente, e a diretiva `@content` define o ponto exato em que esse conteúdo será renderizado.

Com isso, a estrutura fica mais enxuta, sem perder recursos importantes como composição de layout, inclusão de assets, condicionais, loops, formulários e helpers de renderização.

---

## Conceito central

No Spark Template, o arquivo da view representa a página em si.

Em vez de dividir a tela em seções para depois encaixá-las em um layout, você escreve a view normalmente e deixa o layout cuidar da moldura da página. Quando for necessário trocar o layout, definir o título, adicionar classes ao `<body>` ou empilhar CSS e JavaScript, isso é feito por diretivas curtas e diretas.

O resultado é uma sintaxe menor, com menos ruído estrutural e mais foco no conteúdo.

**Regra fundamental:** views nunca estendem nada. Layouts podem herdar de outros layouts quando necessário, mas a view sempre é tratada como conteúdo final.

**Extensão:** todos os arquivos de view usam a extensão `.spark`. O framework compila para PHP e cacheia automaticamente em `storage/cache/views/`.

---

## O que o Spark Template elimina

A principal decisão de design do Spark Template é remover verbosidades que existem em engines como o Blade sem perder funcionalidade. O princípio é: **se o framework consegue inferir a intenção, o desenvolvedor não deveria precisar declará-la.**

| Blade (Laravel) | Spark Template | Motivo da eliminação |
|---|---|---|
| `@extends('layouts.app')` | *(automático)* | O layout `main` é aplicado por convenção. Só declara `@layout('outro')` quando precisa trocar |
| `@section('content')` ... `@endsection` | *(não existe)* | O arquivo inteiro já é o conteúdo. Não precisa demarcar |
| `@yield('content')` | `@content` | Faz o mesmo, mas sem `@section` do outro lado |
| `@section('title', 'Página')` | `@title('Página')` | Diretiva direta, sem abstrair como "seção" |
| `@push('styles')` `<link href="...">` `@endpush` | `@css('nome')` | Três linhas viram uma. O path é resolvido por convenção |
| `@push('scripts')` `<script src="...">` `@endpush` | `@js('nome')` | Mesma simplificação |
| `@include('partials.user.card', ['user' => $user])` | `@partial('user/card', $user)` | Nome mais semântico, passagem direta sem array |
| `@csrf` | *(automático no `@form`)* | Segurança não deveria ser opt-in |
| `@method('PUT')` | *(automático no `@form`)* | O verbo já foi declarado em `@form('/url', 'PUT')` |
| `<label>` + `<input>` + `@error` + `old()` | `@input('name', label: 'Nome')` | Quatro blocos viram uma linha |
| `{{ asset('css/app.css') }}` | `@css('app')` | Convenção de diretório torna o helper de path desnecessário |
| `@guest` ... `@endguest` | `@else` dentro de `@auth` | Não precisa de diretiva separada para algo mutuamente exclusivo |
| `@empty($var)` ... `@endempty` | `@empty` dentro de `@foreach` | Lista vazia é parte do loop, não uma verificação separada |

---

## Layout padrão

`app/views/layouts/main.spark`

```html
<!DOCTYPE html>
<html lang="{{ env('APP_LANG', 'pt-BR') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? env('APP_NAME') }}</title>
    @stack('css')
</head>
<body class="{{ $bodyClass ?? '' }}">

    @partial('header')

    <main>
        @content
    </main>

    @partial('footer')

    @stack('js')
</body>
</html>
```

Nesse modelo:

- `@content` marca o ponto onde a view atual será inserida.
- `@stack('css')` e `@stack('js')` renderizam os assets declarados pela própria página.
- `@partial()` permite reutilizar blocos como header e footer sem depender de herança de template.

---

## Herança entre layouts

Layouts podem herdar de outros layouts para reaproveitar estrutura comum. Isso é útil quando se tem um layout público e um painel administrativo que compartilham o mesmo `<head>`, meta tags e assets base.

`app/views/layouts/admin.spark`

```html
@extends('main')

@bodyClass('admin-panel')

<div class="admin-wrapper">
    @partial('admin/sidebar')
    <div class="admin-content">
        @content
    </div>
</div>
```

O `@extends` só é permitido entre layouts. Ele substitui o `@content` do layout pai pela estrutura do layout filho, que por sua vez define seu próprio `@content` para receber a view.

A distinção é importante: **views nunca usam `@extends`**, apenas layouts podem herdar de outros layouts.

---

## Exemplo de view completa

`app/views/users/index.spark`

```html
@title('Gerenciar Usuários')
@css('datatables')
@js('users')
@bodyClass('page-users')

<div class="container">
    <h1>{{ $title }}</h1>

    @foreach($users as $user)
        <tr>
            <td>{{ $user->name }}</td>
            <td>{{ $user->email }}</td>
            <td>{{ $user->created_at | date }}</td>
            <td>@partial('user/actions', $user)</td>
        </tr>
    @empty
        @partial(
            'empty-state',
            icon: 'users',
            message: 'Nenhum usuário encontrado.'
        )
    @endforeach

    @paginate($users)
</div>
```

A view continua limpa mesmo com título, assets, classe de página, loop com fallback, partials e paginação. Não há camadas extras apenas para conversar com o layout.

---

## Diretivas do Spark Template

### 1. Estrutura e layout

Essas diretivas controlam o layout da página, composição de partes reutilizáveis e inclusão de assets.

```html
@layout('admin')
<!-- Define outro layout. Se omitido, usa layouts/main.spark -->

@title('Minha Página')
<!-- Define $title para uso no layout e na própria view -->

@bodyClass('dark-mode sidebar-open')
<!-- Adiciona classes ao <body> -->

@content
<!-- Usado apenas no layout. Marca onde a view será renderizada -->

@partial('header')
@partial('user/card', $user)
@partial('modal', title: 'Confirmar', size: 'lg')
<!-- Inclui partials com passagem direta de parâmetros -->

@css('datatables')
@css('https://cdn.example.com/lib.css')
<!-- Empilha CSS. Nome curto resolve /public/css/{nome}.css -->

@js('app')
@js('https://cdn.example.com/lib.js')
<!-- Empilha JS. Nome curto resolve /public/js/{nome}.js -->

@stack('css')
@stack('js')
<!-- Renderiza os assets empilhados. Usado no layout -->

@once
    @css('datepicker')
    @js('datepicker')
@endonce
<!-- Garante que o bloco só executa uma vez por renderização,
     mesmo que o partial seja chamado múltiplas vezes dentro de um loop -->
```

---

### 2. Saída e escaping

A engine faz escape automático por contexto, o que reduz erro manual e melhora segurança na renderização.

```html
{{ $variavel }}
<!-- Escape automático por contexto -->
<!-- Em HTML: htmlspecialchars -->
<!-- Em atributo href: escape de URL -->
<!-- Em <script>: escape para JS -->
<!-- Em style: escape para CSS -->

{!! $htmlBruto !!}
<!-- Saída sem escape -->
```

Também é possível aplicar transformações com pipes:

```html
{{ $preco | money }}              <!-- R$ 1.299,90 -->
{{ $data | date }}                <!-- 26/03/2026 -->
{{ $data | date:'d M Y' }}        <!-- 26 Mar 2026 -->
{{ $data | relative }}            <!-- há 3 horas -->
{{ $texto | limit:100 }}          <!-- Trunca com ... -->
{{ $nome | upper }}               <!-- DENILSON -->
{{ $nome | lower }}               <!-- denilson -->
{{ $nome | title }}               <!-- Denilson Silva -->
{{ $nome | initials }}            <!-- DS -->
{{ $valor | number }}             <!-- 1.234,56 -->
{{ $tamanho | bytes }}            <!-- 2,4 MB -->
{{ $lista | count }}              <!-- 15 -->
{{ $texto | slug }}               <!-- meu-texto-aqui -->
{{ $texto | nl2br }}              <!-- quebras de linha → <br> -->
{{ $texto | markdown }}           <!-- markdown → HTML -->
{{ $valor | default:'Não informado' }}  <!-- fallback se null ou vazio -->
```

Pipes podem ser encadeados:

```html
{{ $nome | lower | limit:20 }}
{{ $texto | limit:200 | default:'Sem descrição' }}
```

> **Nota sobre encadeamento:** o pipe `limit` após `markdown` pode truncar HTML no meio de uma tag, gerando markup quebrado. Quando usar `markdown`, prefira aplicar `limit` antes da conversão (`{{ $texto | limit:200 | markdown }}`), ou use o pipe `safe_limit` que respeita a estrutura de tags ao truncar.

---

### 3. Condicionais

A sintaxe mantém o fluxo familiar, mas adiciona atalhos semânticos para cenários comuns.

```html
@if($condicao)
    ...
@elseif($outra)
    ...
@else
    ...
@endif
```

**Autenticação e autorização:**

```html
@auth
    <p>Olá, {{ auth()->name }}</p>
@else
    <a href="/login">Fazer login</a>
@endauth

@role('admin')
    <a href="/admin">Painel Admin</a>
@endrole

@can('edit', $post)
    <button>Editar</button>
@endcan
```

O `@auth` funciona como um condicional unificado. O bloco antes do `@else` é exibido para usuários autenticados; o bloco após o `@else` é exibido para visitantes. O `@else` é opcional — se omitido, nada é renderizado para visitantes.

**Ambiente:**

```html
@dev
    <pre>{{ dump($vars) }}</pre>
@enddev

@prod
    <script>analytics.init()</script>
@endprod
```

---

### 4. Loops

O `@foreach` é o loop principal e aceita um bloco `@empty` integrado para tratar coleções vazias. Isso elimina a necessidade de diretivas separadas para verificar se a lista tem conteúdo.

```html
@foreach($users as $user)
    <div class="{{ $loop->even ? 'bg-gray' : '' }}">
        <span>#{{ $loop->iteration }}</span>
        <span>{{ $user->name }}</span>

        @first
            <span class="badge">Primeiro!</span>
        @endfirst

        @last
            <span class="badge">Último!</span>
        @endlast
    </div>
@empty
    <p>Nenhum usuário encontrado.</p>
@endforeach
```

O bloco `@empty` é opcional. Quando presente, é renderizado automaticamente se a coleção estiver vazia. Quando ausente, nada é exibido.

**Repetição simples:**

```html
@repeat(5)
    <div class="skeleton-loader"></div>
@endrepeat
```

**Loops tradicionais:**

```html
@for($i = 0; $i < 10; $i++)
    ...
@endfor

@while($condicao)
    ...
@endwhile
```

**Variável `$loop`:**

A variável `$loop` fica disponível automaticamente dentro de `@foreach`:

| Propriedade        | Descrição                               |
|--------------------|-----------------------------------------|
| `$loop->index`     | Índice atual (começa em 0)              |
| `$loop->iteration` | Iteração atual (começa em 1)            |
| `$loop->first`     | `true` no primeiro item                 |
| `$loop->last`      | `true` no último item                   |
| `$loop->even`      | `true` nas iterações pares              |
| `$loop->odd`       | `true` nas ímpares                      |
| `$loop->count`     | Total de itens na coleção               |
| `$loop->remaining` | Quantos itens restam                    |
| `$loop->parent`    | Referência ao `$loop` pai em loops aninhados |

---

### 5. Formulários

O objetivo é reduzir repetição sem esconder comportamento importante. Cada diretiva de campo gera automaticamente o conjunto completo de label, input, mensagem de erro e preenchimento com `old()`.

**Estrutura básica:**

```html
@form('/api/users', 'POST')
    <!-- CSRF incluído automaticamente -->
    <!-- method spoofing automático para PUT/PATCH/DELETE -->

    @input('name', label: 'Nome', required: true)
    @input('email', type: 'email', label: 'E-mail')
    @input('bio', type: 'textarea', label: 'Bio', rows: 4)

    @select('role', label: 'Perfil', options: $roles)
    @checkbox('active', label: 'Ativo', checked: $user->active)
    @radio('gender', options: ['M' => 'Masculino', 'F' => 'Feminino'])
    @file('avatar', label: 'Foto', accept: 'image/*')

    @submit('Salvar')
@endform
```

**Exemplo com PUT:**

```html
@form('/api/users/5', 'PUT')
    @input('name', value: $user->name)
    @submit('Atualizar')
@endform
```

**Atributos adicionais dos campos:**

```html
<!-- Placeholder -->
@input('phone', label: 'Telefone', placeholder: '(99) 99999-9999')

<!-- Campo desabilitado -->
@input('code', label: 'Código', value: $user->code, disabled: true)

<!-- Texto de orientação abaixo do campo -->
@input('slug', label: 'URL amigável', hint: 'Será gerado automaticamente se vazio')

<!-- Campo oculto -->
@hidden('redirect_to', '/dashboard')
```

**Agrupamento de campos:**

O `@group` permite alinhar campos lado a lado sem escrever markup de layout manualmente.

```html
@group
    @input('city', label: 'Cidade', width: '70%')
    @select('state', label: 'UF', options: $states, width: '30%')
@endgroup
```

**Comportamento automático dos campos:**

Cada diretiva de campo (`@input`, `@select`, `@checkbox`, `@radio`, `@file`) encapsula:

- Geração conjunta de `<label>`, campo e mensagem de validação.
- Preenchimento automático com `old()` após falha de validação.
- Exibição automática do erro correspondente ao campo, se houver.
- Atributos HTML repassados diretamente (como `class`, `id`, `data-*`).

---

### 6. Componentes

Componentes funcionam como partials com slots nomeados, o que facilita composições mais flexíveis.

**Definição:**

`app/views/partials/card.spark`

```html
<div class="card {{ $class ?? '' }}">
    @hasslot('header')
        <div class="card-header">
            @slot('header')
        </div>
    @endhasslot

    <div class="card-body">
        @slot('body')
    </div>

    @hasslot('footer')
        <div class="card-footer">
            @slot('footer')
        </div>
    @endhasslot
</div>
```

A diretiva `@hasslot('nome')` verifica se o slot foi preenchido na chamada do componente. Isso evita renderizar containers vazios e é mais explícito do que testar variáveis com `@isset`.

**Uso com slots nomeados:**

```html
@component('card', class: 'shadow')
    @slot('header')
        <h3>Título</h3>
    @endslot

    @slot('body')
        <p>Conteúdo do card.</p>
    @endslot
@endcomponent
```

**Uso com slot padrão:**

Quando não há slots nomeados, todo o conteúdo interno é direcionado automaticamente para o slot `body`.

```html
@component('card')
    <p>Isso vai direto para o slot body.</p>
@endcomponent
```

---

### 7. Helpers HTML

Esses helpers reduzem markup repetitivo e centralizam convenções da aplicação.

**Rota ativa:**

```html
<a href="/users" @active('/users')>Usuários</a>
<a href="/users" @active('/users', 'nav-selected')>Usuários</a>
<!-- Adiciona class="active" (ou a classe informada) quando a rota atual corresponde -->
```

**Imagens:**

```html
@img('avatar.jpg')
<!-- Renderiza: <img src="/public/images/avatar.jpg"> -->

@img('avatar.jpg', alt: 'Foto', class: 'rounded', width: 80)
<!-- Atributos adicionais são repassados diretamente -->
```

**Ícones:**

```html
@icon('users')
@icon('check', class: 'text-green', size: 24)
```

**JSON seguro:**

```html
@json($dados)
<!-- Gera saída JSON escapada para consumo seguro no front-end -->
```

**Meta tags SEO:**

```html
@meta(
    description: 'Minha página',
    keywords: 'php, framework',
    og_image: '/images/banner.jpg'
)
```

---

### 8. Cache e performance

O template expõe mecanismos simples para otimização de renderização diretamente na view.

**Cache de fragmento:**

```html
@cache('sidebar', 3600)
    @foreach($categories as $cat)
        <a href="/cat/{{ $cat->slug }}">{{ $cat->name }}</a>
    @endforeach
@endcache

<!-- Cache por contexto (ex: por usuário) -->
@cache('user-menu:' . auth()->id, 1800)
    ...
@endcache
```

**Carregamento tardio:**

O `@lazy` gera um placeholder no HTML que é substituído via `fetch()` no client-side após o carregamento da página.

```html
<!-- Carrega imediatamente após o page load -->
@lazy('/api/alerts')
    <span>Carregando...</span>
@endlazy

<!-- Carrega quando o elemento fica visível (Intersection Observer) -->
@lazy('/api/notifications', trigger: 'visible')
    <p>Carregando...</p>
@endlazy

<!-- Carrega após um intervalo definido -->
@lazy('/api/sidebar-stats', delay: 2000)
    @partial('skeleton/sidebar')
@endlazy
```

O conteúdo interno do `@lazy` funciona como placeholder enquanto a requisição não retorna. A resposta do endpoint substitui o bloco inteiro.

---

### 9. Páginas de erro

O framework renderiza automaticamente a view correspondente ao código HTTP do erro. A convenção é `views/errors/{code}.spark`. Não há necessidade de configurar handlers ou registrar exceções manualmente.

`app/views/errors/404.spark`

```html
@layout('main')
@title('Página não encontrada')

<div class="error-page">
    @icon('search', size: 64)
    <h1>404</h1>
    <p>A página que você procura não existe.</p>
    <a href="/">Voltar ao início</a>
</div>
```

`app/views/errors/500.spark`

```html
@layout('main')
@title('Erro interno')

<div class="error-page">
    @icon('alert-triangle', size: 64)
    <h1>500</h1>
    <p>Algo deu errado. Tente novamente em alguns instantes.</p>
    <a href="/">Voltar ao início</a>
</div>
```

Quando um erro HTTP é disparado (por exceção, `abort()` ou resposta manual), o framework verifica se existe a view `errors/{code}.spark`. Se existir, renderiza. Se não existir, retorna uma resposta genérica em texto.

---

### 10. PHP inline

Quando necessário, ainda é possível usar PHP diretamente dentro do template.

```php
@php
    $total = $items->sum('price');
    $formatted = number_format($total, 2, ',', '.');
@endphp

<p>Total: R$ {{ $formatted }}</p>
```

Esse recurso deve continuar disponível, mas como apoio pontual, não como estrutura principal da view.

---

## Compilação

O fluxo de compilação é transparente para o desenvolvedor:

```
index.spark  →  [Spark Engine compila]  →  index.php (cacheado em /storage/cache/views/)
```

O desenvolvedor nunca interage com os `.php` compilados. Escreve `.spark`, o framework cuida do resto.

- **Modo dev:** compila em tempo real a cada request, detectando alterações no arquivo.
- **Modo produção:** roda `php spark views:cache` e tudo fica pré-compilado.

---

## Comparativo de escrita

### Blade (Laravel)

```html
@extends('layouts.app')

@section('title', 'Editar Usuário')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/form.css') }}">
@endpush

@section('content')
<div class="container">
    <h1>Editar {{ $user->name }}</h1>

    <form method="POST" action="/users/{{ $user->id }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="name">Nome</label>
            <input
                type="text"
                name="name"
                id="name"
                value="{{ old('name', $user->name) }}"
                required
            >
            @error('name')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <div class="form-group">
            <label for="email">E-mail</label>
            <input
                type="email"
                name="email"
                id="email"
                value="{{ old('email', $user->email) }}"
            >
            @error('email')
                <span class="error">{{ $message }}</span>
            @enderror
        </div>

        <button type="submit">Atualizar</button>
    </form>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/form-validation.js') }}"></script>
@endpush
```

### Spark Template

```html
@title('Editar Usuário')
@css('form')
@js('form-validation')

<div class="container">
    <h1>Editar {{ $user->name }}</h1>

    @form('/users/' . $user->id, 'PUT')
        @input('name', label: 'Nome', value: $user->name, required: true)
        @input('email', type: 'email', label: 'E-mail', value: $user->email)
        @submit('Atualizar')
    @endform
</div>
```

A diferença não está apenas na redução de linhas (de 33 para 12), mas no nível de intenção explícita: a view passa a descrever diretamente a tela, sem carregar código estrutural ao redor.

---

## Referência rápida de diretivas

| Diretiva | Descrição |
|---|---|
| `@layout('nome')` | Troca o layout (padrão: `main`) |
| `@extends('nome')` | Herança entre layouts (somente layout → layout) |
| `@title('texto')` | Define `$title` para a view e o layout |
| `@bodyClass('classes')` | Adiciona classes ao `<body>` |
| `@content` | Ponto de inserção da view no layout |
| `@partial('nome', ...)` | Inclui partial com parâmetros |
| `@css('nome')` | Empilha stylesheet |
| `@js('nome')` | Empilha script |
| `@stack('css'\|'js')` | Renderiza assets empilhados |
| `@once ... @endonce` | Executa o bloco uma única vez por renderização |
| `{{ $var }}` | Saída com escape por contexto |
| `{!! $var !!}` | Saída sem escape |
| `{{ $var \| pipe }}` | Saída com transformação via pipe |
| `@if / @elseif / @else / @endif` | Condicional |
| `@auth ... @else ... @endauth` | Condicional de autenticação |
| `@role('nome') ... @endrole` | Condicional de papel |
| `@can('ação', $obj) ... @endcan` | Condicional de permissão |
| `@dev ... @enddev` | Condicional de ambiente (desenvolvimento) |
| `@prod ... @endprod` | Condicional de ambiente (produção) |
| `@foreach ... @empty ... @endforeach` | Loop com fallback integrado |
| `@first ... @endfirst` | Bloco exibido só na primeira iteração |
| `@last ... @endlast` | Bloco exibido só na última iteração |
| `@for / @while / @repeat` | Loops tradicionais |
| `@form('url', 'METHOD') ... @endform` | Formulário com CSRF e method spoofing |
| `@input / @select / @checkbox / @radio / @file` | Campos com label, old() e errors() automáticos |
| `@hidden('nome', valor)` | Campo oculto |
| `@group ... @endgroup` | Agrupamento de campos inline |
| `@submit('texto')` | Botão de envio |
| `@component('nome') ... @endcomponent` | Componente com slots |
| `@slot('nome') ... @endslot` | Define conteúdo de um slot |
| `@hasslot('nome') ... @endhasslot` | Condicional de existência de slot |
| `@active('rota')` | Classe CSS de rota ativa |
| `@img / @icon / @json / @meta` | Helpers HTML |
| `@cache('key', ttl) ... @endcache` | Cache de fragmento |
| `@lazy('url') ... @endlazy` | Carregamento tardio via fetch |
| `@php ... @endphp` | Bloco PHP inline |

---

## Pipes disponíveis

| Pipe | Exemplo | Resultado |
|---|---|---|
| `money` | `{{ 1299.9 \| money }}` | R$ 1.299,90 |
| `date` | `{{ $data \| date }}` | 26/03/2026 |
| `date:'fmt'` | `{{ $data \| date:'d M Y' }}` | 26 Mar 2026 |
| `relative` | `{{ $data \| relative }}` | há 3 horas |
| `limit:N` | `{{ $texto \| limit:100 }}` | Trunca com … |
| `safe_limit:N` | `{{ $html \| safe_limit:200 }}` | Trunca respeitando tags HTML |
| `upper` | `{{ $nome \| upper }}` | DENILSON |
| `lower` | `{{ $nome \| lower }}` | denilson |
| `title` | `{{ $nome \| title }}` | Denilson Silva |
| `initials` | `{{ $nome \| initials }}` | DS |
| `number` | `{{ 1234.56 \| number }}` | 1.234,56 |
| `bytes` | `{{ 2500000 \| bytes }}` | 2,4 MB |
| `count` | `{{ $lista \| count }}` | 15 |
| `slug` | `{{ $texto \| slug }}` | meu-texto-aqui |
| `nl2br` | `{{ $texto \| nl2br }}` | Quebras → `<br>` |
| `markdown` | `{{ $texto \| markdown }}` | Markdown → HTML |
| `default:'val'` | `{{ $x \| default:'N/A' }}` | Fallback se null ou vazio |

---

## Fechamento

O Spark Template parte de uma ideia simples: menos estrutura obrigatória, mais foco no conteúdo da página.

O layout continua existindo, os recursos continuam presentes, e a composição da interface segue poderosa. A diferença é que a view deixa de ser um conjunto de blocos encaixados e passa a ser lida como aquilo que realmente representa: a própria página.
