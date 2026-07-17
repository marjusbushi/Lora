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
use App\Services\ReservationFiscalizationService;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
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
            Setting::set('financial.vat_status', 'registered');
            Setting::set('financial.accommodation_vat_rate', 6, 'number');
            Setting::set('financial.product_vat_rate', 20, 'number');
            Setting::set('financial.tax_rate', 20, 'number');
            Setting::set('financial.fx_all_per_eur', 93.7837, 'number');
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
                && ! array_key_exists('client', $payload)
                && (float) $payload['exchange_rate'] === 93.7837
                && $payload['invoice_discount_type'] === 'amount'
                && (float) $payload['invoice_discount_value'] === 5.0
                && count($payload['lines']) === 2
                && str_starts_with($payload['lines'][0]['product_name'], 'Dhomë ')
                && (int) $payload['lines'][0]['vat'] === 6
                && (int) $payload['lines'][1]['vat'] === 20
                && (float) $payload['lines'][0]['total'] === 100.0
                && (float) $payload['lines'][1]['total'] === 10.0;
        });

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, $document->status);
        $this->assertSame('LORA-T'.$this->tenant->id.'-RES-'.$reservation->id, $document->internal_id);
        $this->assertSame('BANKNOTE', $document->payment_method);
        $this->assertEqualsWithDelta(93.7837, (float) $document->exchange_rate, 0.000001);
        $this->assertSame('TEST-2026-1', $document->fiscal_number);
        $this->assertSame('IIC-TEST', $document->iic);
        $this->assertEqualsWithDelta(105.0, (float) $document->total, 0.001);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fiscalization.completed',
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
        ]);

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('reservations.show', $reservation))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fiscalization.document.fiscal_number', 'TEST-2026-1')
                ->where('fiscalization.document.iic', 'IIC-TEST')
                ->where('fiscalization.document.invoice_payload.lines.0.product_code', 'ROOM-STAY')
                ->where('fiscalization.document.verify_url', 'https://demo.fature.al/verify/test')
                ->where('invoicePrint.currency', 'EUR'));
    }

    public function test_identified_guest_is_not_sent_as_a_fature_al_client_for_cash_payment(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $reservation->guest()->update([
            'document_type' => 'passport',
            'document_number' => 'BA1234567',
            'nationality' => 'ALB',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => ! array_key_exists('client', $request->data()));
    }

    public function test_identified_guest_is_not_sent_as_a_fature_al_client_for_card_payment(): void
    {
        $reservation = $this->checkedOutStay('card');
        $reservation->guest()->update([
            'document_type' => 'passport',
            'document_number' => 'w2534542',
            'nationality' => 'AL',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/invoice/cash'
            && ! array_key_exists('client', $request->data()));
    }

    public function test_non_vat_hotel_sends_zero_vat_for_accommodation_and_products(): void
    {
        Setting::set('financial.vat_status', 'not_registered');
        Setting::set('financial.tax_rate', 0, 'number');
        $reservation = $this->checkedOutStay('cash', 110);
        FolioItem::create([
            'reservation_id' => $reservation->id,
            'description' => 'Minibar ujë',
            'amount' => 10,
            'type' => 'minibar',
            'charge_date' => today(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/invoice/cash'
            && (int) $request->data()['lines'][0]['vat'] === 0
            && (int) $request->data()['lines'][1]['vat'] === 0);
        $this->assertEqualsWithDelta(0, (float) FiscalDocument::query()->sole()->vat_rate, 0.001);
    }

    public function test_provider_vat_status_mismatch_blocks_fiscalization(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $integration = TenantIntegration::query()->where('provider', 'fature_al')->firstOrFail();
        $configuration = $integration->configuration;
        $configuration['account'] = ['issuer_in_vat' => false];
        $integration->update(['configuration' => $configuration]);

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_unsupported_guest_document_is_not_guessed_as_a_fiscal_identity(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $reservation->guest()->update([
            'document_type' => 'drivers_license',
            'document_number' => 'DL-12345',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => ! array_key_exists('client', $request->data()));
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
            'https://demo.fature.al/api/v1/invoice/details/*' => Http::response([], 404),
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
            if ($request->url() === 'https://demo.fature.al/api/v1/invoice/cash') {
                $internalIds[] = $request->data()['internalId'];
            }

            return true;
        });

        $this->assertCount(2, $internalIds);
        $this->assertSame($internalIds[0], $internalIds[1]);
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, FiscalDocument::query()->sole()->status);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'fiscalization.failed')->count());
    }

    public function test_retry_reconciles_an_existing_remote_invoice_before_creating_again(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $internalId = 'LORA-T'.$this->tenant->id.'-RES-'.$reservation->id;

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response(['message' => 'upstream timeout'], 500),
            'https://demo.fature.al/api/v1/invoice/details/*' => Http::response([
                'status' => true,
                'data' => [
                    'invoice' => [
                        'id' => 9001,
                        'number' => 'TEST-2026-1',
                        'iic' => 'IIC-TEST',
                        'issue_date' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url()
            === 'https://demo.fature.al/api/v1/invoice/details/'.rawurlencode($internalId));
        $this->assertSame(1, Http::recorded(fn (Request $request) => $request->url()
            === 'https://demo.fature.al/api/v1/invoice/cash')->count());

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, $document->status);
        $this->assertSame('TEST-2026-1', $document->fiscal_number);
        $this->assertSame('IIC-TEST', $document->iic);
    }

    public function test_failed_legacy_payload_is_reconciled_before_removing_retail_client(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $payload = app(TenantContext::class)->run(
            $this->tenant,
            fn () => app(ReservationFiscalizationService::class)->payload($reservation),
        );
        $oldPayload = $payload;
        unset($oldPayload['exchange_rate']);
        $oldPayload['client'] = [
            'name' => 'Test Guest',
            'id' => ['type' => 'PASS', 'id' => 'BA1234567'],
            'country' => 'ALB',
        ];
        $oldHash = hash('sha256', json_encode($oldPayload, JSON_THROW_ON_ERROR));

        app(TenantContext::class)->run($this->tenant, function () use ($reservation, $oldPayload, $oldHash) {
            FiscalDocument::query()->create([
                'reservation_id' => $reservation->id,
                'provider' => 'fature_al',
                'environment' => 'sandbox',
                'document_type' => 'cash_invoice',
                'internal_id' => $oldPayload['internalId'],
                'payment_method' => $oldPayload['payment_method'],
                'currency' => $oldPayload['currency'],
                'total' => 100,
                'vat_rate' => 20,
                'request_hash' => $oldHash,
                'status' => FiscalDocument::STATUS_FAILED,
                'attempted_at' => now()->subMinutes(10),
                'last_error' => 'HTTP 500',
            ]);
        });

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/details/*' => Http::response([], 404),
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/invoice/cash'
            && (float) $request->data()['exchange_rate'] === 93.7837
            && ! array_key_exists('client', $request->data()));

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, $document->status);
        $this->assertNotSame($oldHash, $document->request_hash);
        $this->assertEqualsWithDelta(93.7837, (float) $document->exchange_rate, 0.000001);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fiscalization.retry_payload_updated',
            'subject_type' => Reservation::class,
            'subject_id' => $reservation->id,
        ]);
    }

    public function test_stale_processing_legacy_payload_is_reconciled_before_removing_retail_client(): void
    {
        $reservation = $this->checkedOutStay('card');
        $payload = app(TenantContext::class)->run(
            $this->tenant,
            fn () => app(ReservationFiscalizationService::class)->payload($reservation),
        );
        $oldPayload = $payload + [
            'client' => [
                'name' => 'Test Guest',
                'id' => ['type' => 'PASS', 'id' => 'BA1234567'],
                'country' => 'ALB',
            ],
        ];
        $oldHash = hash('sha256', json_encode($oldPayload, JSON_THROW_ON_ERROR));

        app(TenantContext::class)->run($this->tenant, function () use ($reservation, $oldPayload, $oldHash) {
            FiscalDocument::query()->create([
                'reservation_id' => $reservation->id,
                'provider' => 'fature_al',
                'environment' => 'sandbox',
                'document_type' => 'cash_invoice',
                'internal_id' => $oldPayload['internalId'],
                'payment_method' => $oldPayload['payment_method'],
                'currency' => $oldPayload['currency'],
                'total' => 100,
                'vat_rate' => 20,
                'invoice_payload' => $oldPayload,
                'request_hash' => $oldHash,
                'status' => FiscalDocument::STATUS_PROCESSING,
                'attempted_at' => now()->subMinutes(10),
            ]);
        });

        Http::preventStrayRequests();
        Http::fake([
            'https://demo.fature.al/api/v1/invoice/details/*' => Http::response([], 404),
            'https://demo.fature.al/api/v1/invoice/cash' => Http::response($this->successResponse()),
        ]);

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.fature.al/api/v1/invoice/cash'
            && ! array_key_exists('client', $request->data()));

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_FISCALIZED, $document->status);
        $this->assertNotSame($oldHash, $document->request_hash);
    }

    public function test_foreign_currency_requires_an_all_exchange_rate(): void
    {
        $reservation = $this->checkedOutStay('cash');
        Setting::set('financial.fx_all_per_eur', 0, 'number');

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors('fiscalization');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
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

    public function test_future_checked_out_stay_is_rejected_before_contacting_provider(): void
    {
        $reservation = $this->checkedOutStay('cash');
        $reservation->forceFill([
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ])->save();
        $this->assertTrue($reservation->fresh()->check_out_date->isAfter(today()));

        $this->actingAs($this->admin)
            ->get(route('reservations.show', $reservation))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fiscalization.checkout_in_future', true)
                ->where('fiscalization.can_issue', false));

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->post(route('reservations.fiscalize', $reservation))
            ->assertSessionHasErrors([
                'fiscalization' => 'Data e check-out është në të ardhmen. Fatura fiskale mund të lëshohet pasi të përfundojë qëndrimi.',
            ]);

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
