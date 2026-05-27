<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sanctum 4 NO autocarga sus migraciones (cambió respecto a v3),
        // por lo que no necesitamos ignoreMigrations() ni nada similar:
        // nuestra migración custom en database/migrations/ se ejecuta y
        // basta con eso.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
