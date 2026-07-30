<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'document' => ['required', 'string', 'max:30', 'unique:clients,document'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'zone_id' => [
                'required',
                'integer',
                Rule::exists('zones', 'id')->where('active', true),
            ],
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
