<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\AiChat;
use App\Services\GeminiClient;
use App\Services\OpenAiClient;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dera e përbashkët AiChat (task #408): zgjedhja e providerit është e
 * PLATFORMËS (default global + mbivendosje per-tenant, PlatformSetting) dhe
 * rezerva NDËR-PROVIDER ndizet vetëm me flamur — Google dhe OpenAI nuk
 * sëmuren kurrë në të njëjtën ditë.
 */
class AiChatRouterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private const ARGS = ['SYSTEM', 'MYSAFIRI: hej', [], [], 'guest_reply'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function reply(string $provider): array
    {
        return [
            'args' => ['confident' => true, 'reply' => 'Përshëndetje!', 'kind' => 'small_talk'],
            'toolsUsed' => [],
            'usage' => ['input' => 10, 'output' => 5, 'thinking' => 0, 'provider' => $provider, 'model' => 'test'],
        ];
    }

    public function test_default_provider_is_gemini_and_existing_behavior_is_unchanged(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andReturn($this->reply('gemini')));
        $this->mock(OpenAiClient::class, fn ($mock) => $mock->shouldReceive('converse')->never());

        $result = app(AiChat::class)->converse(...self::ARGS);

        $this->assertSame('gemini', app(AiChat::class)->provider());
        $this->assertSame('gemini', $result['usage']['provider']);
    }

    public function test_tenant_override_switches_to_openai_without_touching_other_tenants(): void
    {
        PlatformSetting::set('ai.provider_overrides', [(string) $this->tenant->id => 'openai'], 'json');

        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andReturn($this->reply('openai')));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('converse')->never());

        $result = app(AiChat::class)->converse(...self::ARGS);

        $this->assertSame('openai', $result['usage']['provider']);
        // Tenant tjetër (pa mbivendosje) ndjek default-in — asnjë rrjedhje.
        $this->assertSame('gemini', app(AiChat::class)->provider($this->tenant->id + 999));
    }

    public function test_platform_default_can_move_everyone_to_openai(): void
    {
        PlatformSetting::set('ai.provider_default', 'openai', 'text');

        $this->assertSame('openai', app(AiChat::class)->provider());
    }

    public function test_invalid_configured_values_fall_back_to_gemini(): void
    {
        PlatformSetting::set('ai.provider_default', 'skynet', 'text');
        PlatformSetting::set('ai.provider_overrides', [(string) $this->tenant->id => 'hal9000'], 'json');

        $this->assertSame('gemini', app(AiChat::class)->provider());
    }

    /** KRITIK (kriteri 3): flamuri ON + dështim kalimtar → provohet provideri tjetër. */
    public function test_cross_fallback_on_switches_to_the_other_provider_on_transient_failure(): void
    {
        PlatformSetting::set('ai.cross_provider_fallback', '1', 'boolean');

        $this->mock(GeminiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andThrow(new \RuntimeException('Google ktheu një gabim (503). Provo sërish.')));
        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('configured')->andReturn(true)
            ->shouldReceive('converse')->once()->andReturn($this->reply('openai')));

        $result = app(AiChat::class)->converse(...self::ARGS);

        $this->assertSame('openai', $result['usage']['provider']);
    }

    /** KRITIK (kriteri 3): flamuri OFF → sjellja e #403 — gabimi bublon, tjetri s'preket. */
    public function test_cross_fallback_off_bubbles_the_transient_error(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andThrow(new \RuntimeException('Google ktheu një gabim (503). Provo sërish.')));
        $this->mock(OpenAiClient::class, fn ($mock) => $mock->shouldReceive('converse')->never());

        $this->expectExceptionMessage('gabim (503)');

        app(AiChat::class)->converse(...self::ARGS);
    }

    public function test_cross_fallback_ignores_non_transient_errors(): void
    {
        PlatformSetting::set('ai.cross_provider_fallback', '1', 'boolean');

        $this->mock(GeminiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andThrow(new \RuntimeException('Çelësi qendror Gemini i platformës nuk është i vlefshëm — njofto mbështetjen e Lora PMS.')));
        $this->mock(OpenAiClient::class, fn ($mock) => $mock->shouldReceive('converse')->never());

        $this->expectExceptionMessage('nuk është i vlefshëm');

        app(AiChat::class)->converse(...self::ARGS);
    }

    public function test_cross_fallback_skips_an_unconfigured_other_provider(): void
    {
        PlatformSetting::set('ai.cross_provider_fallback', '1', 'boolean');

        $this->mock(GeminiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andThrow(new \RuntimeException('Google nuk u përgjigj në kohë (timeout). Provo sërish.')));
        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('configured')->andReturn(false)
            ->shouldReceive('converse')->never());

        $this->expectExceptionMessage('(timeout)');

        app(AiChat::class)->converse(...self::ARGS);
    }

    public function test_cross_fallback_failure_bubbles_the_original_error(): void
    {
        PlatformSetting::set('ai.cross_provider_fallback', '1', 'boolean');

        $this->mock(GeminiClient::class, fn ($mock) => $mock
            ->shouldReceive('converse')->once()->andThrow(new \RuntimeException('Google ktheu një gabim (503). Provo sërish.')));
        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('configured')->andReturn(true)
            ->shouldReceive('converse')->once()->andThrow(new \RuntimeException('OpenAI ktheu një gabim (500). Provo sërish.')));

        // Gabimi ORIGJINAL (i providerit të tenantit) bublon — jo i rezervës.
        $this->expectExceptionMessage('Google ktheu një gabim (503)');

        app(AiChat::class)->converse(...self::ARGS);
    }
}
