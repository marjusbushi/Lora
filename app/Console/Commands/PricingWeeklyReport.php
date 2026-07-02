<?php

namespace App\Console\Commands;

use App\Models\PricingReport;
use App\Services\AiPricing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * "Raporti javor i çmimeve": deterministic stats narrated by Gemini in
 * Albanian, stored one-per-week (regenerating the same week updates in
 * place). Skips quietly when no Gemini key is configured.
 */
class PricingWeeklyReport extends Command
{
    protected $signature = 'pricing:weekly-report';

    protected $description = 'Generate and store the weekly Albanian pricing report';

    public function handle(): int
    {
        if (! AiPricing::configured()) {
            $this->info('Gemini not configured — skipping the weekly report.');

            return self::SUCCESS;
        }

        $report = AiPricing::weeklyReport();
        if ($report['body'] === '') {
            $this->error('Empty report body — nothing stored.');

            return self::FAILURE;
        }

        // whereDate, not updateOrCreate: the date column persists with a
        // 00:00:00 time, so an equality match would miss and re-INSERT into
        // the unique week_start (same trap as rate_overrides).
        $weekStart = Carbon::today()->startOfWeek()->toDateString();
        $row = PricingReport::whereDate('week_start', $weekStart)->first()
            ?? new PricingReport(['week_start' => $weekStart]);
        $row->fill(['title' => $report['title'], 'body' => $report['body'], 'highlights' => $report['highlights']]);
        $row->save();
        $this->info('Weekly pricing report stored: '.$report['title']);

        return self::SUCCESS;
    }
}
