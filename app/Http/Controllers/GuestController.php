<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestStoreRequest;
use App\Http\Requests\GuestUpdateRequest;
use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\Reservation;
use App\Services\AuditTimeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class GuestController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // The UI stores ISO alpha-2 codes, while older imported profiles may
            // still contain alpha-3 values. Accept both formats during filtering.
            'nationality' => ['nullable', 'string', 'between:2,3', 'regex:/^[A-Za-z]{2,3}$/'],
            'segment' => ['nullable', 'in:all,in_house,arriving_7_days,returning,incomplete,attention'],
            'sort' => ['nullable', 'in:name,last_stay,next_stay,stays'],
        ]);

        $user = $request->user();
        $canUpdate = (bool) $user?->can('update_guests');
        $today = today();
        $todayString = $today->toDateString();
        $windowEnd = $today->copy()->addDays(6);
        $windowEndString = $windowEnd->toDateString();

        $search = trim((string) ($validated['search'] ?? ''));
        $nationality = strtoupper(trim((string) ($validated['nationality'] ?? '')));
        $segment = (string) ($validated['segment'] ?? 'all');
        $sort = (string) ($validated['sort'] ?? 'last_stay');
        $nationalityAliases = $nationality !== '' ? $this->nationalityAliases($nationality) : [];

        $returningGuestIds = $this->returningGuestIds();
        $duplicateGuestIds = $this->duplicateGuestIds();

        $columns = [
            'id', 'first_name', 'last_name', 'email', 'phone',
            'nationality', 'document_type', 'created_at',
        ];

        // The list never exposes sensitive edit-only fields to a view-only role.
        // When editing is allowed, send a complete payload so opening the modal and
        // saving it cannot silently blank document, date-of-birth, or notes fields.
        if ($canUpdate) {
            array_push($columns, 'document_number', 'date_of_birth', 'notes');
        }

        $query = Guest::query()
            ->select($columns)
            ->withCount([
                'reservations as reservation_history_count' => fn (Builder $reservation) => $reservation->withTrashed(),
            ])
            ->withExists('documents');

        if ($search !== '') {
            $terms = collect(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY))->take(5);

            $query->where(function (Builder $searchQuery) use ($terms) {
                foreach ($terms as $term) {
                    $searchQuery->where(function (Builder $fields) use ($term) {
                        $fields->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%")
                            ->orWhere('document_number', 'like', "%{$term}%");
                    });
                }
            });
        }

        if ($nationality !== '') {
            $query->whereIn('nationality', $nationalityAliases);
        }

        match ($segment) {
            'in_house' => $query->whereHas('reservations', fn (Builder $reservation) => $reservation
                ->where('status', 'checked_in')),
            'arriving_7_days' => $query->whereHas('reservations', fn (Builder $reservation) => $reservation
                ->where('status', 'confirmed')
                ->whereNull('no_show_at')
                ->whereBetween('check_in_date', [$todayString, $windowEndString])),
            'returning' => $query->whereIn('id', $returningGuestIds->all()),
            'incomplete' => $this->whereProfileIncomplete($query),
            'attention' => $this->whereNeedsAttention($query, $duplicateGuestIds),
            default => null,
        };

        match ($sort) {
            'name' => null,
            'next_stay' => $query
                ->withMin([
                    'reservations as next_stay_sort' => fn (Builder $reservation) => $reservation
                        ->where('status', 'confirmed')
                        ->whereNull('no_show_at')
                        ->where('check_in_date', '>=', $todayString),
                ], 'check_in_date')
                ->orderByRaw('CASE WHEN next_stay_sort IS NULL THEN 1 ELSE 0 END')
                ->orderBy('next_stay_sort'),
            'stays' => $query
                ->selectSub(
                    Reservation::query()
                        ->selectRaw('COUNT(DISTINCT '.$this->visitKeySql().')')
                        ->whereColumn('reservations.guest_id', 'guests.id')
                        ->where('reservations.status', 'checked_out'),
                    'completed_stays_sort'
                )
                ->orderByDesc('completed_stays_sort'),
            default => $query
                ->withMax([
                    'reservations as last_stay_sort' => fn (Builder $reservation) => $reservation
                        ->where('status', 'checked_out'),
                ], 'check_out_date')
                ->orderByDesc('last_stay_sort'),
        };

        $paginator = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $guestIds = collect($paginator->items())->pluck('id');
        $reservationsByGuest = $guestIds->isEmpty()
            ? collect()
            : Reservation::query()
                ->select([
                    'id', 'guest_id', 'room_id', 'booking_group_id', 'check_in_date',
                    'check_out_date', 'status', 'channel',
                ])
                ->with(['room:id,room_number,room_type_id', 'room.roomType:id,name'])
                ->whereIn('guest_id', $guestIds)
                ->where(function (Builder $reservation) use ($todayString) {
                    $reservation->whereIn('status', ['checked_in', 'checked_out'])
                        ->orWhere(function (Builder $future) use ($todayString) {
                            $future->where('status', 'confirmed')
                                ->whereNull('no_show_at')
                                ->where('check_in_date', '>=', $todayString);
                        });
                })
                ->get()
                ->groupBy('guest_id');

        $duplicateLookup = $duplicateGuestIds->flip();

        $paginator->through(function (Guest $guest) use (
            $canUpdate,
            $duplicateLookup,
            $reservationsByGuest,
            $today,
            $windowEnd
        ) {
            $reservations = $reservationsByGuest->get($guest->id, collect());
            $completedRows = $reservations->where('status', 'checked_out');
            $completedVisits = $completedRows->groupBy(
                fn (Reservation $reservation) => $reservation->booking_group_id
                    ? 'group:'.$reservation->booking_group_id
                    : 'reservation:'.$reservation->id
            );

            $completedStays = $completedVisits->count();
            $totalNights = (int) $completedVisits->sum(
                fn (Collection $visit) => (int) $visit->max(
                    fn (Reservation $reservation) => $reservation->nights
                )
            );

            $currentStay = $reservations
                ->where('status', 'checked_in')
                ->sortBy('check_in_date')
                ->first();
            $nextStay = $reservations
                ->where('status', 'confirmed')
                ->sortBy('check_in_date')
                ->first();
            $lastStay = $completedRows
                ->sortByDesc('check_out_date')
                ->first();

            $state = match (true) {
                $currentStay !== null => 'in_house',
                $nextStay?->check_in_date?->isSameDay($today) === true => 'arriving_today',
                $nextStay !== null && $nextStay->check_in_date->lte($windowEnd) => 'arriving_soon',
                $nextStay !== null => 'upcoming',
                $lastStay !== null => 'past',
                default => 'new',
            };

            $missingFields = collect([
                'email' => $guest->email,
                'phone' => $guest->phone,
                'nationality' => $guest->nationality,
            ])->filter(fn ($value) => blank($value))->keys()->values();
            $profileCompleteness = (int) round(
                (($missingFields->count() === 0 ? 3 : 3 - $missingFields->count()) / 3) * 100
            );
            $isDuplicate = $duplicateLookup->has($guest->id);

            return [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'email' => $guest->email,
                'phone' => $guest->phone,
                'nationality' => $guest->nationality,
                'nationality_label' => $this->nationalityLabel($guest->nationality),
                'created_at' => $guest->created_at?->toDateString(),
                'state' => $state,
                'current_stay' => $this->staySummary($currentStay),
                'next_stay' => $this->staySummary($nextStay),
                'last_stay' => $this->staySummary($lastStay),
                'completed_stays' => $completedStays,
                'total_nights' => $totalNights,
                'profile_completeness' => $profileCompleteness,
                'missing_fields' => $missingFields,
                'is_duplicate' => $isDuplicate,
                'needs_attention' => $isDuplicate || $missingFields->isNotEmpty(),
                'has_reservations' => (int) $guest->reservation_history_count > 0,
                'has_documents' => (bool) $guest->documents_exists,
                'can_delete' => (int) $guest->reservation_history_count === 0 && ! $guest->documents_exists,
                'edit_data' => $canUpdate ? [
                    'first_name' => $guest->first_name,
                    'last_name' => $guest->last_name,
                    'email' => $guest->email,
                    'phone' => $guest->phone,
                    'document_type' => $guest->document_type,
                    'document_number' => $guest->document_number,
                    'nationality' => filled($guest->nationality)
                        ? $this->nationalityRegion($guest->nationality)
                        : null,
                    'date_of_birth' => $guest->date_of_birth?->toDateString(),
                    'notes' => $guest->notes,
                ] : null,
            ];
        });

        $stats = [
            'total' => Guest::count(),
            'in_house' => Guest::whereHas('reservations', fn (Builder $reservation) => $reservation
                ->where('status', 'checked_in'))->count(),
            'arriving_7_days' => Guest::whereHas('reservations', fn (Builder $reservation) => $reservation
                ->where('status', 'confirmed')
                ->whereNull('no_show_at')
                ->whereBetween('check_in_date', [$todayString, $windowEndString]))->count(),
            'arriving_returning' => Guest::whereIn('id', $returningGuestIds->all())
                ->whereHas('reservations', fn (Builder $reservation) => $reservation
                    ->where('status', 'confirmed')
                    ->whereNull('no_show_at')
                    ->whereBetween('check_in_date', [$todayString, $windowEndString]))
                ->count(),
            'returning' => $returningGuestIds->count(),
            'incomplete' => $this->profileIncompleteQuery()->count(),
            'duplicate_profiles' => $duplicateGuestIds->count(),
            'attention' => $this->needsAttentionQuery($duplicateGuestIds)->count(),
        ];

        return Inertia::render('Guests/Index', [
            'guests' => $paginator,
            'filters' => [
                'search' => $search,
                'nationality' => $nationality,
                'segment' => $segment,
                'sort' => $sort,
            ],
            'stats' => $stats,
            'totalGuests' => $stats['total'],
            'permissions' => [
                'create' => (bool) $user?->can('create_guests'),
                'update' => $canUpdate,
                'delete' => (bool) $user?->can('delete_guests'),
            ],
        ]);
    }

    public function show(Guest $guest, AuditTimeline $timeline): Response
    {
        $guest->load([
            'reservations' => fn ($q) => $q
                ->with(['room:id,room_number,room_type_id', 'room.roomType:id,name'])
                ->orderByDesc('check_in_date'),
        ]);

        $stays = $guest->reservations;
        $stayIds = $stays->pluck('id');

        $historyLogs = AuditLog::query()
            ->with('causer:id,name')
            ->where(function ($query) use ($guest, $stayIds) {
                $query->where(function ($guestLogs) use ($guest) {
                    $guestLogs->where('subject_type', Guest::class)
                        ->where('subject_id', $guest->id);
                });

                if ($stayIds->isNotEmpty()) {
                    $query->orWhere(function ($reservationLogs) use ($stayIds) {
                        $reservationLogs->where('subject_type', Reservation::class)
                            ->whereIn('subject_id', $stayIds);
                    });
                }
            })
            ->latest('id')
            ->limit(50)
            ->get();

        $staySubjects = $stays->mapWithKeys(fn (Reservation $reservation) => [
            $reservation->id => [
                'label' => "Rezervimi #{$reservation->id} · Dhoma ".($reservation->room?->room_number ?? '—'),
                'url' => route('reservations.show', $reservation->id),
            ],
        ]);
        $historySubjects = $historyLogs->mapWithKeys(function (AuditLog $log) use ($staySubjects) {
            if ($log->subject_type !== Reservation::class) {
                return [];
            }

            return [$log->id => $staySubjects->get($log->subject_id)];
        })->filter()->all();

        // Possible duplicates — same email / phone / document_number (excluding self).
        $duplicates = collect();
        if ($guest->email || $guest->phone || $guest->document_number) {
            $duplicates = Guest::where('id', '!=', $guest->id)
                ->where(function ($q) use ($guest) {
                    if ($guest->email) {
                        $q->orWhere('email', $guest->email);
                    }
                    if ($guest->phone) {
                        $q->orWhere('phone', $guest->phone);
                    }
                    if ($guest->document_number) {
                        $q->orWhere('document_number', $guest->document_number);
                    }
                })
                ->select('id', 'first_name', 'last_name', 'email', 'phone')
                ->limit(10)
                ->get();
        }

        return Inertia::render('Guests/Show', [
            'guest' => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'email' => $guest->email,
                'phone' => $guest->phone,
                'document_type' => $guest->document_type,
                'document_number' => $guest->document_number,
                'nationality' => $guest->nationality,
                'date_of_birth' => $guest->date_of_birth?->toDateString(),
                'notes' => $guest->notes,
            ],
            'stays' => $stays->map(fn ($r) => [
                'id' => $r->id,
                'room' => $r->room?->room_number,
                'room_type' => $r->room?->roomType?->name,
                'check_in_date' => $r->check_in_date?->toDateString(),
                'check_out_date' => $r->check_out_date?->toDateString(),
                'nights' => $r->nights,
                'status' => $r->status,
                'total_amount' => (float) $r->total_amount,
            ]),
            'stats' => [
                'total_stays' => $stays->count(),
                'total_nights' => (int) $stays->sum(fn ($r) => $r->nights),
                'lifetime_spend' => (float) $stays->whereNotIn('status', ['cancelled'])->sum('total_amount'),
            ],
            'documents' => $guest->documents()->with('uploader:id,name')->get()->map(fn ($d) => [
                'id' => $d->id,
                'type' => $d->type,
                'original_name' => $d->original_name,
                'mime' => $d->mime,
                'size' => (int) $d->size,
                'uploaded_by' => $d->uploader?->name,
                'created_at' => $d->created_at?->toDateString(),
                'url' => route('guests.documents.show', $d->id),
            ]),
            'duplicates' => $duplicates,
            'history' => $timeline->entries($historyLogs, $historySubjects),
        ]);
    }

    public function store(GuestStoreRequest $request): RedirectResponse
    {
        Guest::create($request->validated());

        return back()->with('success', 'Mysafiri u regjistrua me sukses.');
    }

    public function update(GuestUpdateRequest $request, Guest $guest): RedirectResponse
    {
        $guest->update($request->validated());

        return back()->with('success', 'Te dhenat u perditesuan.');
    }

    public function destroy(Guest $guest): RedirectResponse
    {
        if (! auth()->user()->can('delete_guests')) {
            abort(403);
        }

        if ($guest->reservations()->withTrashed()->exists() || $guest->documents()->exists()) {
            return back()->withErrors([
                'guest' => 'Ky profil ka historik rezervimesh ose dokumente private dhe nuk mund të fshihet. Mund ta korrigjosh ose ta bashkosh me një dublikatë.',
            ]);
        }

        $guest->delete();

        return back()->with('success', 'Mysafiri u fshi.');
    }

    // ----- Identity documents (passport / ID / …) — stored on the PRIVATE disk -----

    public function storeDocument(Request $request, Guest $guest): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:passport,id_card,drivers_license,visa,other'],
            'file' => ['required', 'file', 'max:25600', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx'],
        ]);

        $file = $request->file('file');
        // 'local' disk root = storage/app/private → NOT web-accessible (sensitive ID docs).
        $path = $file->store(TenantStorage::path("guest-documents/{$guest->id}"), 'local');

        $guest->documents()->create([
            'type' => $data['type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);

        return back()->with('success', 'Dokumenti u ngarkua.');
    }

    /** Stream a private document inline (auth + view_guests gated by the route). */
    public function downloadDocument(GuestDocument $document)
    {
        abort_unless($document->guest()->exists(), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return response()->file(
            Storage::disk('local')->path($document->path),
            ['Content-Disposition' => 'inline; filename="'.addslashes($document->original_name).'"']
        );
    }

    public function destroyDocument(GuestDocument $document): RedirectResponse
    {
        Storage::disk('local')->delete($document->path);
        $document->delete();

        return back()->with('success', 'Dokumenti u fshi.');
    }

    private function visitKeySql(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "CASE WHEN reservations.booking_group_id IS NULL THEN 'reservation:' || reservations.id ELSE 'group:' || reservations.booking_group_id END";
        }

        return "CASE WHEN reservations.booking_group_id IS NULL THEN CONCAT('reservation:', reservations.id) ELSE CONCAT('group:', reservations.booking_group_id) END";
    }

    /** @return Collection<int, int> */
    private function returningGuestIds(): Collection
    {
        return Reservation::query()
            ->select('guest_id')
            ->whereNotNull('guest_id')
            ->where('status', 'checked_out')
            ->whereHas('guest')
            ->groupBy('guest_id')
            ->havingRaw('COUNT(DISTINCT '.$this->visitKeySql().') >= 2')
            ->pluck('guest_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** @return list<string> */
    private function nationalityAliases(string $selected): array
    {
        $targetRegion = $this->nationalityRegion($selected);

        return Guest::query()
            ->whereNotNull('nationality')
            ->where('nationality', '!=', '')
            ->distinct()
            ->pluck('nationality')
            ->push($selected)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn (string $code) => $this->nationalityRegion($code) === $targetRegion)
            ->unique()
            ->values()
            ->all();
    }

    private function nationalityRegion(string $code): string
    {
        $code = strtoupper(trim($code));

        if (class_exists(\Locale::class)) {
            try {
                return strtoupper(\Locale::getRegion('und_'.$code) ?: $code);
            } catch (\Throwable) {
                // Fall back to exact matching if ICU cannot parse a legacy value.
            }
        }

        return $code;
    }

    private function nationalityLabel(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $code = strtoupper(trim((string) $code));

        if (class_exists(\Locale::class)) {
            try {
                $label = \Locale::getDisplayRegion('und_'.$code, 'sq');

                if (filled($label)) {
                    return $label;
                }
            } catch (\Throwable) {
                // The raw value is still more useful than an empty label.
            }
        }

        return $code;
    }

    /** @return Collection<int, int> */
    private function duplicateGuestIds(): Collection
    {
        $duplicateEmails = Guest::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');
        $duplicatePhones = Guest::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');
        $duplicateDocuments = Guest::query()
            ->whereNotNull('document_number')
            ->where('document_number', '!=', '')
            ->select('document_number')
            ->groupBy('document_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('document_number');

        if ($duplicateEmails->isEmpty() && $duplicatePhones->isEmpty() && $duplicateDocuments->isEmpty()) {
            return collect();
        }

        return Guest::query()
            ->where(function (Builder $query) use ($duplicateEmails, $duplicatePhones, $duplicateDocuments) {
                if ($duplicateEmails->isNotEmpty()) {
                    $query->orWhereIn('email', $duplicateEmails->all());
                }
                if ($duplicatePhones->isNotEmpty()) {
                    $query->orWhereIn('phone', $duplicatePhones->all());
                }
                if ($duplicateDocuments->isNotEmpty()) {
                    $query->orWhereIn('document_number', $duplicateDocuments->all());
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function whereProfileIncomplete(Builder $query): Builder
    {
        return $query->where(function (Builder $missing) {
            $this->addMissingProfileConditions($missing);
        });
    }

    private function whereNeedsAttention(Builder $query, Collection $duplicateGuestIds): Builder
    {
        return $query->where(function (Builder $attention) use ($duplicateGuestIds) {
            $attention->where(function (Builder $missing) {
                $this->addMissingProfileConditions($missing);
            });

            if ($duplicateGuestIds->isNotEmpty()) {
                $attention->orWhereIn('id', $duplicateGuestIds->all());
            }
        });
    }

    private function profileIncompleteQuery(): Builder
    {
        return $this->whereProfileIncomplete(Guest::query());
    }

    private function needsAttentionQuery(Collection $duplicateGuestIds): Builder
    {
        return $this->whereNeedsAttention(Guest::query(), $duplicateGuestIds);
    }

    private function addMissingProfileConditions(Builder $query): void
    {
        $query->whereNull('email')
            ->orWhere('email', '')
            ->orWhereNull('phone')
            ->orWhere('phone', '')
            ->orWhereNull('nationality')
            ->orWhere('nationality', '');
    }

    private function staySummary(?Reservation $reservation): ?array
    {
        if (! $reservation) {
            return null;
        }

        return [
            'reservation_id' => $reservation->id,
            'check_in_date' => $reservation->check_in_date?->toDateString(),
            'check_out_date' => $reservation->check_out_date?->toDateString(),
            'room_number' => $reservation->room?->room_number,
            'room_type' => $reservation->room?->roomType?->name,
            'channel' => Reservation::normalizeChannel($reservation->channel),
        ];
    }
}
