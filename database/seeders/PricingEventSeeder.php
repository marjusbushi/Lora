<?php

namespace Database\Seeders;

use App\Models\PricingEvent;
use Illuminate\Database\Seeder;

/**
 * Baseline recurring demand events for Ksamil pricing: the fixed Albanian
 * public holidays (mirrors App\Services\Holidays::FIXED) plus the August
 * diaspora/Ferragosto peak. uplift_pct stays NULL (context-only) — the
 * owner or the Copa 2 engine settings decide actual uplifts later.
 * Idempotent: safe to re-run on prod (`db:seed --class=PricingEventSeeder --force`).
 */
class PricingEventSeeder extends Seeder
{
    private const YEAR = 2026; // anchor year; recurring events repeat by month-day

    /** @var array<string, string> 'MM-DD' => name (single-day recurring holidays) */
    private const HOLIDAYS = [
        '01-01' => 'Viti i Ri',
        '01-02' => 'Viti i Ri (dita 2)',
        '03-14' => 'Dita e Verës',
        '03-22' => 'Dita e Nevruzit',
        '05-01' => 'Dita e Punës',
        '08-15' => 'Ferragosto / Shën Maria',
        '09-05' => 'Nënë Tereza',
        '11-28' => 'Dita e Flamurit',
        '11-29' => 'Dita e Çlirimit',
        '12-25' => 'Krishtlindjet',
    ];

    public function run(): void
    {
        foreach (self::HOLIDAYS as $monthDay => $name) {
            $date = self::YEAR.'-'.$monthDay;
            PricingEvent::firstOrCreate(
                ['name' => $name, 'source' => 'system'],
                ['date_from' => $date, 'date_to' => $date, 'recurring' => true, 'uplift_pct' => null],
            );
        }

        // The multi-week August surge (diaspora returns + Italian holidays) that
        // floods Ksamil — a range, not a single day.
        PricingEvent::firstOrCreate(
            ['name' => 'Piku i gushtit (diaspora + pushimet italiane)', 'source' => 'system'],
            ['date_from' => self::YEAR.'-08-01', 'date_to' => self::YEAR.'-08-25', 'recurring' => true, 'uplift_pct' => null],
        );
    }
}
