<?php

namespace App\Http\Requests\Beach;

use App\Models\BeachSeason;
use App\Models\BeachZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Krijim + përditësim i një sezoni çmimesh plazhi (route param {season} kur përditësohet). */
class SaveBeachSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('season') ? 'update_beach' : 'create_beach';

        return $this->user()?->can($permission) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            // Çelësi = beach_zone_id; vlera bosh/null = zona përdor çmimin bazë.
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            // Çdo ditë i përket maksimumi NJË sezoni — mbivendosja refuzohet
            // që çmimi ditor të jetë gjithmonë i vetëm dhe i parashikueshëm.
            $overlap = BeachSeason::query()
                ->when($this->route('season'), fn ($query) => $query->whereKeyNot($this->route('season')->id))
                ->where('start_date', '<=', $this->input('end_date'))
                ->where('end_date', '>=', $this->input('start_date'))
                ->first();

            if ($overlap) {
                $validator->errors()->add(
                    'start_date',
                    "Datat mbivendosen me sezonin \"{$overlap->name}\" ({$overlap->start_date->format('d.m.Y')}–{$overlap->end_date->format('d.m.Y')}) — çdo ditë i përket vetëm një sezoni.",
                );
            }

            // Çelësat e çmimeve duhet të jenë zona reale të KËTIJ tenanti
            // (scope-i i TenantModel e bën listën fail-closed).
            $zoneIds = array_keys($this->input('prices', []) ?? []);
            if ($zoneIds !== []) {
                $known = BeachZone::query()->whereIn('id', $zoneIds)->pluck('id')->all();
                foreach (array_diff($zoneIds, $known) as $unknown) {
                    $validator->errors()->add('prices', "Zona #{$unknown} nuk ekziston.");
                }
            }
        });
    }
}
