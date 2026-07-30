<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInternetServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id', 'unique:internet_services,client_id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'installation_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['client_id.unique' => 'Este cliente ya tiene un servicio asignado.'];
    }
}
