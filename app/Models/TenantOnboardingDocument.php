<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOnboardingDocument extends Model
{
    protected $fillable = [
        'tenant_onboarding_id', 'uploaded_by', 'step_key', 'name', 'disk', 'path',
        'mime_type', 'size',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(TenantOnboarding::class, 'tenant_onboarding_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withoutGlobalScopes();
    }
}
