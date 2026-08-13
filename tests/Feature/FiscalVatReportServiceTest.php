<?php

namespace Tests\Feature;

use App\Models\FiscalDocument;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\FatureAlConfiguration;
use App\Services\Reporting\FiscalVatReportService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalVatReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_real_fiscal_vat_and_missing_documents(): void
    {
        Setting::set('financial.vat_status', 'registered');
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Fiscal', 'last_name' => 'Guest']);

        $fiscalizedReservation = $this->reservation($room, $guest, $user, 120);
        $missingReservation = $this->reservation($room, $guest, $user, 50);
        FolioItem::create(['reservation_id' => $missingReservation->id, 'description' => 'Transfer', 'amount' => 20, 'type' => 'service', 'charge_date' => '2026-07-10']);
        FolioItem::create(['reservation_id' => $missingReservation->id, 'description' => 'Ulje', 'amount' => 10, 'type' => 'discount', 'charge_date' => '2026-07-10']);
        FiscalDocument::create([
            'reservation_id' => $fiscalizedReservation->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'RES-'.$fiscalizedReservation->id,
            'payment_method' => 'CARD',
            'currency' => 'EUR',
            'total' => 120,
            'vat_rate' => 6,
            'invoice_payload' => ['lines' => [
                ['total' => 100, 'vat' => 6],
                ['total' => 20, 'vat' => 20],
            ]],
            'request_hash' => str_repeat('a', 64),
            'status' => FiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'FISC-1',
            'fiscalized_at' => '2026-07-10 12:00:00',
            'attempted_at' => '2026-07-10 12:00:00',
        ]);

        $pos = PosOrder::create([
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 60,
            'business_date' => '2026-07-11',
            'paid_at' => '2026-07-11 12:00:00',
            'created_by' => $user->id,
        ]);
        PosFiscalDocument::create([
            'pos_order_id' => $pos->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'POS-'.$pos->id,
            'payment_method' => 'BANKNOTE',
            'currency' => 'EUR',
            'total' => 60,
            'vat_rate' => 20,
            'request_hash' => str_repeat('b', 64),
            'status' => PosFiscalDocument::STATUS_FAILED,
            'attempted_at' => '2026-07-11 12:00:00',
            'last_error' => 'Provider unavailable',
        ]);

        $priorReservation = $this->reservation($room, $guest, $user, 60, '2026-06-15');
        FiscalDocument::create([
            'reservation_id' => $priorReservation->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'RES-'.$priorReservation->id,
            'payment_method' => 'CARD',
            'currency' => 'EUR',
            'total' => 60,
            'vat_rate' => 6,
            'request_hash' => str_repeat('c', 64),
            'status' => FiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'FISC-OLD-STAY',
            'fiscalized_at' => '2026-07-12 12:00:00',
            'attempted_at' => '2026-07-12 12:00:00',
        ]);

        $lateFiscalized = $this->reservation($room, $guest, $user, 40);
        FiscalDocument::create([
            'reservation_id' => $lateFiscalized->id,
            'provider' => 'fature_al',
            'environment' => 'sandbox',
            'document_type' => 'cash_invoice',
            'internal_id' => 'RES-'.$lateFiscalized->id,
            'payment_method' => 'CARD',
            'currency' => 'EUR',
            'total' => 40,
            'vat_rate' => 6,
            'request_hash' => str_repeat('d', 64),
            'status' => FiscalDocument::STATUS_FISCALIZED,
            'fiscal_number' => 'FISC-NEXT-MONTH',
            'fiscalized_at' => '2026-08-01 12:00:00',
            'attempted_at' => '2026-08-01 12:00:00',
        ]);

        $report = app(FiscalVatReportService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));

        $this->assertSame(5, $report['summary']['documents']);
        $this->assertSame(3, $report['summary']['fiscalized']);
        $this->assertSame(2, $report['summary']['tax_documents']);
        $this->assertSame(1, $report['summary']['failed']);
        $this->assertSame(1, $report['summary']['missing']);
        $this->assertSame(60.0, $report['summary']['coverage_rate']);
        $this->assertSame(180.0, $report['summary']['gross']);
        $this->assertSame(12.39, $report['summary']['vat']);
        $this->assertSame(167.61, $report['summary']['net']);
        $missing = collect($report['documents'])->firstWhere('source_id', $missingReservation->id);
        $this->assertSame('missing', $missing['status']);
        $this->assertSame(60.0, $missing['gross']);
        $this->assertSame(120.0, $report['summary']['awaiting_gross']);
        $this->assertSame('sandbox', $report['summary']['environment']);
    }

    public function test_environment_is_read_from_tenant_configuration(): void
    {
        Setting::set('financial.vat_status', 'registered');
        $this->mock(FatureAlConfiguration::class, function ($mock) {
            $mock->shouldReceive('get')->with('environment', 'sandbox')->andReturn('production');
        });
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Env', 'last_name' => 'Guest']);

        $productionReservation = $this->reservation($room, $guest, $user, 100);
        $sandboxReservation = $this->reservation($room, $guest, $user, 80);
        foreach ([[$productionReservation, 'production', 'FISC-PROD'], [$sandboxReservation, 'sandbox', 'FISC-SAND']] as [$reservation, $environment, $number]) {
            FiscalDocument::create([
                'reservation_id' => $reservation->id,
                'provider' => 'fature_al',
                'environment' => $environment,
                'document_type' => 'cash_invoice',
                'internal_id' => $environment.'-'.$reservation->id,
                'payment_method' => 'CARD',
                'currency' => 'EUR',
                'total' => $reservation->total_amount,
                'vat_rate' => 6,
                'request_hash' => str_repeat(substr($environment, 0, 1), 64),
                'status' => FiscalDocument::STATUS_FISCALIZED,
                'fiscal_number' => $number,
                'fiscalized_at' => '2026-07-10 12:00:00',
                'attempted_at' => '2026-07-10 12:00:00',
            ]);
        }

        $report = app(FiscalVatReportService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));

        $this->assertSame('production', $report['summary']['environment']);
        $this->assertSame(1, $report['summary']['fiscalized']);
        $this->assertSame(1, $report['summary']['missing']);
        $sandboxRow = collect($report['documents'])->firstWhere('source_id', $sandboxReservation->id);
        $this->assertSame('missing', $sandboxRow['status']);
        $this->assertSame(80.0, $report['summary']['awaiting_gross']);
    }

    private function reservation(Room $room, Guest $guest, User $user, float $total, string $checkOut = '2026-07-10'): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => date('Y-m-d', strtotime($checkOut.' -1 day')),
            'check_out_date' => $checkOut,
            'status' => 'checked_out',
            'total_amount' => $total,
            'adults' => 1,
            'children' => 0,
            'channel' => 'direct',
        ]);
    }
}
