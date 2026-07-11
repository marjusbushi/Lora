<?php

namespace App\Http\Requests;

use App\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class GuestUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update_guests');
    }

    protected function prepareForValidation(): void
    {
        if ($this->email) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        $guestId = $this->route('guest')->id;

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', TenantRule::unique('guests', 'email')->ignore($guestId)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'document_type' => ['nullable', 'in:id_card,passport,drivers_license'],
            'document_number' => ['nullable', 'string', 'max:50', TenantRule::unique('guests', 'document_number')->ignore($guestId)],
            'nationality' => ['nullable', 'string', 'max:3'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'preferences' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ekziston nje mysafir me kete email.',
            'document_number.unique' => 'Ekziston nje mysafir me kete numer dokumenti.',
        ];
    }
}
