<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannexPingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
        ]);
    }

    public function test_not_configured_returns_failure(): void
    {
        config(['services.channex.api_key' => '']);

        $this->artisan('channex:ping')->assertFailed();
    }

    public function test_connected_but_empty_warns_and_succeeds(): void
    {
        Http::fake(['*properties*' => Http::response(['data' => []])]);

        $this->artisan('channex:ping')
            ->expectsOutputToContain('no properties')
            ->assertSuccessful();
    }

    public function test_lists_properties_on_success(): void
    {
        Http::fake(['*properties*' => Http::response(['data' => [
            ['id' => 'P1', 'attributes' => ['title' => 'Villa Mucho']],
        ]])]);

        $this->artisan('channex:ping')
            ->expectsOutputToContain('Villa Mucho')
            ->assertSuccessful();
    }

    public function test_http_failure_returns_failure_not_false_success(): void
    {
        Http::fake(['*properties*' => Http::response(['errors' => ['unauthorized']], 401)]);

        $this->artisan('channex:ping')->assertFailed();
    }
}
