# Validation

O SparkPHP valida input com uma unica funcao `validate()`. Sem classes, sem form requests — apenas regras e dados.

## Uso basico

```php
// app/routes/users.php

post(function () {
    $data = validate([
        'name'  => 'required|string|min:3|max:100',
        'email' => 'required|email|unique:users,email',
        'age'   => 'required|int|between:18,120',
    ]);

    // Se chegou aqui, $data contem apenas os campos validados
    return User::create($data);
});
```

Se a validacao falhar:

- **Request JSON** (API): retorna `422` com envelope padrao:
  `{"error":"The given data was invalid.","status":422,"code":"validation_error","errors":{"campo":"mensagem"}}`
- **Request HTML** (formulario): redireciona `back()` com erros e `old()` input na sessao

## Integracao com OpenAPI

As regras declaradas em `validate([...])` tambem servem de base para o comando:

```bash
php spark api:spec
```

O gerador usa essas regras para inferir o `requestBody` da spec OpenAPI, incluindo:

- campos obrigatorios (`required`)
- tipos basicos (`string`, `int`, `float`, `bool`, `email`, `url`, `date`)
- limites (`min`, `max`, `between`)
- enums (`in:...`)

Exemplo:

```php
post(function () {
    $data = validate([
        'name' => 'required|string|min:3|max:100',
        'email' => 'required|email',
        'role' => 'in:admin,editor,user',
        'active' => 'bool',
    ]);

    return User::create($data);
});
```

vira um `requestBody` com schema equivalente a:

```php
[
    'type' => 'object',
    'required' => ['name', 'email'],
    'properties' => [
        'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 100],
        'email' => ['type' => 'string', 'format' => 'email'],
        'role' => ['type' => 'string', 'enum' => ['admin', 'editor', 'user']],
        'active' => ['type' => 'boolean'],
    ],
]
```

---

## Regras disponiveis

### Tipo

| Regra       | Descricao                           |
|-------------|-------------------------------------|
| `string`    | Deve ser string                     |
| `int`       | Deve ser inteiro                    |
| `float`     | Deve ser float/decimal              |
| `bool`      | Deve ser booleano                   |
| `numeric`   | Deve ser numerico (string ok)       |
| `date`      | Deve ser data valida                |

### Presenca

| Regra       | Descricao                           |
|-------------|-------------------------------------|
| `required`  | Campo obrigatorio (nao vazio)       |
| `nullable`  | Permite null (pula demais regras)   |

### Tamanho

| Regra          | Descricao                                    |
|----------------|----------------------------------------------|
| `min:3`        | String: minimo 3 chars. Numero: minimo 3     |
| `max:255`      | String: maximo 255 chars. Numero: maximo 255 |
| `between:1,10` | Valor entre 1 e 10 (inclusivo)               |

### Formato

| Regra          | Descricao                            |
|----------------|--------------------------------------|
| `email`        | E-mail valido                        |
| `url`          | URL valida                           |
| `regex:/^\d+$/`| Deve casar com a regex               |

### Conjunto

| Regra                   | Descricao                                                  |
|-------------------------|------------------------------------------------------------|
| `in:draft,published`    | Valor deve ser um dos listados                             |
| `unique:users,email`    | Valor nao existe na tabela.coluna                          |
| `exists:roles,id`       | Valor deve existir na tabela.coluna                        |

### Confirmacao

| Regra        | Descricao                                                    |
|--------------|--------------------------------------------------------------|
| `confirmed`  | Exige campo `{campo}_confirmation` com mesmo valor           |

### Data

| Regra             | Descricao                         |
|-------------------|-----------------------------------|
| `before:2026-12-31` | Data anterior a                |
| `after:2026-01-01`  | Data posterior a               |

### Arquivo

| Regra             | Descricao                         |
|-------------------|-----------------------------------|
| `file`            | Deve ser upload valido            |
| `image`           | Deve ser imagem (jpeg, png, gif, webp) |
| `max_size:2048`   | Tamanho maximo em KB              |

---

## Exemplos praticos

### Cadastro de usuario

```php
post(function () {
    $data = validate([
        'name'     => 'required|string|min:2|max:100',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|string|min:8|confirmed',
        'terms'    => 'required|bool',
    ]);

    $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
    unset($data['terms']);

    $user = User::create($data);
    login($user);

    return redirect('/dashboard');
});
```

### Atualizacao de perfil

```php
// app/routes/profile.php

put(function () {
    $data = validate([
        'name'   => 'required|string|min:2',
        'bio'    => 'nullable|string|max:500',
        'avatar' => 'nullable|image|max_size:2048',
    ]);

    auth()->update($data);

    return redirect('/profile');
});
```

### Filtros de busca (opcionais)

```php
get(function () {
    $filters = validate([
        'status' => 'nullable|in:active,inactive,banned',
        'sort'   => 'nullable|in:name,created_at',
        'page'   => 'nullable|int|min:1',
    ]);

    return User::where($filters)->paginate(20);
});
```

---

## Mensagens de erro personalizadas

```php
$data = validate([
    'email' => 'required|email|unique:users,email',
], [
    'email.required' => 'Informe seu e-mail.',
    'email.email'    => 'E-mail invalido.',
    'email.unique'   => 'Este e-mail ja esta em uso.',
]);
```

---

## Exibindo erros na view

### Listar todos os erros

```
@if(errors())
    <div class="alert alert-danger">
        <ul>
        @foreach(errors() as $field => $messages)
            @foreach($messages as $msg)
                <li>{{ $msg }}</li>
            @endforeach
        @endforeach
        </ul>
    </div>
@endif
```

### Erro de um campo especifico

```
@error('email')
    <span class="text-red">{{ $message }}</span>
@enderror
```

### Input antigo (old)

```html
<input type="text" name="name" value="{{ old('name') }}">
<input type="email" name="email" value="{{ old('email') }}">
```

Apos falha de validacao em form HTML, os valores digitados sao restaurados automaticamente.

## Proximo passo

→ [Middleware](07-middleware.md)
