<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains (no usado en nuestra config)
    |--------------------------------------------------------------------------
    |
    | Solo para SPA con cookies. Nosotros usamos Bearer tokens, así que esto
    | queda vacío y se ignora.
    |
    */
    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        ''
    )),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    */
    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Token Expiration
    |--------------------------------------------------------------------------
    |
    | Tiempo en minutos antes de que un token expire.
    | NULL = no expira automáticamente (se revoca explícitamente).
    | 720 = 12 horas (default que pusimos en .env).
    |
    */
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 720),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Prefijo de los tokens para que sean identificables (útil para detectar
    | leaks en repos públicos automáticamente).
    |
    */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'pos_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
