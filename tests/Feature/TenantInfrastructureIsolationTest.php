<?php

namespace Tests\Feature;

use App\Jobs\Middleware\UseTenantContext;
use App\Jobs\PushRoomTypeAri;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\TenantKey;
use App\Support\TenantStorage;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TenantInfrastructureIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_keys_are_unique_per_tenant_and_fail_closed_without_context(): void
    {
        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        $context->set($first);
        $firstKey = TenantKey::make('shared-operation');
        Cache::put($firstKey, 'first');
        $firstSettingsKey = Setting::cacheKey();

        $context->set($second);
        $secondKey = TenantKey::make('shared-operation');
        $secondSettingsKey = Setting::cacheKey();

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertNotSame($firstSettingsKey, $secondSettingsKey);
        $this->assertNull(Cache::get($secondKey));
        Cache::put($secondKey, 'second');
        $this->assertSame('first', Cache::get($firstKey));
        $this->assertSame('second', Cache::get($secondKey));

        $context->clear();
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            TenantKey::make('missing-context');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_storage_paths_are_tenant_scoped_and_reject_unsafe_paths(): void
    {
        $context = app(TenantContext::class);
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        $context->set($first);
        $firstPath = TenantStorage::path('guest-documents/10');

        $context->set($second);
        $secondPath = TenantStorage::path('/guest-documents/10/');

        $this->assertSame("tenants/{$first->id}/guest-documents/10", $firstPath);
        $this->assertSame("tenants/{$second->id}/guest-documents/10", $secondPath);
        $this->assertNotSame($firstPath, $secondPath);

        $context->clear();
        $this->app['env'] = 'production';
        try {
            TenantStorage::path('guest-documents/10');
            $this->fail('Storage without tenant context should fail.');
        } catch (RuntimeException) {
            $this->assertNull($context->id());
        } finally {
            $this->app['env'] = 'testing';
        }

        $context->set($second);
        $this->expectException(InvalidArgumentException::class);
        TenantStorage::path('../other-hotel');
    }

    public function test_job_captures_dispatching_tenant_and_dispatch_without_context_fails(): void
    {
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->sole();
        $context->set($tenant);

        $job = new PushRoomTypeAri(123);
        $this->assertSame($tenant->id, $job->tenantId);

        $context->clear();
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            new PushRoomTypeAri(123);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_job_middleware_rejects_missing_or_suspended_tenant(): void
    {
        $job = new \stdClass;

        try {
            (new UseTenantContext(null))->handle($job, fn () => null);
            $this->fail('A job without tenant context should fail.');
        } catch (RuntimeException) {
            $this->assertNull(app(TenantContext::class)->id());
        }

        $tenant = Tenant::query()->sole();
        $tenant->update(['status' => 'suspended']);

        $this->expectException(RuntimeException::class);
        (new UseTenantContext($tenant->id))->handle($job, fn () => null);
    }
}
