<?php

namespace App\Http\Requests\Beach;

use App\Models\BeachZone;
use App\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBeachZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update_beach') ?? false;
    }

    public function rules(): array
    {
        /** @var BeachZone $zone */
        $zone = $this->route('zone');

        return [
            'name' => ['required', 'string', 'max:100', TenantRule::unique('beach_zones', 'name')->ignore($zone->id)],
            'price_per_day' => ['required', 'numeric', 'min:0', 'max:99999'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
