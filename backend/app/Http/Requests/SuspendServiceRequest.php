<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuspendServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(['debt', 'client_request', 'technical', 'other'])],
            'notes' => ['nullable', 'required_if:reason,other', 'string', 'max:1000'],
        ];
    }
}
