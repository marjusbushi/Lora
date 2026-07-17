<?php

namespace App\Models;

use App\Observers\GuestObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([GuestObserver::class])]
class Guest extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $hidden = [
        'merged_into_guest_tenant_id',
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'document_type',
        'document_number',
        'nationality',
        'date_of_birth',
        'preferences',
        'notes',
        'tags',
        'marketing_consent',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'preferences' => 'array',
            'tags' => 'array',
            'marketing_consent' => 'boolean',
            'merged_at' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Normalize email + phone on save so dedup/search are deterministic across
     * staff and website entry (case/whitespace and phone formatting variants).
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value ? strtolower(trim($value)) : $value;
    }

    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = $value ? preg_replace('/[^\d+]/', '', $value) : $value;
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function documents()
    {
        return $this->hasMany(GuestDocument::class)->latest();
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function mergedInto()
    {
        return $this->belongsTo(self::class, 'merged_into_guest_id')->withTrashed();
    }
}
