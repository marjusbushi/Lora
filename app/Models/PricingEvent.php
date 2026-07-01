<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A demand event for pricing (holiday, festival, diaspora peak). Recurring
 * events repeat yearly by month-day; resolution happens in PHP (the table is
 * tiny) so a Dec 30 → Jan 2 event naturally wraps the year boundary.
 * One source of truth for both the deterministic engine (uplift_pct) and the
 * Gemini context (Copa 2 / Copa 4).
 */
class PricingEvent extends Model
{
    protected $fillable = [
        'name',
        'date_from',
        'date_to',
        'uplift_pct',
        'source',
        'recurring',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'uplift_pct' => 'decimal:2',
            'recurring' => 'boolean',
        ];
    }

    /**
     * Events active anywhere in [$from, $to], with recurring ones resolved to
     * the concrete year(s) of the window. Each item gets resolved_from /
     * resolved_to Carbon attributes for the queried occurrence.
     *
     * @return Collection<int, static>
     */
    public static function betweenDates(Carbon|string $from, Carbon|string $to): Collection
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        return static::all()->flatMap(function (self $event) use ($from, $to) {
            $hits = collect();

            if (! $event->recurring) {
                if ($event->date_from->lte($to) && $event->date_to->gte($from)) {
                    $event->resolved_from = $event->date_from->copy();
                    $event->resolved_to = $event->date_to->copy();
                    $hits->push($event);
                }

                return $hits;
            }

            // Recurring: materialize one occurrence per candidate year, keeping
            // the original day-span so year-end ranges wrap correctly.
            $span = $event->date_from->diffInDays($event->date_to);
            for ($year = $from->year - 1; $year <= $to->year; $year++) {
                $start = $event->date_from->copy()->setYear($year);
                $end = $start->copy()->addDays($span);
                if ($start->lte($to) && $end->gte($from)) {
                    $occurrence = $event->replicate();
                    $occurrence->id = $event->id;
                    $occurrence->resolved_from = $start;
                    $occurrence->resolved_to = $end;
                    $hits->push($occurrence);
                }
            }

            return $hits;
        })->values();
    }

    /** Events active on a single date. */
    public static function forDate(Carbon|string $date): Collection
    {
        return static::betweenDates($date, $date);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
