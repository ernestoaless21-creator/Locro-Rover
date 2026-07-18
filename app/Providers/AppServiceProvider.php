<?php

namespace App\Providers;

use App\Services\Import\Adapters\LegacyExcelImportAdapter;
use App\Services\Import\Adapters\LocroRoverExcelImportAdapter;
use App\Services\Import\ImportAdapterRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fase P2: registro de adapters del importador historico (ver
        // App\Services\Import\ImportAdapterRegistry). Agregar un formato
        // nuevo = una clase que implemente ImportFormatAdapter + sumarla aca,
        // sin tocar ImportService ni el resto del pipeline.
        $this->app->tag([
            LegacyExcelImportAdapter::class,
            LocroRoverExcelImportAdapter::class,
        ], 'import.adapters');

        $this->app->bind(ImportAdapterRegistry::class, function ($app) {
            return new ImportAdapterRegistry(iterator_to_array($app->tagged('import.adapters')));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
