<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AuditTimeline
{
    private const ACTION_LABELS = [
        'reservation.created' => 'Rezervimi u krijua',
        'reservation.updated' => 'Rezervimi u ndryshua',
        'reservation.check_in' => 'U krye check-in',
        'reservation.check_out' => 'U krye check-out',
        'reservation.move_room' => 'U ndryshua dhoma',
        'reservation.cancel' => 'Rezervimi u anulua',
        'reservation.deleted' => 'Rezervimi u fshi',
        'guest.created' => 'Profili i mysafirit u krijua',
        'guest.updated' => 'Profili i mysafirit u ndryshua',
        'guest.deleted' => 'Profili i mysafirit u fshi',
        'guest.merged' => 'Profilet e dubluara u bashkuan',
        'payment.record' => 'U regjistrua pagesë',
        'payment.pok_capture' => 'U regjistrua pagesë online',
        'folio.add_line' => 'U shtua një zë në folio',
        'housekeeping.stayover_requested' => 'U kërkua pastrim ditor',
        'pos.complete' => 'U mbyll porosia POS',
        'pos.cancel' => 'U anulua porosia POS',
        'user.create' => 'U krijua përdoruesi',
        'user.update' => 'U ndryshua përdoruesi',
        'user.delete' => 'U fshi përdoruesi',
    ];

    private const FIELD_LABELS = [
        'room_id' => 'Dhoma',
        'guest_id' => 'Mysafiri',
        'check_in_date' => 'Data e check-in',
        'check_out_date' => 'Data e check-out',
        'status' => 'Statusi',
        'total_amount' => 'Totali',
        'adults' => 'Të rritur',
        'children' => 'Fëmijë',
        'notes' => 'Shënime',
        'channel' => 'Kanali',
        'channel_ref' => 'Kodi OTA',
        'payment_collect' => 'Mbledhja e pagesës',
        'eta' => 'Ora e mbërritjes',
        'etd' => 'Ora e largimit',
        'early_check_in' => 'Check-in i hershëm',
        'late_check_out' => 'Check-out i vonë',
        'no_show_at' => 'No-show',
        'first_name' => 'Emri',
        'last_name' => 'Mbiemri',
        'email' => 'Email',
        'phone' => 'Telefoni',
        'nationality' => 'Kombësia',
        'document_type' => 'Lloji i dokumentit',
        'document_number' => 'Numri i dokumentit',
        'date_of_birth' => 'Datëlindja',
        'marketing_consent' => 'Leje marketingu',
    ];

    private const DETAIL_LABELS = [
        'amount' => 'Shuma',
        'method' => 'Mënyra',
        'type' => 'Lloji',
        'room' => 'Dhoma',
        'from' => 'Nga',
        'to' => 'Në',
        'context' => 'Konteksti',
        'merged_guest' => 'Profili i arkivuar',
        'reservations_moved' => 'Rezervime të transferuara',
        'documents_moved' => 'Dokumente të transferuara',
        'invoices_moved' => 'Fatura të transferuara',
        'reviews_moved' => 'Vlerësime të transferuara',
    ];

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @param  array<int, array{label:string,url:?string}>  $subjects
     * @return array<int, array<string, mixed>>
     */
    public function entries(Collection $logs, array $subjects = []): array
    {
        return $logs->map(fn (AuditLog $log) => $this->entry($log, $subjects[$log->id] ?? null))->all();
    }

    /** @param array{label:string,url:?string}|null $subject */
    public function entry(AuditLog $log, ?array $subject = null): array
    {
        $properties = (array) $log->properties;
        $changes = collect((array) ($properties['changes'] ?? []))
            ->map(function ($change, $field) {
                $change = (array) $change;

                return [
                    'field' => $field,
                    'label' => self::FIELD_LABELS[$field] ?? Str::headline($field),
                    'from' => $this->displayValue($change['from_label'] ?? $change['from'] ?? null, $field),
                    'to' => $this->displayValue($change['to_label'] ?? $change['to'] ?? null, $field),
                ];
            })
            ->values()
            ->all();

        $details = collect($properties)
            ->except(['changes', 'missing_room_id'])
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->map(fn ($value, $key) => [
                'label' => self::DETAIL_LABELS[$key] ?? Str::headline($key),
                'value' => $this->displayValue($value, $key),
            ])
            ->values()
            ->all();

        return [
            'id' => $log->id,
            'action' => $log->action,
            'label' => self::ACTION_LABELS[$log->action] ?? Str::headline(str_replace('.', ' ', $log->action)),
            'actor' => $log->causer?->name,
            'source' => $log->source ?: ($log->causer_id ? 'staff' : 'system'),
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
            'changes' => $changes,
            'details' => $details,
            'subject' => $subject,
        ];
    }

    private function displayValue(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value) || in_array($field, ['early_check_in', 'late_check_out', 'marketing_consent'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Po' : 'Jo';
        }

        if ($field === 'status') {
            return [
                'pending' => 'Në pritje', 'confirmed' => 'Konfirmuar',
                'checked_in' => 'Brenda', 'checked_out' => 'Larguar',
                'cancelled' => 'Anulluar',
            ][$value] ?? (string) $value;
        }

        if (in_array($field, ['total_amount', 'amount'], true) && is_numeric($value)) {
            return number_format((float) $value, 2).'€';
        }

        return (string) $value;
    }
}
