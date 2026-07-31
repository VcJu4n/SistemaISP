<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServiceTechnicalConfig;
use Illuminate\Foundation\Http\FormRequest;

class StoreInternetServiceRequest extends FormRequest
{
    use ValidatesServiceTechnicalConfig;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return array_merge([
            'client_id' => ['required', 'integer', 'exists:clients,id', 'unique:internet_services,client_id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'installation_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], $this->technicalConfigRules());
    }

    public function messages(): array
    {
        return array_merge([
            'client_id.unique' => 'Este cliente ya tiene un servicio asignado.',
        ], $this->technicalConfigMessages());
    }
}
