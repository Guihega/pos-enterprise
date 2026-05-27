<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'POS Enterprise API',
    'version' => config('app.version', '0.1.0'),
    'docs' => '/api/v1/health',
]));
