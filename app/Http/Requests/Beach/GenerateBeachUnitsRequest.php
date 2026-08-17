<?php

namespace App\Http\Requests\Beach;

use Illuminate\Foundation\Http\FormRequest;

class GenerateBeachUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create_beach') ?? false;
    }

    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:200'],
            'start_number' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ];
    }
}
