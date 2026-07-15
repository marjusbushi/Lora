<?php

namespace App\Console\Commands;

use App\Services\PlatformBillingService;
use Illuminate\Console\Command;

class RunRecurringBilling extends Command
{
    protected $signature = 'billing:run-recurring';

    protected $description = 'Create invoices for active subscriptions whose next billing date is due.';

    public function handle(PlatformBillingService $billing): int
    {
        $billing->markOverdue();
        $result = $billing->processDueSubscriptions();

        $this->info("Created {$result['created']->count()} recurring invoice(s); {$result['failed']} subscription(s) failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
