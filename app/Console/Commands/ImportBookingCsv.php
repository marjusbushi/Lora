<?php

namespace App\Console\Commands;

use App\Models\ChannelSyncLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-off / repeatable import of a Booking.com reservations export (CSV) into the
 * PMS. Maps each "Unit type" to a real room (by room number OR room-type name),
 * creates/links a guest, and writes a reservation tagged channel=booking.com with
 * the Book Number as channel_ref so re-running never duplicates. --dry-run shows
 * exactly what it would do without writing anything.
 */
class ImportBookingCsv extends Command
{
    protected $signature = 'booking:import {file : path to the CSV} {--dry-run : show the plan without writing}';

    protected $description = 'Import Booking.com reservations (CSV) into the PMS';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $rows = $this->readCsv($path);
        if (! $rows) {
            $this->error('No rows parsed.');

            return self::FAILURE;
        }

        // withTrashed: resolve the system user even if soft-deleted (see submitBooking) so a
        // CSV import is attributed to it, not silently to the first admin.
        $creator = User::systemForCurrentTenant()->id;

        // room_number -> Room, and a per-type pool of rooms for free-room assignment.
        $roomsByNumber = Room::with('roomType')->get()->keyBy(fn ($r) => trim((string) $r->room_number));
        $roomsByType = Room::with('roomType')->get()->groupBy('room_type_id');

        $created = 0;
        $updated = 0;
        $cancelled = 0;
        $flagged = [];

        foreach ($rows as $i => $row) {
            $book = trim($row['Book Number'] ?? '');
            if ($book === '') {
                continue;
            }

            $status = str_starts_with(strtolower(trim($row['Status'] ?? '')), 'cancel') ? 'cancelled' : 'confirmed';
            $checkIn = $this->date($row['Check-in'] ?? null);
            $checkOut = $this->date($row['Check-out'] ?? null);
            $price = $this->money($row['Price'] ?? '');
            $commission = $this->money($row['Commission Amount'] ?? '');
            $adults = (int) ($row['Adults'] ?? 0) ?: 1;
            $children = (int) ($row['Children'] ?? 0);
            $remarks = trim($row['Remarks'] ?? '');
            $country = trim($row['Booker country'] ?? '');
            $phone = trim($row['Phone number'] ?? '');

            // Resolve the room(s) this booking maps to.
            $units = array_filter(array_map('trim', explode(',', (string) ($row['Unit type'] ?? ''))));
            $rooms = [];
            $unmapped = [];
            foreach ($units as $unit) {
                $room = $this->resolveRoom($unit, $roomsByNumber, $roomsByType, $rooms, $checkIn, $checkOut, $status);
                if ($room) {
                    $rooms[] = $room;
                } else {
                    $unmapped[] = $unit;
                }
            }
            if (! $rooms) {
                $flagged[] = "#{$book} {$row['Guest Name(s)']} — s'u mapua dot: '".($row['Unit type'] ?? '')."'";

                continue;
            }

            $guest = $this->guest($row['Guest Name(s)'] ?? 'Mysafir', $phone, $country, $dry);
            $perRoomTotal = count($rooms) > 1 ? round($price / count($rooms), 2) : $price;

            foreach ($rooms as $idx => $room) {
                $attrs = [
                    'guest_id' => $guest?->id,
                    'created_by' => $creator,
                    'check_in_date' => $checkIn,
                    'check_out_date' => $checkOut,
                    'status' => $status,
                    'total_amount' => $idx === 0 ? $perRoomTotal : $perRoomTotal,
                    'commission_amount' => $idx === 0 ? $commission : 0,
                    'adults' => $adults,
                    'children' => $children,
                    'notes' => trim("Booking.com #{$book}".($remarks ? " — {$remarks}" : '')),
                    'channel' => 'booking.com',
                ];
                $line = "  #{$book}  ".($row['Guest Name(s)'] ?? '')."  {$checkIn}→{$checkOut}  dhoma {$room->room_number} ({$room->roomType?->name})  {$status}  €{$attrs['total_amount']}";
                if ($unmapped) {
                    $line .= '  [pjesë e pamapuar: '.implode(', ', $unmapped).']';
                }

                if ($dry) {
                    $this->line($line);
                    $created++;

                    continue;
                }

                $existing = Reservation::where('channel', 'booking.com')->where('channel_ref', $book)->where('room_id', $room->id)->first();
                if (! $existing) {
                    $attrs['created_via'] = Reservation::CREATED_VIA_IMPORT;
                }
                $res = Reservation::updateOrCreate(
                    ['channel' => 'booking.com', 'channel_ref' => $book, 'room_id' => $room->id],
                    $attrs
                );
                $existing ? $updated++ : $created++;
                if ($status === 'cancelled') {
                    $cancelled++;
                }
                ChannelSyncLog::record([
                    'direction' => 'pull', 'action' => 'import.booking', 'reservation_id' => $res->id,
                    'room_type_id' => $room->room_type_id, 'status' => 'ok',
                    'request' => ['book' => $book, 'unit' => $row['Unit type'] ?? null],
                ]);
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '')."Reservations: {$created} të reja, {$updated} të përditësuara ({$cancelled} të anuluara).");
        if ($flagged) {
            $this->warn('Rreshta që s\'u mapuan dot ('.count($flagged).'):');
            foreach ($flagged as $f) {
                $this->line('  - '.$f);
            }
        }

        return self::SUCCESS;
    }

    private function resolveRoom(string $unit, $roomsByNumber, $roomsByType, array $taken, ?string $in, ?string $out, string $status): ?Room
    {
        // 1) exact room number (e.g. "201", "202")
        if (isset($roomsByNumber[$unit])) {
            return $roomsByNumber[$unit];
        }
        // 2) room-type name (exact, case-insensitive), with a known Booking.com alias
        $name = strtolower($unit);
        if (str_contains($name, 'balcony') && str_contains($name, 'sea view')) {
            $name = 'deluxe double room with balcony'; // Booking lists it with a longer marketing name
        }
        $type = RoomType::all()->first(fn ($t) => strtolower(trim($t->name)) === $name);
        if (! $type) {
            return null;
        }

        $pool = $roomsByType->get($type->id) ?? collect();
        $takenIds = collect($taken)->pluck('id')->all();
        // prefer a room with no overlapping non-cancelled reservation for these dates
        foreach ($pool as $room) {
            if (in_array($room->id, $takenIds, true)) {
                continue;
            }
            if ($status === 'cancelled' || $this->isFree($room->id, $in, $out)) {
                return $room;
            }
        }

        return $pool->first(fn ($r) => ! in_array($r->id, $takenIds, true)) ?? $pool->first();
    }

    private function isFree(int $roomId, ?string $in, ?string $out): bool
    {
        if (! $in || ! $out) {
            return true;
        }

        return ! Reservation::where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled'])
            ->where('check_in_date', '<', $out)
            ->where('check_out_date', '>', $in)
            ->exists();
    }

    private function guest(string $fullName, string $phone, string $country, bool $dry): ?Guest
    {
        $parts = preg_split('/\s+/', trim($fullName));
        $first = $parts[0] ?? 'Mysafir';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        if ($dry) {
            return Guest::where('first_name', $first)->where('last_name', $last)->first()
                ?? new Guest(['first_name' => $first, 'last_name' => $last]);
        }

        return Guest::firstOrCreate(
            ['first_name' => $first, 'last_name' => $last],
            ['phone' => $phone ?: null, 'notes' => $country ? "Booking.com · {$country}" : null]
        );
    }

    private function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function money(?string $v): float
    {
        return (float) preg_replace('/[^0-9.]/', '', (string) $v);
    }

    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            return [];
        }
        $header = fgetcsv($fh);
        if (! $header) {
            fclose($fh);

            return [];
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if (count(array_filter($data, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($data, 0, count($header)), count($header), null));
        }
        fclose($fh);

        return $rows;
    }
}
