<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Mail\ScheduledReportMail;
use App\Models\SavedReport;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DeliverScheduledReports extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'reports:deliver-scheduled {--tenant= : ID e hotelit}';

    protected $description = 'Send due saved reports for the active tenant';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        $tenant = app(TenantContext::class)->tenant();
        $domain = $tenant?->domains()->orderByDesc('is_primary')->value('domain');

        SavedReport::query()
            ->where('is_active', true)
            ->whereNotNull('frequency')
            ->whereNotNull('delivery_email')
            ->where('next_delivery_at', '<=', now())
            ->orderBy('id')
            ->chunkById(50, function ($reports) use ($domain) {
                foreach ($reports as $report) {
                    $relative = route($report->route_name, $report->filters ?? [], false);
                    $url = $domain ? 'https://'.$domain.$relative : url($relative);

                    Mail::to($report->delivery_email)->send(new ScheduledReportMail($report, $url));
                    $report->last_delivered_at = now();
                    $report->scheduleNext();
                    $report->save();
                }
            });

        return self::SUCCESS;
    }
}
