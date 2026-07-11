<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\AuditTimeline;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditTimeline $timeline): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:all,reservation,guest,payment,folio,housekeeping,pos,user,pricing,channex'],
            'source' => ['nullable', 'in:all,staff,channex,website,import,system'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $category = (string) ($filters['category'] ?? 'all');
        $source = (string) ($filters['source'] ?? 'all');

        $query = AuditLog::query()->with('causer:id,name')->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhereHas('causer', fn ($user) => $user->where('name', 'like', "%{$search}%"));

                if (ctype_digit($search)) {
                    $q->orWhere('subject_id', (int) $search);
                }
            });
        }
        if ($category !== 'all') {
            $query->where('action', 'like', $category.'.%');
        }
        if ($source !== 'all') {
            $query->where('source', $source);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $logs = $query->paginate(50)->withQueryString();
        $subjects = $this->subjects(collect($logs->items()));
        $logs->through(fn (AuditLog $log) => $timeline->entry($log, $subjects[$log->id] ?? null));

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'filters' => array_merge([
                'search' => '', 'category' => 'all', 'source' => 'all',
                'date_from' => null, 'date_to' => null,
            ], $filters),
        ]);
    }

    /** @return array<int, array{label:string,url:?string}> */
    private function subjects($logs): array
    {
        $reservationIds = $logs->where('subject_type', Reservation::class)->pluck('subject_id')->filter()->unique();
        $guestIds = $logs->where('subject_type', Guest::class)->pluck('subject_id')->filter()->unique();

        $reservations = Reservation::withTrashed()->with(['guest:id,first_name,last_name', 'room:id,room_number'])
            ->whereKey($reservationIds)->get()->keyBy('id');
        $guests = Guest::withTrashed()->whereKey($guestIds)->get()->keyBy('id');

        return $logs->mapWithKeys(function (AuditLog $log) use ($reservations, $guests) {
            if ($log->subject_type === Reservation::class && $reservation = $reservations->get($log->subject_id)) {
                $label = "Rezervimi #{$reservation->id}";
                if ($reservation->guest) {
                    $label .= ' · '.$reservation->guest->full_name;
                }
                if ($reservation->room) {
                    $label .= ' · Dhoma '.$reservation->room->room_number;
                }

                return [$log->id => [
                    'label' => $label,
                    'url' => $reservation->trashed() ? null : route('reservations.show', $reservation->id),
                ]];
            }

            if ($log->subject_type === Guest::class && $guest = $guests->get($log->subject_id)) {
                return [$log->id => [
                    'label' => 'Mysafiri · '.$guest->full_name,
                    'url' => $guest->trashed() ? null : route('guests.show', $guest->id),
                ]];
            }

            $type = $log->subject_type ? class_basename($log->subject_type) : 'Sistemi';

            return [$log->id => [
                'label' => $log->subject_id ? "{$type} #{$log->subject_id}" : $type,
                'url' => null,
            ]];
        })->all();
    }
}
