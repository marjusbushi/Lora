<?php

namespace Tests\Feature;

use App\Models\BeachReservation;
use App\Models\BeachZone;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Setting;
use App\Models\User;
use App\Services\FinanceLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BeachFinanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function reservation(float $total = 500): BeachReservation
    {
        $zone = BeachZone::create(['name' => 'Rreshti '.uniqid(), 'price_per_day' => $total]);
        $unit = $zone->units()->create(['number' => (string) random_int(1000, 9999)]);

        return BeachReservation::create([
            'beach_unit_id' => $unit->id,
            'guest_name' => 'Guest Ledger', 'guest_phone' => '069',
            'start_date' => today()->addDay()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'status' => BeachReservation::STATUS_CONFIRMED,
            'source' => BeachReservation::SOURCE_RECEPTION,
            'total_amount' => $total,
        ]);
    }

    private function ledgerRowFor(BeachReservation $reservation): ?FinancePayment
    {
        return FinancePayment::where('sourceable_type', BeachReservation::class)
            ->where('sourceable_id', $reservation->id)
            ->first();
    }

    public function test_shared_mode_routes_cash_to_general_arka_and_unmark_removes_row(): void
    {
        $reservation = $this->reservation(500);

        $this->actingAs($this->admin)
            ->post(route('beach.reservations.mark-paid', $reservation), ['method' => 'cash'])
            ->assertSessionHasNoErrors();

        $row = $this->ledgerRowFor($reservation);
        $this->assertNotNull($row);
        $this->assertSame('in', $row->direction);
        $this->assertSame('cash', $row->method);
        $account = FinanceAccount::find($row->account_id);
        $this->assertSame('general', $account->scope);
        $this->assertSame('cash', $account->type);

        // Heqja e shënimit fshin rreshtin e ledger-it (observer updated → removeFor).
        $this->actingAs($this->admin)
            ->post(route('beach.reservations.unmark-paid', $reservation))
            ->assertSessionHasNoErrors();
        $this->assertNull($this->ledgerRowFor($reservation));
    }

    public function test_split_cash_auto_creates_arka_plazh(): void
    {
        Setting::set('finance.beach_account_mode', FinanceLedger::POS_MODE_SPLIT_CASH);
        $reservation = $this->reservation(500);

        $this->actingAs($this->admin)
            ->post(route('beach.reservations.mark-paid', $reservation), ['method' => 'cash'])
            ->assertSessionHasNoErrors();

        $account = FinanceAccount::find($this->ledgerRowFor($reservation)->account_id);
        $this->assertSame('beach', $account->scope);
        $this->assertSame('cash', $account->type);
        $this->assertTrue((bool) $account->is_system);
        $this->assertStringContainsString('Plazh', $account->name);

        // Karta në split_cash mbetet te banka e përgjithshme.
        $second = $this->reservation(300);
        $this->actingAs($this->admin)
            ->post(route('beach.reservations.mark-paid', $second), ['method' => 'card'])
            ->assertSessionHasNoErrors();
        $cardAccount = FinanceAccount::find($this->ledgerRowFor($second)->account_id);
        $this->assertSame('general', $cardAccount->scope);
        $this->assertSame('bank', $cardAccount->type);
    }

    public function test_online_payment_routes_to_bank_and_split_bank_to_banka_plazh(): void
    {
        // 'online' (POK) → llogari bankare; me split_bank → 'Banka Plazh'.
        Setting::set('finance.beach_account_mode', FinanceLedger::POS_MODE_SPLIT_BANK);
        $reservation = $this->reservation(700);
        $reservation->update(['paid_at' => now(), 'payment_method' => 'online']);

        $row = $this->ledgerRowFor($reservation);
        $this->assertNotNull($row);
        $account = FinanceAccount::find($row->account_id);
        $this->assertSame('beach', $account->scope);
        $this->assertSame('bank', $account->type);
        $this->assertStringContainsString('Plazh', $account->name);
    }

    public function test_idempotent_and_mode_change_leaves_history(): void
    {
        $reservation = $this->reservation(500);
        $reservation->update(['paid_at' => now(), 'payment_method' => 'cash']);

        $ledger = app(FinanceLedger::class);
        $ledger->recordBeachPayment($reservation->fresh());
        $ledger->recordBeachPayment($reservation->fresh());

        $this->assertSame(1, FinancePayment::where('sourceable_type', BeachReservation::class)
            ->where('sourceable_id', $reservation->id)->count());
        $originalAccount = $this->ledgerRowFor($reservation)->account_id;

        // Ndryshimi i mode PA prekur rezervimin s'e lëviz historinë.
        Setting::set('finance.beach_account_mode', FinanceLedger::POS_MODE_SPLIT_ALL);
        $this->assertSame($originalAccount, $this->ledgerRowFor($reservation)->account_id);
    }

    public function test_backfill_creates_rows_once(): void
    {
        $reservation = $this->reservation(400);
        $reservation->update(['paid_at' => now(), 'payment_method' => 'cash']);

        // Simulo histori pa ledger (para kësaj feature): fshi rreshtin e observer-it.
        app(FinanceLedger::class)->removeFor($reservation);
        $this->assertNull($this->ledgerRowFor($reservation));

        Artisan::call('finance:backfill');
        $this->assertNotNull($this->ledgerRowFor($reservation));

        Artisan::call('finance:backfill');
        $this->assertSame(1, FinancePayment::where('sourceable_type', BeachReservation::class)
            ->where('sourceable_id', $reservation->id)->count());
    }
}
