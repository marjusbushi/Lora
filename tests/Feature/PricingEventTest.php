<?php

namespace Tests\Feature;

use App\Models\PricingEvent;
use Database\Seeders\PricingEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task #192 (Copa 1d): persisted pricing events with yearly-recurring
 * resolution — one source of truth for the engine uplift and the AI context.
 */
class PricingEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_recurring_holidays_idempotently(): void
    {
        $this->seed(PricingEventSeeder::class);
        $count = PricingEvent::count();
        $this->assertGreaterThanOrEqual(11, $count, '10 holidays + the August peak range');

        $this->seed(PricingEventSeeder::class);
        $this->assertSame($count, PricingEvent::count(), 're-seeding must not duplicate');

        $ferragosto = PricingEvent::where('name', 'like', 'Ferragosto%')->first();
        $this->assertTrue($ferragosto->recurring);
        $this->assertSame('system', $ferragosto->source);
        $this->assertNull($ferragosto->uplift_pct, 'seeded events are context-only until the owner sets uplifts');
    }

    public function test_recurring_event_resolves_in_any_year(): void
    {
        $this->seed(PricingEventSeeder::class);

        // Seeded with anchor year 2026 — must still hit on 15 Aug 2027 and 2030.
        foreach (['2027-08-15', '2030-08-15'] as $date) {
            $hits = PricingEvent::forDate($date);
            $this->assertTrue(
                $hits->contains(fn ($e) => str_starts_with($e->name, 'Ferragosto')),
                "Ferragosto must resolve on {$date}",
            );
        }

        // The August range covers mid-August but not September.
        $this->assertTrue(PricingEvent::forDate('2028-08-10')->contains(fn ($e) => str_contains($e->name, 'gushtit')));
        $this->assertFalse(PricingEvent::forDate('2028-09-10')->contains(fn ($e) => str_contains($e->name, 'gushtit')));
    }

    public function test_recurring_range_wraps_the_year_boundary(): void
    {
        PricingEvent::create([
            'name' => 'Festat e fundvitit',
            'date_from' => '2026-12-30',
            'date_to' => '2027-01-02',
            'recurring' => true,
            'source' => 'manual',
        ]);

        // Occurrence starting Dec 2028 spills into Jan 2029.
        $hit = PricingEvent::forDate('2029-01-01');
        $this->assertCount(1, $hit);
        $this->assertSame('2028-12-30', $hit->first()->resolved_from->toDateString());
        $this->assertSame('2029-01-02', $hit->first()->resolved_to->toDateString());

        $this->assertCount(0, PricingEvent::forDate('2029-01-05'), 'past the span — no match');
    }

    public function test_non_recurring_event_matches_only_its_own_dates(): void
    {
        PricingEvent::create([
            'name' => 'Festa e Sarandës 2026',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-22',
            'recurring' => false,
            'source' => 'manual',
        ]);

        $this->assertCount(1, PricingEvent::forDate('2026-08-21'));
        $this->assertCount(0, PricingEvent::forDate('2027-08-21'), 'non-recurring must not repeat next year');

        $window = PricingEvent::betweenDates('2026-08-01', '2026-08-31');
        $this->assertTrue($window->contains(fn ($e) => $e->name === 'Festa e Sarandës 2026'));
    }
}
