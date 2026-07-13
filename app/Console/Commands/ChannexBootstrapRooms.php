<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\ChannelSync;
use App\Services\ChannexClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create a Channex room type + its rate plans for each PMS room type that does
 * not have them yet, so a FRESH property (e.g. the new production account) has
 * the structure that channex:link-rooms needs to map against. THREE rate plans
 * per room type: the BASE plan (canonical PMS price) plus one per OTA channel
 * (Booking.com / Expedia) that carries the program-compensated price so member
 * promotions land back on the PMS price (see ChannelSync::pushRatesForMapping).
 *
 * IDEMPOTENT + re-runnable: it matches existing Channex room types by
 * (case-insensitive) title and skips creating them, and ensures each rate-plan
 * ROLE independently by title — so a re-run after a partial failure only fills
 * the gaps. It does NOT write channel_mappings (that stays channex:link-rooms'
 * job) and does NOT push ARI.
 *
 *   php artisan channex:bootstrap-rooms --dry   # preview, no writes
 *   php artisan channex:bootstrap-rooms         # create what's missing
 */
class ChannexBootstrapRooms extends Command
{
    protected $signature = 'channex:bootstrap-rooms {--dry : Show what would be created without creating}';

    protected $description = 'Create a Channex room type + base and per-channel rate plans for each PMS room type (idempotent)';

    public function handle(ChannexClient $channex): int
    {
        if (! $channex->configured()) {
            $this->error('CHANNEX_API_KEY is not set (.env).');

            return self::FAILURE;
        }

        try {
            $existingRoomTypes = collect($channex->getRoomTypes());
            $ratePlans = collect($channex->getRatePlans());
        } catch (\Throwable $e) {
            // A bad key / lost access surfaces (getList throws) instead of looking
            // like an empty account — never create blindly against the wrong place.
            report($e);
            $this->error('Could not read from Channex — check the API key / connection (see logs).');

            return self::FAILURE;
        }

        // Existing Channex room types keyed by normalized title, and each room
        // type's existing rate-plan titles (lowercased) so every plan ROLE
        // (base / Booking.com / Expedia) is ensured independently.
        $existingByTitle = $existingRoomTypes->keyBy(fn ($rt) => Str::lower(trim($rt['attributes']['title'] ?? '')));
        $planTitlesByRoomType = [];
        foreach ($ratePlans as $rp) {
            $rtId = $rp['relationships']['room_type']['data']['id'] ?? null;
            if ($rtId) {
                $planTitlesByRoomType[$rtId][] = Str::lower(trim($rp['attributes']['title'] ?? ''));
            }
        }
        $channelTitles = [
            Str::lower(ChannelSync::RATE_PLAN_TITLE_BOOKING),
            Str::lower(ChannelSync::RATE_PLAN_TITLE_EXPEDIA),
        ];

        $dry = (bool) $this->option('dry');
        $rtCreated = 0;
        $rpCreated = 0;
        $rtExisting = 0;

        foreach (RoomType::orderBy('id')->get() as $roomType) {
            $title = trim($roomType->name);
            $count = Room::where('room_type_id', $roomType->id)->count();
            $occ = max(1, (int) $roomType->max_occupancy);

            $match = $existingByTitle->get(Str::lower($title));
            $channexRoomTypeId = $match['id'] ?? null;

            if ($channexRoomTypeId) {
                $this->line(sprintf('  = exists      %-30s %s', $title, $channexRoomTypeId));
                $rtExisting++;
            } else {
                $this->line(sprintf('  %s %-30s (rooms=%d, occ=%d)', $dry ? '+ would create' : '+ creating   ', $title, $count, $occ));
                if (! $dry) {
                    $channexRoomTypeId = $channex->createRoomType($title, $count, $occ);
                    if (! $channexRoomTypeId) {
                        $this->error("    FAILED to create room type \"{$title}\" (see channel_sync_logs)");

                        continue;
                    }
                    $rtCreated++;
                }
            }

            // Ensure the three rate-plan roles for this room type, each matched
            // by title so a re-run only fills what's missing. The BASE role also
            // accepts any pre-existing non-channel plan (prod's original
            // "Standard Rate") so legacy setups aren't duplicated.
            $titles = $channexRoomTypeId ? ($planTitlesByRoomType[$channexRoomTypeId] ?? []) : [];
            $hasBase = array_diff($titles, $channelTitles) !== [];
            $roles = [
                [$hasBase, ChannelSync::RATE_PLAN_TITLE_BASE, 'base'],
                [in_array($channelTitles[0], $titles, true), ChannelSync::RATE_PLAN_TITLE_BOOKING, 'booking.com'],
                [in_array($channelTitles[1], $titles, true), ChannelSync::RATE_PLAN_TITLE_EXPEDIA, 'expedia'],
            ];

            foreach ($roles as [$exists, $planTitle, $role]) {
                if ($exists) {
                    continue;
                }
                if ($dry) {
                    $this->line(sprintf('      + would create rate plan [%s] "%s" (occ=%d)', $role, $planTitle, $occ));

                    continue;
                }
                if (! $channexRoomTypeId) {
                    continue;
                }
                $rpId = $channex->createRatePlan($channexRoomTypeId, $occ, $planTitle);
                if ($rpId) {
                    $rpCreated++;
                } else {
                    $this->error("    FAILED to create rate plan [{$role}] for \"{$title}\" (see channel_sync_logs)");
                }
            }
        }

        $this->info(($dry ? '[dry] ' : '')."Room types created: {$rtCreated}, rate plans created: {$rpCreated}, already existed: {$rtExisting}.");
        $this->line('Next: php artisan channex:link-rooms');

        return self::SUCCESS;
    }
}
