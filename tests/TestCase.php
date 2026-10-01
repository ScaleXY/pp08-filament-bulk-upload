<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ScaleXY\FilamentBulkUpload\BulkUploadServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, SupportServiceProvider::class, SchemasServiceProvider::class,
            ActionsServiceProvider::class, FormsServiceProvider::class,
            LivewireServiceProvider::class, MediaLibraryServiceProvider::class, BulkUploadServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('media-library.max_file_size', 1_000_000_000);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['view']->addNamespace('filament-bulk-upload', __DIR__.'/views');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
        (require __DIR__.'/../database/migrations/2026_10_01_000000_create_bulk_upload_tables.php')->up();
        (require __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub')->up();
        Gate::define('create', fn ($user, $model) => $user->name === 'allowed');
        Gate::define('update', fn ($user, $record) => $user->name === 'allowed');
        $this->actingAs(User::create(['name' => 'allowed']));
    }
}
