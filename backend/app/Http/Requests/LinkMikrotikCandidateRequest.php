<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkMikrotikCandidateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
        ];
    }
}
