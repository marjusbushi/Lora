<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformBillingService;
use App\Services\TenantBillingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);
    }

    public function test_super_admin_can_create_and_publish_a_subscription_invoice(): void
    {
        $tenant = Tenant::query()->sole();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $billing = app(TenantBillingService::class)->summary($tenant);
        $expectedLines = collect($billing['modules'])
            ->filter(fn (array $module) => $module['enabled'] && $module['monthly_cents'] > 0)
            ->count();

        $this->actingAs($admin)
            ->post('https://admin.lorapms.test/super-admin/billing/invoices', [
                'tenant_id' => $tenant->id,
                'period_starts_on' => '2026-08-01',
                'period_ends_on' => '2026-08-31',
                'due_on' => '2026-08-15',
                'issue_now' => true,
                'notes' => 'Fatura e gushtit.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $invoice = BillingInvoice::query()->with('lines')->sole();
        $this->assertSame('open', $invoice->status);
        $this->assertSame($billing['monthly_fixed_cents'], $invoice->total_cents);
        $this->assertSame($expectedLines, $invoice->lines->count());
        $this->assertStringStartsWith('INV-2026-', $invoice->number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.invoice.create', 'tenant_id' => $tenant->id]);
    }

    public function test_manual_payment_updates_invoice_balance_and_status_atomically(): void
    {
        $tenant = Tenant::query()->sole();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $invoice = BillingInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $tenant->subscription->id,
            'number' => 'INV-2026-00001',
            'status' => 'open',
            'currency' => 'EUR',
            'subtotal_cents' => 8300,
            'total_cents' => 8300,
            'due_on' => '2026-08-15',
        ]);

        $this->actingAs($admin)
            ->post('https://admin.lorapms.test/super-admin/billing/payments', [
                'billing_invoice_id' => $invoice->id,
                'amount' => '83.00',
                'method' => 'bank_transfer',
                'reference' => 'BKT-TEST-1',
                'paid_at' => '2026-08-10 10:30:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('billing_invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
            'amount_paid_cents' => 8300,
        ]);
        $this->assertDatabaseHas('billing_payments', [
            'billing_invoice_id' => $invoice->id,
            'provider' => 'manual',
            'amount_cents' => 8300,
            'reference' => 'BKT-TEST-1',
        ]);
        $this->assertStringStartsWith('PAY-2026-', BillingPayment::query()->sole()->number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.payment.record', 'tenant_id' => $tenant->id]);
    }

    public function test_billing_modules_are_separate_super_admin_pages(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->get('https://admin.lorapms.test/super-admin/billing/invoices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/Billing/Invoices'));

        $this->get('https://admin.lorapms.test/super-admin/billing/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/Billing/Payments'));

        $this->get('https://admin.lorapms.test/super-admin/billing/provider-events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/Billing/ProviderEvents'));
    }

    public function test_regular_hotel_user_cannot_access_platform_billing(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get('https://admin.lorapms.test/super-admin/billing/invoices')
            ->assertForbidden();
    }

    public function test_active_subscription_generates_each_recurring_cycle_once(): void
    {
        Carbon::setTestNow('2026-08-31 00:10:00');
        $tenant = Tenant::query()->sole();
        $tenant->subscription()->update([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'billing_anchor_day' => 31,
            'next_billing_at' => '2026-08-31 00:00:00',
        ]);

        $firstRun = app(PlatformBillingService::class)->processDueSubscriptions(now());

        $this->assertSame(1, $firstRun['created']->count());
        $this->assertSame(0, $firstRun['failed']);
        $invoice = BillingInvoice::query()->sole();
        $this->assertSame('open', $invoice->status);
        $this->assertSame('2026-08-31', $invoice->period_starts_on->toDateString());
        $this->assertSame('2026-09-29', $invoice->period_ends_on->toDateString());
        $this->assertSame('subscription_schedule', $invoice->metadata['source']);
        $this->assertSame('subscription:'.$tenant->subscription->id.':2026-08-31', $invoice->idempotency_key);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'platform.invoice.recurring',
            'source' => 'system',
        ]);

        $subscription = $tenant->subscription()->firstOrFail();
        $this->assertSame('2026-09-30', $subscription->next_billing_at->toDateString());
        $this->assertSame('2026-09-29', $subscription->current_period_ends_at->toDateString());

        $secondRun = app(PlatformBillingService::class)->processDueSubscriptions(now());

        $this->assertSame(0, $secondRun['created']->count());
        $this->assertSame(1, BillingInvoice::query()->count());
    }

    public function test_non_active_subscription_is_not_billed(): void
    {
        Carbon::setTestNow('2026-08-01 00:10:00');
        $tenant = Tenant::query()->sole();
        $tenant->subscription()->update([
            'status' => 'past_due',
            'next_billing_at' => '2026-08-01 00:00:00',
        ]);

        $result = app(PlatformBillingService::class)->processDueSubscriptions(now());

        $this->assertSame(0, $result['created']->count());
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_recurring_billing_command_is_scheduled_once_daily(): void
    {
        Artisan::call('schedule:list');
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => $event->description === 'platform:billing:run-recurring');

        $this->assertCount(1, $events);
        $this->assertSame('10 0 * * *', $events->first()->getExpression());
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->onOneServer);
    }
}
