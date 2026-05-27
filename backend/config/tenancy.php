<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración de multi-tenancy
|--------------------------------------------------------------------------
|
| Política y comportamiento del subsistema multi-tenant.
| Ver ADR-0003 (modelo Pool por defecto) y ADR-0006 (RLS).
|
*/

return [

    /*
    |---------------------------------------------------------------------
    | Dominio raíz para resolución por subdominio
    |---------------------------------------------------------------------
    |
    | Si el host del request es {slug}.{domain}, resolvemos el tenant por
    | "slug". En desarrollo local típicamente se deja vacío (no se usa
    | subdominio) y se resuelve por header.
    |
    */
    'domain' => env('TENANT_DOMAIN', null),

    /*
    |---------------------------------------------------------------------
    | Header para resolución manual / mobile
    |---------------------------------------------------------------------
    */
    'header_name' => env('TENANT_HEADER_NAME', 'X-Tenant'),

    /*
    |---------------------------------------------------------------------
    | Fallback en desarrollo
    |---------------------------------------------------------------------
    |
    | Si no se pudo resolver el tenant por ninguna estrategia, ¿caemos al
    | primer tenant activo? Útil para pruebas exploratorias con curl/psql.
    | NUNCA habilitar en producción.
    |
    */
    'fallback_to_default' => env('TENANT_FALLBACK_TO_DEFAULT', false),

    /*
    |---------------------------------------------------------------------
    | Estrategias de resolución habilitadas (orden importa)
    |---------------------------------------------------------------------
    |
    | Lista de estrategias activas. El middleware las prueba en orden.
    |
    */
    'resolution' => array_filter(
        explode(',', env('TENANT_RESOLUTION', 'subdomain,header,jwt'))
    ),

];
