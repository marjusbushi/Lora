<?php

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * Demand-relevant holidays for the pricing calendar. FIXED-date entries only — Albanian public
 * holidays plus the Italian Ferragosto (~15 Aug) that floods Ksamil. Variable feasts (Orthodox/
 * Catholic Easter, Bajram) are intentionally omitted to avoid shipping wrong dates; extend the
 * map to add more. Keyed by 'MM-DD'; the year does not matter.
 */
class Holidays
{
    /** @var array<string,string> 'MM-DD' => Albanian name */
    private const FIXED = [
        '01-01' => 'Viti i Ri',
        '01-02' => 'Viti i Ri',
        '03-14' => 'Dita e Verës',
        '03-22' => 'Dita e Nevruzit',
        '05-01' => 'Dita e Punës',
        '08-15' => 'Ferragosto / Shën Maria',
        '09-05' => 'Nënë Tereza',
        '11-28' => 'Dita e Flamurit',
        '11-29' => 'Dita e Çlirimit',
        '12-25' => 'Krishtlindjet',
    ];

    /** Holiday name for a date, or null. Accepts a Carbon instance or a 'Y-m-d' string. */
    public static function for(CarbonInterface|string $date): ?string
    {
        $md = $date instanceof CarbonInterface ? $date->format('m-d') : substr((string) $date, 5, 5);

        return self::FIXED[$md] ?? null;
    }
}
