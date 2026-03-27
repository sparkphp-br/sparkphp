<?php

// Auth Middleware
// Redireciona para /login se o usuário não estiver autenticado.

if (!auth()) {
    $request = app()->getContainer()->make(Request::class);

    if ($request->acceptsJson()) {
        return json(['error' => 'Unauthenticated.'], 401);
    }

    return redirect('/login');
}

// Retornar null = seguir em frente
