<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeServicePlanRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $service = $this->route('service');

        return [
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'pppoe_profile' => [
                Rule::requiredIf($service?->mikrotik_control_method === 'pppoe'),
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'pppoe_profile.required' => 'El perfil PPPoE del nuevo plan es obligatorio para cambiar la velocidad.',
        ];
    }
}
