<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('zones', 'name')->ignore($this->route('zone'))],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['required', 'boolean'],
        ];
    }
}
