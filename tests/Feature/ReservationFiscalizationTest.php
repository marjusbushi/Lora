<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FiscalDocument;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReservationFiscalizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('admin');

        app(TenantContext::class)->run($this->tenant, function () {
            TenantIntegration::query()->create([
                'provider' => 'fature_al',
                'enabled' => true,
                'credentials' => ['api_token' => 'sandbox-fiscal-token'],
                'configuration' => [
                    'environment' => 'sandbox',
                    'last_test_status' => 'success',
                    'last_tested_at' => now()->toIso8601String(),
                ],
            ]);
            Setting::set('financial.tax_rate', 20, 'number');
        });
    }

    public function test_checked_out_cash_reservation_is_fiscalized_with_idempotent_internal_id(): void
    {
        $reservation = $this->checkedOutStay('cash', 105);
        FolioItem::create([
            'reservation_id' => $reservation->id,
            'description' => 'Minibar ujë',
            'amount' => 10,
            'type' => 'minibar',
            'charge_date' => today(),
        ]);
        FolioItem::create([
            'reservation_id' => $reservation->id,
            'description' => 'Zbritje besnikërie',
            'amount' => 5,
            'type' => 'discount',
            'charge_date' => today(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        Http::assertSent(function (Request $request) use ($reservation) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://demo.fature.al/api/v1/invoice/cash'
                && $request->hasHeader('Authorization', 'Bearer sandbox-fiscal-token')
                && $payload['internalId'] === 'LORA-T'.$this->tenant->id.'-RES-'.$reservation->id
                && $payload['payment_method'] === 'BANKNOTE'
                && $payload['invoice_discount_type'] === 'amount'
                && (float) $payload['invoice_discount_value'] === 5.0
                && count($payload['lines']) === 2
                && (float) $payload['lines'][0]['total'] === 100.0
                && (float) $payload['lines'][1]['total'] === 10.0;
        });

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, $document->status);
        $this->assertSame('LORA-T'.$this->tenant->id.'-RES-'.$reservation->id, $document->internal_id);
        $this->assertSame('BANKNOTE', $document->payment_method);
        $this->assertSame('TEST-2026-1', $document->fiscal_number);
        $this->assertSame('IIC-TEST', $document->iic);
        $this->assertEqualsWithDelta(105.0, (float) $document->total, 0.001);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fiscalization.completed',
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
        ]);
    }

    public function test_repeated_click_does_not_send_a_second_invoice(): void
    {
        $reservation = $this->checkedOutStay('card');

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)->post(route('reservations.fiscalize', $reservation))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reservations.fiscalize', $reservation))->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame('CARD', FiscalDocument::query()->sole()->payment_method);
    }

    public function test_failed_attempt_reuses_the_same_internal_id_on_retry(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $internalIds = [];

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::sequence()
                ->push(['message' => 'temporary'], 500)
                ->push($this->successResponse(), 200),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');
        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSent(function (Request $request) use (&$internalIds) {
            $internalIds[] = $request->data()['internalId'];

            return true;
        });

        $this->assertCount(2, $internalIds);
        $this->assertSame($internalIds[0], $internalIds[1]);
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, FiscalDocument::query()->sole()->status);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'fiscalization.failed')->count());
    }

    public function test_production_configuration_is_blocked_by_this_sandbox_phase(): void
    {
        $reservation = $this->checkedOutStay('cash');
        TenantIntegration::query()->where('provider', 'fature_al')->firstOrFail()->update([
            'configuration' => ['environment' => 'production'],
        ]);

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_connection_must_be_verified_before_an_invoice_is_sent(): void
    {
        $reservation = $this->checkedOutStay('cash');
        TenantIntegration::query()->where('provider', 'fature_al')->firstOrFail()->update([
            'configuration' => [
                'environment' => 'sandbox',
                'last_test_status' => 'failed',
            ],
        ]);

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_mixed_payment_reservation_is_rejected_instead_of_guessing(): void
    {
        $reservation = $this->checkedOutStay('cash', 50);
        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 50,
            'method' => 'card',
            'created_by' => $this->admin->id,
        ]);

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_payment_total_must_match_the_invoice_total(): void
    {
        $reservation = $this->checkedOutStay('cash', 90);

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_a_hotel_cannot_fiscalize_another_tenants_reservation(): void
    {
        $otherTenant = Tenant::factory()->create(['name' => 'Other Hotel']);
        $otherReservation = app(TenantContext::class)->run($otherTenant, function () use ($otherTenant) {
            $otherUser = User::factory()->create(['current_tenant_id' => $otherTenant->id]);
            $roomType = RoomType::create([
                'name' => 'Other Standard',
                'base_price' => 80,
                'max_occupancy' => 2,
                'amenities' => [],
            ]);
            $room = Room::create([
                'room_type_id' => $roomType->id,
                'room_number' => '201',
                'floor' => 2,
                'status' => 'cleaning',
            ]);
            $guest = Guest::create(['first_name' => 'Other', 'last_name' => 'Guest']);

            return Reservation::create([
                'room_id' => $room->id,
                'guest_id' => $guest->id,
                'created_by' => $otherUser->id,
                'check_in_date' => now()->subDays(2)->toDateString(),
                'check_out_date' => now()->subDay()->toDateString(),
                'status' => 'checked_out',
                'total_amount' => 80,
                'adults' => 1,
                'channel' => 'direct',
            ]);
        });

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $otherReservation->id))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    private function checkedOutStay(string $method, float $paid = 100): Reservation
    {
        $roomType = RoomType::create([
            'name' => 'Standard',
            'base_price' => 100,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'floor' => 1,
            'status' => 'cleaning',
        ]);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => now()->subDays(2)->toDateString(),
            'check_out_date' => now()->subDay()->toDateString(),
            'status' => 'checked_out',
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => $paid,
            'method' => $method,
            'created_by' => $this->admin->id,
        ]);

        return $reservation;
    }

    /** @return array<string, mixed> */
    private function successResponse(): array
    {
        return [
            'status' => true,
            'data' => [
                'invoice' => [
                    'id' => 9001,
                    'number' => 'TEST-2026-1',
                    'iic' => 'IIC-TEST',
                    'fic' => 'FIC-TEST',
                    'tcrCode' => 'TCR-TEST',
                    'businessCode' => 'BUSINESS-TEST',
                    'operatorCode' => 'OPERATOR-TEST',
                    'fiscalizedAt' => now()->toIso8601String(),
                    'verifyURL' => 'https://demo.fature.al/verify/test',
                    'pdf' => 'https://demo.fature.al/invoice/test.pdf',
                ],
            ],
        ];
    }
}
