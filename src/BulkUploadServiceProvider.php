<?php

namespace ScaleXY\FilamentBulkUpload;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use ScaleXY\FilamentBulkUpload\Commands\CleanupUploads;
use ScaleXY\FilamentBulkUpload\Commands\RecoverBatches;

class BulkUploadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-bulk-upload.php', 'filament-bulk-upload');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-bulk-upload');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->publishes([__DIR__.'/../config/filament-bulk-upload.php' => config_path('filament-bulk-upload.php')], 'bulk-upload-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'bulk-upload-migrations');
        FilamentAsset::register([
            AlpineComponent::make('bulk-media-upload', __DIR__.'/../dist/bulk-media-upload.js'),
            Css::make('bulk-media-upload', __DIR__.'/../dist/bulk-media-upload.css'),
        ], 'scalexy/filament-bulk-upload');
        if ($this->app->runningInConsole()) {
            $this->commands([CleanupUploads::class, RecoverBatches::class]);
        }
    }
}
