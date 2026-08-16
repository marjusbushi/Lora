<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Models\ChannelSyncLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-off / repeatable import of a reservations export (CSV) into the PMS.
 * Two formats, auto-detected from the header:
 *  - Booking.com extranet export ("Book Number", "Unit type", ...) — every
 *    row is channel=booking.com;
 *  - Beds24 bookings export ("Ref", "FirstNight", "ApiSource", ...) — one
 *    file carries ALL channels; each row's channel derives from Referer and
 *    the OTA reservation number from ApiRef (falling back to the Beds24 Ref).
 * Maps each unit to a real room (by room number OR room-type name), creates/
 * links a guest, and upserts on (channel, channel_ref, room) so re-running
 * never duplicates. --dry-run shows the plan without writing anything.
 */
class ImportBookingCsv extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'booking:import {file : path to the CSV} {--dry-run : show the plan without writing} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Import OTA reservations (Booking.com or Beds24 CSV) into the PMS';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

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

        // Beds24 exports are translated into the Booking-format row shape so
        // the whole pipeline below (unit expansion, room resolution, guest
        // linking, idempotent upsert) stays a single code path.
        $beds24 = array_key_exists('ApiSource', $rows[0]) && array_key_exists('FirstNight', $rows[0]);
        if ($beds24) {
            $rows = array_map(fn (array $row) => $this->translateBeds24Row($row), $rows);
        }
        $this->info('Formati i detektuar: '.($beds24 ? 'Beds24 (multi-kanal)' : 'Booking.com extranet'));

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

            $checkIn = $this->date($row['Check-in'] ?? null);
            $checkOut = $this->date($row['Check-out'] ?? null);
            // History imports: a stay whose check-out already passed is a
            // COMPLETED stay, not an open confirmation — otherwise old years
            // flood the arrivals lists and status-based reports.
            $status = match (true) {
                str_starts_with(strtolower(trim($row['Status'] ?? '')), 'cancel') => 'cancelled',
                $checkOut !== null && $checkOut < now()->toDateString() => 'checked_out',
                default => 'confirmed',
            };
            $price = $this->money($row['Price'] ?? '');
            $commission = $this->money($row['Commission Amount'] ?? '');
            $adults = (int) ($row['Adults'] ?? 0) ?: 1;
            $children = (int) ($row['Children'] ?? 0);
            $remarks = trim($row['Remarks'] ?? '');
            $country = trim($row['Booker country'] ?? '');
            $phone = trim($row['Phone number'] ?? '');

            // Resolve the room(s) this booking maps to. Booking.com's export
            // lists each unit NAME once and carries the count in a separate
            // "Rooms" column — a booking of 2× the same unit arrives as one
            // name with Rooms=2, so the name list must be expanded to match.
            $units = array_values(array_filter(array_map('trim', explode(',', (string) ($row['Unit type'] ?? '')))));
            $roomsWanted = (int) ($row['Rooms'] ?? $row['Units'] ?? 0);
            if ($roomsWanted > count($units) && count(array_unique($units)) === 1) {
                $units = array_fill(0, $roomsWanted, $units[0]);
            } elseif ($roomsWanted > count($units)) {
                // Mixed unit names with a higher count are ambiguous (which unit
                // repeats?) — import what is listed but flag it for the operator.
                $flagged[] = "#{$book} {$row['Guest Name(s)']} — CSV kërkon {$roomsWanted} dhoma por 'Unit type' rendit vetëm ".count($units).": '".($row['Unit type'] ?? '')."'";
            }
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

            $guest = $this->guest($row['Guest Name(s)'] ?? 'Mysafir', $phone, $country, $dry, trim($row['_email'] ?? ''));
            $perRoomTotal = count($rooms) > 1 ? round($price / count($rooms), 2) : $price;
            $channel = $row['_channel'] ?? 'booking.com';
            $channelLabel = ['booking.com' => 'Booking.com', 'expedia' => 'Expedia', 'airbnb' => 'Airbnb', 'direct' => 'Beds24'][$channel] ?? $channel;

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
                    'notes' => trim("{$channelLabel} #{$book}".($remarks ? " — {$remarks}" : '')),
                    'channel' => $channel,
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

                $existing = Reservation::where('channel', $channel)->where('channel_ref', $book)->where('room_id', $room->id)->first();
                if (! $existing) {
                    $attrs['created_via'] = Reservation::CREATED_VIA_IMPORT;
                }
                $res = Reservation::updateOrCreate(
                    ['channel' => $channel, 'channel_ref' => $book, 'room_id' => $room->id],
                    $attrs
                );
                $existing ? $updated++ : $created++;
                if ($status === 'cancelled') {
                    $cancelled++;
                }
                ChannelSyncLog::record([
                    'direction' => 'pull', 'action' => 'import.booking', 'reservation_id' => $res->id,
                    'room_type_id' => $room->room_type_id, 'status' => 'ok',
                    'request' => ['book' => $book, 'unit' => $row['Unit type'] ?? null, 'channel' => $channel],
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

    /**
     * Reshape one Beds24 export row into the Booking-format columns the main
     * loop reads, plus underscore-prefixed extras (channel, email) that only
     * exist in the richer Beds24 export.
     */
    private function translateBeds24Row(array $row): array
    {
        // Beds24 keeps a 0.00 Price on some rows and the real money in
        // "Charges" — prefer Price, fall back to Charges.
        $price = $this->money($row['Price'] ?? '');
        if ($price <= 0) {
            $price = $this->money($row['Charges'] ?? '');
        }

        $name = trim(trim((string) ($row['First Name'] ?? '')).' '.trim((string) ($row['Name'] ?? '')));
        $remarks = implode(' · ', array_filter([trim((string) ($row['Comments'] ?? '')), trim((string) ($row['Notes'] ?? ''))]));

        return [
            // The OTA's own number links future Channex updates; manual and
            // direct rows have no ApiRef and keep the Beds24 Ref instead.
            'Book Number' => trim((string) ($row['ApiRef'] ?? '')) !== '' ? trim((string) $row['ApiRef']) : trim((string) ($row['Ref'] ?? '')),
            'Guest Name(s)' => $name !== '' ? $name : 'Mysafir',
            'Status' => trim((string) ($row['Status'] ?? '')),
            'Check-in' => trim((string) ($row['FirstNight'] ?? '')),
            'Check-out' => trim((string) ($row['CheckOut'] ?? '')),
            'Price' => (string) $price,
            'Commission Amount' => trim((string) ($row['Commission'] ?? '')),
            'Adults' => trim((string) ($row['Adult'] ?? '')),
            'Children' => trim((string) ($row['Child'] ?? '')),
            'Rooms' => trim((string) ($row['Quantity'] ?? '')),
            'Unit type' => trim((string) ($row['Room'] ?? '')),
            'Remarks' => $remarks,
            'Booker country' => trim((string) ($row['Country'] ?? '')),
            'Phone number' => trim((string) ($row['Phone'] ?? '')) !== '' ? trim((string) $row['Phone']) : trim((string) ($row['Mobile'] ?? '')),
            '_channel' => $this->beds24Channel((string) ($row['Referer'] ?? '')),
            '_email' => trim((string) ($row['Email'] ?? '')),
        ];
    }

    /** Beds24's Referer is the OTA name — or the staff email/anything for manual entries. */
    private function beds24Channel(string $referer): string
    {
        $referer = strtolower(trim($referer));

        return match (true) {
            str_contains($referer, 'booking') => 'booking.com',
            str_contains($referer, 'expedia') || str_contains($referer, 'ebookers') => 'expedia',
            str_contains($referer, 'airbnb') => 'airbnb',
            default => 'direct',
        };
    }

    private function resolveRoom(string $unit, $roomsByNumber, $roomsByType, array $taken, ?string $in, ?string $out, string $status): ?Room
    {
        // 1) exact room number (e.g. "201", "202")
        if (isset($roomsByNumber[$unit])) {
            return $roomsByNumber[$unit];
        }
        // 2) room-type name (exact, case-insensitive), with a known Booking.com alias.
        // Booking.com's "Deluxe Double Room with Balcony and Sea View" is the
        // hotel's "Deluxe With Sea View" type — confirmed by the hotel's own
        // Channex channel mapping (Booking.com room_type_code 150548607) and
        // the public listing; NOT the "Deluxe Double Room With Balcony" type
        // it was previously aliased to (misfiled ~27 imported bookings).
        $name = strtolower($unit);
        if (str_contains($name, 'balcony') && str_contains($name, 'sea view')) {
            $name = 'deluxe with sea view';
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

    private function guest(string $fullName, string $phone, string $country, bool $dry, string $email = ''): ?Guest
    {
        $parts = preg_split('/\s+/', trim($fullName));
        $first = $parts[0] ?? 'Mysafir';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        if ($dry) {
            return Guest::where('first_name', $first)->where('last_name', $last)->first()
                ?? new Guest(['first_name' => $first, 'last_name' => $last]);
        }

        // Identity stays name-based (Booking's per-reservation alias emails
        // would split one repeat guest into many); the email is only STORED.
        return Guest::firstOrCreate(
            ['first_name' => $first, 'last_name' => $last],
            ['phone' => $phone ?: null, 'email' => $email ?: null, 'notes' => $country ? "Import · {$country}" : null]
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
