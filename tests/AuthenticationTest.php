<?php

namespace ScaleXY\FilamentBulkUpload\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use ScaleXY\FilamentBulkUpload\Support\Access;

class AuthenticationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.guards.tenant_admin', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('filament-bulk-upload.auth_guard', 'tenant_admin');
        $app['config']->set('filament-bulk-upload.middleware', ['web', 'auth:tenant_admin']);
    }

    public static function tenantKey(): ?string
    {
        return config('tests.current_tenant');
    }

    public function test_cached_static_resolver_reads_the_current_tenant_at_runtime(): void
    {
        $resolver = [self::class, 'tenantKey'];
        $cachedResolver = eval('return '.var_export($resolver, true).';');
        config(['filament-bulk-upload.tenant_resolver' => $cachedResolver]);
        $access = app(Access::class);
        $this->assertNull($access->tenant());
        config(['tests.current_tenant' => 'tenant-a']);
        $this->assertSame('tenant-a', $access->tenant());
        config(['tests.current_tenant' => 'tenant-b']);
        $this->assertSame('tenant-b', $access->tenant());
    }

    public function test_upload_routes_require_the_matching_session_csrf_token(): void
    {
        Route::getRoutes()->getByName('bulk-upload.sessions')->middleware(StrictCsrfMiddleware::class);
        $this->actingAs(User::create(['name' => 'allowed']), 'tenant_admin');
        $access = app(Access::class);
        $token = Crypt::encrypt(['owner' => $access->owner(), 'tenant' => null, 'expires' => time() + 60,
            'settings' => ['model' => Document::class, 'collection' => 'default', 'disk' => 's3', 'field' => 'data.media']]);
        $this->withSession(['_token' => 'server-token'])->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertStatus(419);
        $this->withSession(['_token' => 'server-token'])->postJson('/filament-bulk-upload/sessions', ['token' => $token], ['X-CSRF-TOKEN' => 'wrong'])->assertStatus(419);
        $this->withSession(['_token' => 'server-token'])->postJson('/filament-bulk-upload/sessions', ['token' => $token], ['X-CSRF-TOKEN' => 'server-token'])->assertOk();
    }

    public function test_tenant_guard_controls_endpoint_ownership_and_policy_authorization(): void
    {
        $tenantUser = User::create(['name' => 'allowed']);
        $this->actingAs($tenantUser, 'tenant_admin');
        Auth::shouldUse('web');
        Auth::guard('web')->setUser(User::create(['name' => 'denied']));
        $access = app(Access::class);
        $this->assertSame($tenantUser->getMorphClass().':'.$tenantUser->id, $access->owner());
        $settings = ['model' => Document::class, 'collection' => 'default', 'disk' => 's3', 'field' => 'data.media'];
        $access->authorize($settings);
        $token = Crypt::encrypt(['owner' => $access->owner(), 'tenant' => null, 'expires' => time() + 60, 'settings' => $settings]);
        $this->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertOk();
        Auth::guard('tenant_admin')->forgetUser();
        $this->postJson('/filament-bulk-upload/sessions', ['token' => $token])->assertUnauthorized();
    }
}
