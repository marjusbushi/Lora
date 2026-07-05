<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleaningTask extends Model
{
    protected $fillable = [
        'room_id',
        'assigned_to',
        'started_by',
        'inspected_by',
        'type',
        'status',
        'priority',
        'notes',
        'issue_reported',
        'checklist',
        'completed_at',
        'started_at',
        'inspected_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'completed_at' => 'datetime',
            'started_at' => 'datetime',
            'inspected_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Built-in checklist templates per cleaning type (Albanian).
     * Overridable per-property via Setting housekeeping.checklists — see templateFor().
     */
    public const DEFAULT_CHECKLISTS = [
        'checkout_clean' => [
            'Zhvesh dhe ndëro çarçafët',
            'Ndëro peshqirët',
            'Pastro banjon (WC, dush, lavaman, pasqyrë)',
            'Fshi pluhurat dhe sipërfaqet',
            'Fshi ose aspiro dyshemenë',
            'Zbraz mbeturinat',
            'Rimbush amenities (sapun, shampo, letër higjenike)',
            'Kontrollo dhe rimbush minibarin',
            'Kontrollo pajisjet (TV, AC, dritat)',
            'Kontrollo për dëmtime ose sende të harruara',
        ],
        'stayover_clean' => [
            'Rregullo krevatin',
            'Ndëro peshqirët e përdorur',
            'Pastrim i shpejtë i banjos',
            'Zbraz mbeturinat',
            'Rimbush amenities dhe minibarin',
        ],
        'deep_clean' => [
            'Zhvesh dhe ndëro çarçafët',
            'Ndëro peshqirët',
            'Pastro banjon në thellësi (fugat, rubinetet)',
            'Fshi xhamat dhe pastro perdet',
            'Pastro pas dhe poshtë mobiljeve',
            'Larje ose pastrim i tapetit',
            'Fshi pluhurat dhe sipërfaqet',
            'Aspiro dhe laj dyshemenë',
            'Zbraz mbeturinat',
            'Rimbush amenities dhe minibarin',
            'Kontrollo pajisjet dhe raporto dëmtime',
        ],
    ];

    /**
     * Resolve the checklist template for a cleaning type: a per-property override
     * (Setting housekeeping.checklists) wins if that type's key exists (even empty =
     * "no checklist"); otherwise fall back to the built-in default.
     *
     * @return list<string>
     */
    public static function templateFor(string $type): array
    {
        $overrides = Setting::get('housekeeping.checklists', null);

        $items = is_array($overrides) && array_key_exists($type, $overrides)
            ? (is_array($overrides[$type]) ? $overrides[$type] : [])
            : (self::DEFAULT_CHECKLISTS[$type] ?? []);

        return array_values(array_filter(
            array_map(fn ($label) => trim((string) $label), $items),
            fn ($label) => $label !== ''
        ));
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function inspectedBy()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
