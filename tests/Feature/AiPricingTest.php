<?php

namespace Tests\Feature;

use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\User;
use App\Services\RoomPricing;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiPricingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    private function type(): RoomType
    {
        return RoomType::create(['name' => 'Deluxe', 'base_price' => 70, 'max_occupancy' => 2]);
    }

    private function fakeGemini(RoomType $type): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => [
                            'name' => 'submit_pricing_plan',
                            'args' => [
                                'summary' => 'Plani për Gushtin',
                                'recommendations' => [[
                                    'date_from' => '2026-08-14', 'date_to' => '2026-08-16',
                                    'label' => 'Festa e Sarandës', 'reason' => 'Festival + fundjavë.',
                                    'action' => 'raise', 'adjustment_pct' => 55,
                                    'prices' => [['room_type_id' => $type->id, 'room_type_name' => 'Deluxe', 'current' => 70, 'suggested' => 110]],
                                    'projected_extra' => '+€500',
                                ]],
                            ],
                        ],
                    ]]],
                ]],
            ], 200),
        ]);
    }

    public function test_ai_plan_returns_recommendations(): void
    {
        $type = $this->type();
        $this->fakeGemini($type);

        $this->actingAs($this->admin())
            ->postJson(route('pricing.smart.ai-plan'), ['month' => '2026-08-01', 'events' => ['15 Gush · Festa e Sarandës']])
            ->assertOk()
            ->assertJsonPath('recommendations.0.label', 'Festa e Sarandës')
            ->assertJsonPath('recommendations.0.prices.0.suggested', 110);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage.googleapis.com')
            && $r['tool_config']['function_calling_config']['allowed_function_names'][0] === 'submit_pricing_plan');
    }

    public function test_ai_plan_requires_a_key(): void
    {
        config()->set('services.gemini.key', null);

        $this->actingAs($this->admin())
            ->postJson(route('pricing.smart.ai-plan'), ['month' => '2026-08-01'])
            ->assertStatus(422);
    }

    public function test_max_tokens_returns_a_friendly_error(): void
    {
        $this->type();
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');

        // Simulate the thinking-budget-exhaustion failure mode: 200 OK, finishReason MAX_TOKENS, no call.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => []]]],
            ], 200),
        ]);

        $res = $this->actingAs($this->admin())
            ->postJson(route('pricing.smart.ai-plan'), ['month' => '2026-08-01']);

        $res->assertStatus(502);
        $this->assertStringContainsString('buxheti i tokenave', (string) $res->json('error'));
    }

    public function test_apply_plan_writes_overrides_across_the_range(): void
    {
        $type = $this->type(); // base 70

        $this->actingAs($this->admin())->post(route('pricing.smart.apply-plan'), [
            'date_from' => '2026-08-14', 'date_to' => '2026-08-16',
            'prices' => [['room_type_id' => $type->id, 'suggested' => 110]],
        ])->assertRedirect();

        $this->assertEquals(3, RateOverride::where('room_type_id', $type->id)->count()); // 14,15,16
        $this->assertEquals(110.0, RoomPricing::total($type, '2026-08-14', '2026-08-15')); // one night
        $this->assertEquals(330.0, RoomPricing::total($type, '2026-08-14', '2026-08-17')); // 3 × 110
    }
}
