<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\GuestMerge;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestMergeService
{
    public const FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'nationality',
        'date_of_birth',
        'document_type',
        'document_number',
        'notes',
    ];

    public function arePotentialDuplicates(Guest $first, Guest $second): bool
    {
        if ($first->id === $second->id) {
            return false;
        }

        foreach (['email', 'phone', 'document_number'] as $field) {
            if (filled($first->{$field}) && $first->{$field} === $second->{$field}) {
                return true;
            }
        }

        return false;
    }

    /** @return array{primary_id:int,secondary_id:int} */
    public function merge(
        Guest $first,
        Guest $second,
        int $primaryId,
        array $fieldSources,
        int $userId,
        string $suggestionSource,
    ): array {
        return DB::transaction(function () use ($first, $second, $primaryId, $fieldSources, $userId, $suggestionSource) {
            $guests = Guest::query()
                ->whereIn('id', [$first->id, $second->id])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_unless($guests->count() === 2, 404);
            $lockedFirst = $guests[$first->id];
            $lockedSecond = $guests[$second->id];
            if (! $this->arePotentialDuplicates($lockedFirst, $lockedSecond)) {
                throw ValidationException::withMessages(['merge' => 'Këto profile nuk përputhen më si dublikatë. Rifresko faqen.']);
            }

            $primary = $guests[$primaryId] ?? null;
            $secondary = $primaryId === $lockedFirst->id ? $lockedSecond : $lockedFirst;
            abort_unless($primary, 422);

            $secondarySnapshot = $secondary->only(array_merge(self::FIELDS, ['id', 'tags', 'preferences', 'marketing_consent', 'created_at']));
            $movedCounts = [
                'reservations' => $secondary->reservations()->withTrashed()->count(),
                'documents' => $secondary->documents()->count(),
                'invoices' => $secondary->invoices()->count(),
                'reviews' => $secondary->reviews()->count(),
            ];

            $updates = [];
            foreach (self::FIELDS as $field) {
                $sourceId = (int) ($fieldSources[$field] ?? $primary->id);
                $updates[$field] = $guests[$sourceId]?->{$field};
            }

            if (($updates['document_number'] ?? null) === $secondary->document_number && filled($secondary->document_number)) {
                $secondary->document_number = null;
                $secondary->saveQuietly();
            }

            $updates['tags'] = collect(array_merge((array) $primary->tags, (array) $secondary->tags))->filter()->unique()->values()->all();
            $updates['preferences'] = array_replace((array) $secondary->preferences, (array) $primary->preferences);
            $primary->update($updates);

            Reservation::withTrashed()->where('guest_id', $secondary->id)->update(['guest_id' => $primary->id]);
            GuestDocument::where('guest_id', $secondary->id)->update(['guest_id' => $primary->id]);
            Invoice::where('guest_id', $secondary->id)->update(['guest_id' => $primary->id]);
            Review::where('guest_id', $secondary->id)->update(['guest_id' => $primary->id]);

            $secondary->merged_into_guest_id = $primary->id;
            $secondary->merged_at = now();
            $secondary->merged_by = $userId;
            $secondary->saveQuietly();
            $secondary->delete();

            GuestMerge::create([
                'primary_guest_id' => $primary->id,
                'secondary_guest_id' => $secondary->id,
                'merged_by' => $userId,
                'field_sources' => $fieldSources,
                'secondary_snapshot' => $secondarySnapshot,
                'moved_counts' => $movedCounts,
                'suggestion_source' => $suggestionSource,
            ]);

            AuditLog::record('guest.merged', $primary, [
                'merged_guest' => "{$secondary->full_name} (#{$secondary->id})",
                'reservations_moved' => $movedCounts['reservations'],
                'documents_moved' => $movedCounts['documents'],
                'invoices_moved' => $movedCounts['invoices'],
                'reviews_moved' => $movedCounts['reviews'],
            ]);

            return ['primary_id' => $primary->id, 'secondary_id' => $secondary->id];
        });
    }
}
