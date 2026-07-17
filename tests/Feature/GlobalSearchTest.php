<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_typed_results_for_the_active_hotel(): void
    {
        [$tenant, $admin] = $this->adminForDefaultHotel();

        $reservationId = app(TenantContext::class)->run($tenant, function () use ($admin) {
            $type = RoomType::create(['name' => 'Deluxe Aurora', 'base_price' => 100, 'max_occupancy' => 2]);
            $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'A-404', 'floor' => 4, 'status' => 'available']);
            $guest = Guest::create(['first_name' => 'Aurora', 'last_name' => 'Test', 'email' => 'aurora@example.test']);

            return Reservation::create([
                'room_id' => $room->id,
                'guest_id' => $guest->id,
                'created_by' => $admin->id,
                'check_in_date' => today()->addDay(),
                'check_out_date' => today()->addDays(3),
                'status' => 'checked_out',
                'total_amount' => 200,
                'adults' => 2,
            ])->id;
        });

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=Aurora&locale=en')
            ->assertOk()
            ->assertJsonPath('query', 'Aurora')
            ->assertJsonFragment(['key' => 'reservations'])
            ->assertJsonFragment(['key' => 'guests'])
            ->assertJsonFragment(['key' => 'rooms'])
            ->assertJsonFragment(['title' => 'Room A-404'])
            ->assertJsonFragment(['href' => "/pms/finance/invoices?source=hotel&record_id={$reservationId}"])
            ->assertJsonFragment(['title' => 'Aurora Test']);

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=Aurora%20Test&locale=en')
            ->assertOk()
            ->assertJsonFragment(['key' => 'reservations'])
            ->assertJsonFragment(['key' => 'guests']);

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=A-404&locale=sq')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Dhoma A-404']);
    }

    public function test_search_never_returns_records_from_another_hotel(): void
    {
        [$tenant, $admin] = $this->adminForDefaultHotel();
        $foreign = Tenant::factory()->create(['name' => 'Foreign Hotel']);
        app(TenantBillingService::class)->provision($foreign, enableAll: true);
        app(TenantRoleService::class)->provision($foreign);

        app(TenantContext::class)->run($foreign, function () {
            Guest::create(['first_name' => 'ForeignSecret', 'last_name' => 'Guest']);
        });

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=ForeignSecret')
            ->assertOk()
            ->assertJsonMissing(['title' => 'ForeignSecret Guest']);

        $this->assertSame($tenant->id, $admin->current_tenant_id);
    }

    public function test_search_only_returns_groups_the_user_may_open(): void
    {
        [$tenant] = $this->adminForDefaultHotel();
        $roomsOnly = app(TenantContext::class)->run($tenant, function () {
            $user = User::factory()->create(['current_tenant_id' => app(TenantContext::class)->id()]);
            $user->givePermissionTo('view_rooms');
            Guest::create(['first_name' => 'PrivateGuest', 'last_name' => 'Hidden']);

            return $user;
        });

        $this->actingAs($roomsOnly)
            ->getJson('http://localhost/pms/global-search?q=PrivateGuest')
            ->assertOk()
            ->assertJsonMissing(['key' => 'guests'])
            ->assertJsonMissing(['title' => 'PrivateGuest Hidden']);
    }

    public function test_super_admin_without_a_hotel_role_only_sees_destinations_they_can_open(): void
    {
        [$tenant] = $this->adminForDefaultHotel();

        $superAdmin = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'is_super_admin' => true,
        ]);

        app(TenantContext::class)->run($tenant, function () {
            Guest::create(['first_name' => 'Restricted', 'last_name' => 'Guest']);
        });

        $this->actingAs($superAdmin)
            ->getJson('http://localhost/pms/global-search?q=Restricted')
            ->assertOk()
            ->assertJsonPath('groups', []);
    }

    public function test_search_requires_at_least_two_non_whitespace_characters(): void
    {
        [, $admin] = $this->adminForDefaultHotel();

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_finance_search_hides_bank_payments_without_bank_account_permission(): void
    {
        [$tenant] = $this->adminForDefaultHotel();

        $staff = app(TenantContext::class)->run($tenant, function () {
            $staff = User::factory()->create(['current_tenant_id' => app(TenantContext::class)->id()]);
            $staff->givePermissionTo('view_finance');
            $cash = FinanceAccount::create(['name' => 'Arka test', 'type' => 'cash', 'currency' => 'EUR', 'is_active' => true]);
            $bank = FinanceAccount::create(['name' => 'Banka test', 'type' => 'bank', 'currency' => 'EUR', 'is_active' => true]);

            FinancePayment::create([
                'direction' => 'in', 'account_id' => $cash->id, 'amount' => 10, 'currency' => 'EUR',
                'method' => 'cash', 'source' => 'manual', 'description' => 'SEARCH-CASH', 'paid_at' => now(),
            ]);
            FinancePayment::create([
                'direction' => 'in', 'account_id' => $bank->id, 'amount' => 20, 'currency' => 'EUR',
                'method' => 'bank', 'source' => 'manual', 'description' => 'SEARCH-BANK', 'paid_at' => now(),
            ]);

            return $staff;
        });

        $this->actingAs($staff)
            ->getJson('http://localhost/pms/global-search?q=SEARCH')
            ->assertOk()
            ->assertJsonFragment(['subtitle' => '10.00 EUR · cash · SEARCH-CASH'])
            ->assertJsonMissing(['subtitle' => '20.00 EUR · bank · SEARCH-BANK']);
    }

    public function test_paid_module_results_are_hidden_when_subscription_is_inactive(): void
    {
        [$tenant, $admin] = $this->adminForDefaultHotel();

        app(TenantContext::class)->run($tenant, function () {
            $account = FinanceAccount::create(['name' => 'Suspended cash', 'type' => 'cash', 'currency' => 'EUR', 'is_active' => true]);
            FinancePayment::create([
                'direction' => 'in', 'account_id' => $account->id, 'amount' => 30, 'currency' => 'EUR',
                'method' => 'cash', 'source' => 'manual', 'description' => 'SUSPENDED-SECRET', 'paid_at' => now(),
            ]);
        });

        $metadata = $tenant->metadata;
        $metadata['billing_access']['status'] = 'suspended';
        $tenant->update(['metadata' => $metadata]);

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=SUSPENDED-SECRET')
            ->assertOk()
            ->assertJsonMissing(['key' => 'finance'])
            ->assertJsonMissing(['subtitle' => '30.00 EUR · cash · SUSPENDED-SECRET']);
    }

    public function test_housekeeper_only_sees_tasks_they_may_open(): void
    {
        [$tenant] = $this->adminForDefaultHotel();

        [$housekeeper, $otherTaskId, $ownTaskId] = app(TenantContext::class)->run($tenant, function () {
            $type = RoomType::create(['name' => 'Housekeeping Search', 'base_price' => 50, 'max_occupancy' => 2]);
            $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'HK-101', 'floor' => 1, 'status' => 'available']);
            $housekeeper = User::factory()->create(['current_tenant_id' => app(TenantContext::class)->id()]);
            $other = User::factory()->create(['current_tenant_id' => app(TenantContext::class)->id()]);
            $housekeeper->givePermissionTo('view_housekeeping');

            $otherTask = CleaningTask::create([
                'room_id' => $room->id,
                'assigned_to' => $other->id,
                'type' => 'deep_clean',
                'status' => 'pending',
                'priority' => 'normal',
                'notes' => 'SEARCH-HOUSEKEEPING',
            ]);
            $ownTask = CleaningTask::create([
                'room_id' => $room->id,
                'assigned_to' => $housekeeper->id,
                'type' => 'stayover_clean',
                'status' => 'pending',
                'priority' => 'normal',
                'notes' => 'SEARCH-HOUSEKEEPING',
            ]);

            return [$housekeeper, $otherTask->id, $ownTask->id];
        });

        $this->actingAs($housekeeper)
            ->getJson('http://localhost/pms/global-search?q=SEARCH-HOUSEKEEPING')
            ->assertOk()
            ->assertJsonFragment(['href' => "/pms/housekeeping/{$ownTaskId}/clean"])
            ->assertJsonMissing(['href' => "/pms/housekeeping/{$otherTaskId}/clean"]);
    }

    public function test_finance_search_finds_pos_invoices_by_fiscal_number(): void
    {
        [$tenant, $admin] = $this->adminForDefaultHotel();

        $orderId = app(TenantContext::class)->run($tenant, function () use ($admin) {
            $order = PosOrder::create([
                'status' => 'completed',
                'payment_method' => 'card',
                'total_amount' => 42,
                'created_by' => $admin->id,
                'paid_at' => now(),
            ]);
            PosFiscalDocument::create([
                'pos_order_id' => $order->id,
                'provider' => 'fature_al',
                'environment' => 'sandbox',
                'document_type' => 'cash_invoice',
                'internal_id' => 'SEARCH-POS-'.$order->id,
                'payment_method' => 'CARD',
                'currency' => 'EUR',
                'total' => 42,
                'vat_rate' => 20,
                'invoice_payload' => [],
                'request_hash' => str_repeat('a', 64),
                'status' => PosFiscalDocument::STATUS_FISCALIZED,
                'fiscal_number' => 'POS-FISCAL-SEARCH-42',
                'fiscalized_at' => now(),
            ]);

            return $order->id;
        });

        $this->actingAs($admin)
            ->getJson('http://localhost/pms/global-search?q=POS-FISCAL-SEARCH&locale=en')
            ->assertOk()
            ->assertJsonFragment(['title' => "POS invoice #{$orderId}"])
            ->assertJsonFragment(['href' => "/pms/finance/invoices?source=pos&record_id={$orderId}"]);
    }

    private function adminForDefaultHotel(): array
    {
        $tenant = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($tenant);

        $admin = app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
            $user->assignRole('admin');

            return $user;
        });

        return [$tenant, $admin];
    }
}
