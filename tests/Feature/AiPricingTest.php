<?php

namespace Tests\Feature;

use App\Models\PricingEvent;
use App\Models\PricingReport;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task #195 (Copa 4): Gemini's four jobs — explain, suggest events, weekly
 * report, calendar Q&A. The LLM never sets a price; nothing it suggests is
 * written without the owner's approval; the key travels in a header.
 */
class AiPricingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function configureGemini(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    /** Fake ONE forced-function response for whichever tool the code calls. */
    private function fakeGemini(array $args): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['functionCall' => ['name' => 'any', 'args' => $args]]]],
                ]],
            ], 200),
        ]);
    }

    /** The fake above ignores the tool name — patch it to echo the requested one. */
    private function fakeGeminiFor(string $tool, array $args): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) use ($tool, $args) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['functionCall' => ['name' => $tool, 'args' => $args]]]],
                    ]],
                ], 200);
            },
        ]);
    }

    private function type(): RoomType
    {
        $type = RoomType::create(['name' => 'Twin', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        Room::create(['room_number' => 'A1', 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'available']);

        return $type;
    }

    public function test_key_travels_in_header_never_in_the_url(): void
    {
        $this->configureGemini();
        $this->fakeGeminiFor('submit_answer', ['answer' => 'Testim.']);
        $admin = $this->admin();
        $type = $this->type();

        $this->actingAs($admin)->postJson(route('pricing.smart.ask'), [
            'question' => 'Si duket gushti?', 'month' => now()->toDateString(), 'room_type_id' => $type->id,
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($request->url(), 'key=');
        });
    }

    public function test_explain_returns_sentence_and_caches(): void
    {
        $this->configureGemini();
        $this->fakeGeminiFor('submit_explanation', ['sentence' => 'Çmimi u rrit se dhoma po mbushet.']);
        $admin = $this->admin();
        $type = $this->type();

        // Book the only room so the day has factors (100% occupancy).
        \App\Models\Reservation::create([
            'room_id' => Room::first()->id,
            'guest_id' => \App\Models\Guest::create(['first_name' => 'G', 'last_name' => 'X'])->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->addDays(16)->toDateString(),
            'check_out_date' => now()->addDays(17)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);
        $date = now()->addDays(16)->toDateString();

        $this->actingAs($admin)->postJson(route('pricing.smart.explain'), ['date' => $date, 'room_type_id' => $type->id])
            ->assertOk()->assertJson(['sentence' => 'Çmimi u rrit se dhoma po mbushet.']);

        // Second identical call is served from cache — no new HTTP request.
        $this->actingAs($admin)->postJson(route('pricing.smart.explain'), ['date' => $date, 'room_type_id' => $type->id])
            ->assertOk();
        Http::assertSentCount(1);
    }

    public function test_suggested_events_write_nothing_until_approved(): void
    {
        $this->configureGemini();
        $this->fakeGeminiFor('submit_event_suggestions', ['events' => [
            ['name' => 'Bajrami i Madh', 'date_from' => '2027-03-09', 'date_to' => '2027-03-11', 'uplift_pct' => 10, 'reason' => 'Diaspora kthehet.'],
        ]]);
        $admin = $this->admin();
        $this->type();

        $res = $this->actingAs($admin)->postJson(route('pricing.smart.events.suggest'))->assertOk();
        $this->assertCount(1, $res->json('events'));
        $this->assertSame(0, PricingEvent::count(), 'suggestion alone must write NOTHING');

        // Approval writes it, stamped as AI-sourced.
        $this->actingAs($admin)->post(route('pricing.smart.events.approve'), $res->json('events')[0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $event = PricingEvent::first();
        $this->assertSame('Bajrami i Madh', $event->name);
        $this->assertSame('ai', $event->source);
        $this->assertEquals(10.0, (float) $event->uplift_pct);
    }

    public function test_weekly_report_command_persists_and_is_scheduled(): void
    {
        $this->configureGemini();
        $this->fakeGeminiFor('submit_weekly_report', [
            'title' => 'Java në një shikim',
            'body' => 'Zënia mesatare 40%. Fundjavat po mbushen — mos i ul.',
            'highlights' => ['Gushti 90% i zënë'],
        ]);
        $this->type();

        $this->artisan('pricing:weekly-report')->assertSuccessful();
        $report = PricingReport::first();
        $this->assertNotNull($report);
        $this->assertSame('Java në një shikim', $report->title);
        $this->assertSame(['Gushti 90% i zënë'], $report->highlights);

        // Re-run same week updates in place (unique week_start).
        $this->artisan('pricing:weekly-report')->assertSuccessful();
        $this->assertSame(1, PricingReport::count());

        $this->artisan('schedule:list')->expectsOutputToContain('pricing:weekly-report')->assertSuccessful();
    }

    public function test_ask_is_grounded_in_engine_data(): void
    {
        $this->configureGemini();
        $this->fakeGeminiFor('submit_answer', ['answer' => 'Gushti duket i fortë.']);
        $admin = $this->admin();
        $type = $this->type();

        $this->actingAs($admin)->postJson(route('pricing.smart.ask'), [
            'question' => 'Si duket muaji?', 'month' => now()->toDateString(), 'room_type_id' => $type->id,
        ])->assertOk()->assertJson(['answer' => 'Gushti duket i fortë.']);

        // The prompt body must carry REAL engine grounding (days + strategy).
        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains($body, 'strategy') && str_contains($body, now()->toDateString());
        });
    }

    public function test_unconfigured_gemini_returns_friendly_422(): void
    {
        config()->set('services.gemini.key', null);
        $admin = $this->admin();
        $type = $this->type();

        $this->actingAs($admin)->postJson(route('pricing.smart.explain'), ['date' => now()->toDateString(), 'room_type_id' => $type->id])
            ->assertStatus(422);
        $this->actingAs($admin)->postJson(route('pricing.smart.events.suggest'))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('pricing.smart.ask'), ['question' => 'a?', 'month' => now()->toDateString()])
            ->assertStatus(422);
        $this->artisan('pricing:weekly-report')->assertSuccessful(); // skips quietly
        $this->assertSame(0, PricingReport::count());
    }

    public function test_page_renders_with_events_and_report_props(): void
    {
        $admin = $this->admin();
        $this->type();
        PricingEvent::create(['name' => 'Ferragosto', 'date_from' => now()->addDays(10)->toDateString(), 'date_to' => now()->addDays(10)->toDateString(), 'source' => 'system', 'recurring' => true]);
        PricingReport::create(['week_start' => now()->startOfWeek()->toDateString(), 'title' => 'T', 'body' => 'B', 'highlights' => []]);

        $props = $this->actingAs($admin)->get(route('pricing.smart.index'))->assertOk()
            ->viewData('page')['props'];
        $this->assertNotEmpty($props['upcomingEvents']);
        $this->assertSame('T', $props['latestReport']['title']);
    }
}
