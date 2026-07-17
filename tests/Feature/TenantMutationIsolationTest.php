<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CleaningTask;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\InventoryItem;
use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceIssue;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Support\TenantStorage;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The WRITE surface of tenant isolation: an admin of hotel A must not be able
 * to UPDATE or DELETE any record of hotel B, a forged session tenant must be
 * ignored, and roles/permissions must never bleed across hotels.
 */
class TenantMutationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $home;

    private Tenant $foreign;

    private User $admin;

    /** @var array<string, Model> */
    private array $foreignRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $context = app(TenantContext::class);

        $this->home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($this->home);

        $this->foreign = Tenant::factory()->create(['name' => 'Hotel Foreign']);
        app(TenantBillingService::class)->provision($this->foreign, enableAll: true);
        app(TenantRoleService::class)->provision($this->foreign);
        Storage::fake('local');

        $context->set($this->home);
        $this->admin = User::factory()->create(['current_tenant_id' => $this->home->id]);
        $this->admin->assignRole('admin');

        $context->set($this->foreign);
        $type = RoomType::create(['name' => 'F-Type', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'F1', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'For', 'last_name' => 'Eign', 'email' => 'foreign@example.test']);
        $staff = User::factory()->create(['name' => 'B-SECRET-STAFF']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $staff->id,
            'check_in_date' => today()->addDays(2)->toDateString(),
            'check_out_date' => today()->addDays(4)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 180,
            'adults' => 2,
        ]);
        $task = CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'pending']);
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $menuItem = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Uje', 'price' => 2, 'is_available' => true]);
        $account = FinanceAccount::create([
            'name' => 'B-SECRET-BANK', 'type' => 'bank', 'currency' => 'EUR', 'is_active' => true,
        ]);
        $payment = FinancePayment::create([
            'direction' => 'in',
            'account_id' => $account->id,
            'amount' => 73,
            'currency' => 'EUR',
            'method' => 'bank',
            'source' => 'manual',
            'description' => 'B-SECRET-FINANCE',
            'paid_at' => now(),
            'created_by' => $staff->id,
        ]);
        $supplier = Supplier::create(['name' => 'B-SECRET-SUPPLIER']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'number' => 'B-SECRET-BILL',
            'category' => 'Të tjera',
            'issue_date' => today(),
            'currency' => 'EUR',
            'total' => 41,
            'status' => 'open',
        ]);
        $posOrder = PosOrder::create([
            'table_number' => 'B-SECRET',
            'status' => 'open',
            'total_amount' => 9,
            'created_by' => $staff->id,
        ]);
        $warehouse = Warehouse::create([
            'name' => 'B-SECRET-WAREHOUSE', 'type' => 'central', 'is_default' => true, 'is_active' => true,
        ]);
        $inventoryItem = InventoryItem::create([
            'name' => 'B-SECRET-STOCK', 'sku' => 'B-SECRET-SKU', 'type' => 'product', 'unit' => 'piece', 'is_active' => true,
        ]);
        $maintenanceIssue = MaintenanceIssue::create([
            'room_id' => $room->id,
            'reported_by' => $staff->id,
            'title' => 'B-SECRET-MAINTENANCE',
            'category' => 'other',
            'priority' => 'medium',
            'status' => 'reported',
        ]);
        $documentPath = TenantStorage::path("guest-documents/{$guest->id}/b-secret.pdf");
        Storage::disk('local')->put($documentPath, 'hotel-b-private-document');
        $guestDocument = GuestDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'original_name' => 'B-SECRET-PASSPORT.pdf',
            'path' => $documentPath,
            'mime' => 'application/pdf',
            'size' => 24,
            'uploaded_by' => $staff->id,
        ]);
        $attachmentPath = TenantStorage::path("maintenance/{$maintenanceIssue->id}/b-secret.pdf");
        Storage::disk('local')->put($attachmentPath, 'hotel-b-private-maintenance-file');
        $maintenanceAttachment = MaintenanceAttachment::create([
            'maintenance_issue_id' => $maintenanceIssue->id,
            'uploaded_by' => $staff->id,
            'disk' => 'local',
            'path' => $attachmentPath,
            'original_name' => 'B-SECRET-MAINTENANCE.pdf',
            'mime_type' => 'application/pdf',
            'size' => 34,
        ]);
        $integration = TenantIntegration::create([
            'provider' => 'channex',
            'enabled' => true,
            'credentials' => ['api_key' => 'B-SECRET-KEY'],
            'configuration' => ['property_id' => 'B-SECRET-PROPERTY'],
        ]);
        $context->clear();

        $this->foreignRecords = compact(
            'type',
            'room',
            'guest',
            'reservation',
            'task',
            'menuItem',
            'account',
            'payment',
            'supplier',
            'bill',
            'posOrder',
            'warehouse',
            'inventoryItem',
            'maintenanceIssue',
            'guestDocument',
            'maintenanceAttachment',
            'integration',
        );
    }

    public function test_hotel_a_cannot_read_hotel_b_data_across_core_modules(): void
    {
        $context = app(TenantContext::class);
        $context->set($this->home);

        foreach ($this->foreignRecords as $name => $record) {
            $this->assertNull(
                $record::query()->find($record->getKey()),
                "Hotel A could read Hotel B's {$name} record.",
            );
        }

        $context->clear();
        $this->withoutVite();

        $pages = [
            [route('rooms.index'), 'F1'],
            [route('guests.index'), 'foreign@example.test'],
            [route('reservations.index'), 'foreign@example.test'],
            [route('finance.index'), 'B-SECRET-FINANCE'],
            [route('pos.index'), 'B-SECRET'],
            [route('inventory.items'), 'B-SECRET-STOCK'],
            [route('maintenance.index'), 'B-SECRET-MAINTENANCE'],
            [route('users.index'), 'B-SECRET-STAFF'],
        ];

        foreach ($pages as [$url, $secret]) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertDontSee($secret, false);
        }

        $this->actingAs($this->admin)
            ->get(route('guests.documents.show', $this->foreignRecords['guestDocument']))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->get(route('maintenance.attachments.show', $this->foreignRecords['maintenanceAttachment']))
            ->assertNotFound();

        $context->set($this->foreign);
        foreach ($this->foreignRecords as $name => $record) {
            $this->assertNotNull(
                $record::query()->find($record->getKey()),
                "Hotel B could not read its own {$name} record.",
            );
        }
    }

    public function test_admin_cannot_update_or_delete_records_of_another_hotel(): void
    {
        $r = $this->foreignRecords;

        $attempts = [
            ['put', route('rooms.update', $r['room']->id)],
            ['delete', route('rooms.destroy', $r['room']->id)],
            ['put', route('guests.update', $r['guest']->id)],
            ['delete', route('guests.destroy', $r['guest']->id)],
            ['put', route('reservations.update', $r['reservation']->id)],
            ['delete', route('reservations.destroy', $r['reservation']->id)],
            ['patch', route('housekeeping.status', $r['task']->id)],
            ['put', route('settings.menu-items.update', $r['menuItem']->id)],
            ['put', route('finance.accounts.toggle', $r['account']->id)],
            ['post', route('finance.bills.pay', $r['bill']->id)],
            ['put', route('finance.suppliers.update', $r['supplier']->id)],
            ['delete', route('finance.suppliers.destroy', $r['supplier']->id)],
            ['post', route('pos.cancel', $r['posOrder']->id)],
            ['put', route('inventory.items.update', $r['inventoryItem']->id)],
            ['put', route('inventory.warehouses.update', $r['warehouse']->id)],
            ['patch', route('maintenance.update', $r['maintenanceIssue']->id)],
            ['delete', route('guests.documents.destroy', $r['guestDocument']->id)],
            ['delete', route('maintenance.attachments.destroy', $r['maintenanceAttachment']->id)],
        ];

        foreach ($attempts as [$method, $url]) {
            $this->actingAs($this->admin)
                ->{$method}($url, [])
                ->assertNotFound();
        }

        // Nothing changed or vanished on the foreign hotel.
        $this->assertSame('confirmed', Reservation::withoutGlobalScopes()->findOrFail($r['reservation']->id)->status);
        $this->assertNotNull(Room::withoutGlobalScopes()->find($r['room']->id));
        $this->assertNotNull(Guest::withoutGlobalScopes()->find($r['guest']->id));
        $this->assertNotNull(MenuItem::withoutGlobalScopes()->find($r['menuItem']->id));
        $this->assertTrue(FinanceAccount::withoutGlobalScopes()->findOrFail($r['account']->id)->is_active);
        $this->assertSame('open', Bill::withoutGlobalScopes()->findOrFail($r['bill']->id)->status);
        $this->assertNull(Supplier::withoutGlobalScopes()->findOrFail($r['supplier']->id)->deleted_at);
        $this->assertSame('open', PosOrder::withoutGlobalScopes()->findOrFail($r['posOrder']->id)->status);
        $this->assertSame('B-SECRET-STOCK', InventoryItem::withoutGlobalScopes()->findOrFail($r['inventoryItem']->id)->name);
        $this->assertSame('reported', MaintenanceIssue::withoutGlobalScopes()->findOrFail($r['maintenanceIssue']->id)->status);
        $this->assertNotNull(GuestDocument::withoutGlobalScopes()->find($r['guestDocument']->id));
        $this->assertNotNull(MaintenanceAttachment::withoutGlobalScopes()->find($r['maintenanceAttachment']->id));
        Storage::disk('local')->assertExists($r['guestDocument']->path);
        Storage::disk('local')->assertExists($r['maintenanceAttachment']->path);
    }

    public function test_forged_session_tenant_id_of_a_non_member_is_ignored(): void
    {
        // The admin belongs ONLY to the home hotel; smuggling the foreign
        // tenant's id into the session must not switch the context.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->foreign->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $this->home->id)
                ->where('tenant.name', $this->home->name));

        // And the foreign hotel's records still 404 with the forged session.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->foreign->id])
            ->get(route('guests.show', $this->foreignRecords['guest']->id))
            ->assertNotFound();
    }

    public function test_roles_and_permissions_do_not_bleed_between_hotels(): void
    {
        $context = app(TenantContext::class);

        // The same person: admin at HOME, plain member (no role) at FOREIGN.
        $context->set($this->foreign);
        $this->admin->tenants()->syncWithoutDetaching([
            $this->foreign->id => ['is_owner' => false, 'is_active' => true],
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $this->foreign->id,
            'domain' => 'foreign-hotel.test',
            'is_primary' => true,
        ]);
        $context->clear();

        // Session pinned to HOME → full admin payload.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->home->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $this->home->id)
                ->where('auth.user.role', 'admin'));

        // FOREIGN's registered host wins over the stale HOME session. The same
        // person has no admin role or permissions in that hotel.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->home->id])
            ->get('https://foreign-hotel.test/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $this->foreign->id)
                ->where('auth.user.role', null)
                ->where('auth.user.permissions', []));
    }

    public function test_unique_validation_is_scoped_per_hotel(): void
    {
        // HOME already has room F1? No — F1 belongs to FOREIGN. The home admin
        // must be able to reuse the SAME room number for their own hotel.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->home->id])
            ->post(route('rooms.store'), [
                'room_number' => 'F1',
                'room_type_id' => tap(app(TenantContext::class))->set($this->home)
                    ->run($this->home, fn () => RoomType::create([
                        'name' => 'H-Type', 'base_price' => 70, 'max_occupancy' => 2, 'amenities' => [],
                    ]))->id,
                'floor' => 1,
                'status' => 'available',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            2,
            Room::withoutGlobalScopes()->where('room_number', 'F1')->count(),
            'The same room number must be allowed once per hotel.',
        );
    }
}
