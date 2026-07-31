<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ChannexClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding helper: pull the room structure a hotel already has on Channex
 * (typically imported there from Booking.com via Channex's own "Import
 * property" feature) and create the matching RoomTypes + physical Rooms in
 * the PMS — so a new hotel starts from its real inventory, not a blank page.
 *
 * Deliberately NOT imported: prices. The PMS is the master of pricing —
 * OTA prices carry commissions/markups and would poison the base rates.
 * The owner sets base + seasonal prices in the PMS afterwards.
 *
 * Idempotent: room types match by name (case-insensitive); physical rooms
 * are only topped up to the Channex count_of_rooms, never duplicated.
 * Placeholder room numbers are sequential from 101 — the owner renames them
 * to the real door numbers in the UI.
 *
 * After this, run channex:link-rooms to stamp the channel mappings.
 */
class ChannexImportPropertyStructure extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'channex:import-property-structure {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual} {--dry-run : Vetëm trego çfarë do krijohej}';

    protected $description = 'Krijon tipet e dhomave + dhomat në PMS nga struktura e property-së në Channex (Booking import)';

    public function handle(ChannexClient $channex): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! $channex->configured()) {
            $this->error('Channex nuk është i konfiguruar për këtë hotel (integrimi në panel).');

            return self::FAILURE;
        }

        try {
            $channexTypes = collect($channex->getRoomTypes());
        } catch (\Throwable $e) {
            report($e);
            $this->error('S\'u lexua dot struktura nga Channex: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($channexTypes->isEmpty()) {
            $this->warn('Property në Channex s\'ka asnjë room type. Përdor "Import property from Booking.com" në Channex së pari.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $createdTypes = 0;
        $createdRooms = 0;

        foreach ($channexTypes as $channexType) {
            $attr = $channexType['attributes'] ?? [];
            $title = trim((string) ($attr['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $count = max(1, (int) ($attr['count_of_rooms'] ?? 1));
            $occupancy = max(1, (int) ($attr['occ_adults'] ?? ($attr['default_occupancy'] ?? 2)));

            $type = RoomType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($title)])->first();

            if ($dryRun) {
                $this->line(sprintf('  %-32s %s · %d dhoma · %d persona',
                    $title, $type ? 'EKZISTON' : '+ i ri', $count, $occupancy));
                continue;
            }

            DB::transaction(function () use (&$type, &$createdTypes, &$createdRooms, $title, $count, $occupancy) {
                if (! $type) {
                    // base_price 0 on purpose: prices are the owner's decision,
                    // set in the PMS afterwards (PMS is the pricing master).
                    $type = RoomType::create([
                        'name' => $title,
                        'base_price' => 0,
                        'max_occupancy' => $occupancy,
                        'amenities' => [],
                    ]);
                    $createdTypes++;
                }

                $existing = Room::query()->where('room_type_id', $type->id)->count();
                for ($i = $existing; $i < $count; $i++) {
                    $number = $this->nextFreeRoomNumber();
                    Room::create([
                        'room_type_id' => $type->id,
                        'room_number' => $number,
                        'floor' => intdiv((int) $number, 100),
                        'status' => 'available',
                    ]);
                    $createdRooms++;
                }
            });

            $this->line(sprintf('  %-32s %d dhoma · %d persona', $title, $count, $occupancy));
        }

        if ($dryRun) {
            $this->info('Dry-run — asgjë s\'u krijua.');

            return self::SUCCESS;
        }

        $this->info("U krijuan: {$createdTypes} tipe dhomash, {$createdRooms} dhoma (numra placeholder — pronari i ndërron me numrat realë).");
        $this->line('Hapat pas: (1) pronari vendos çmimet; (2) php artisan channex:link-rooms --tenant=... për mappings.');

        return self::SUCCESS;
    }

    /** The next room number not yet taken, walking up from 101. */
    private function nextFreeRoomNumber(): string
    {
        $taken = Room::query()->pluck('room_number')->flip();
        for ($n = 101; ; $n++) {
            if (! isset($taken[(string) $n]) && ! isset($taken[$n])) {
                return (string) $n;
            }
        }
    }
}
