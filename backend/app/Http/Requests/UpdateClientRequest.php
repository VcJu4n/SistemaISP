<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'document' => [
                'required',
                'string',
                'max:30',
                Rule::unique('clients', 'document')->ignore($this->route('client')),
            ],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'location_reference' => ['nullable', 'string', 'max:500'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'installation_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.unique' => 'Ya existe un cliente con esta cédula o RUC.',
        ];
    }
}
