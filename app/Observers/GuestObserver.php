<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Guest;

class GuestObserver
{
    public function created(Guest $guest): void
    {
        AuditLog::record('guest.created', $guest, ['changes' => $this->changes($guest, true)]);
    }

    public function updated(Guest $guest): void
    {
        $changes = $this->changes($guest);
        if ($changes !== []) {
            AuditLog::record('guest.updated', $guest, ['changes' => $changes]);
        }
    }

    public function deleted(Guest $guest): void
    {
        AuditLog::record('guest.deleted', $guest);
    }

    /** @return array<string, array{from:mixed,to:mixed}> */
    private function changes(Guest $guest, bool $created = false): array
    {
        $fields = [
            'first_name', 'last_name', 'email', 'phone', 'nationality',
            'document_type', 'document_number', 'date_of_birth', 'notes',
            'marketing_consent',
        ];
        $changes = [];

        foreach ($fields as $field) {
            if (! $created && ! $guest->wasChanged($field)) {
                continue;
            }

            $to = $this->value($guest->getAttribute($field));
            if ($created && $to === null) {
                continue;
            }

            $changes[$field] = [
                'from' => $created ? null : $this->value($guest->getOriginal($field)),
                'to' => $to,
            ];
        }

        return $changes;
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
