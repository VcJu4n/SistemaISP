<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:plans,name'],
            'download_mbps' => ['required', 'integer', 'min:1', 'max:100000'],
            'upload_mbps' => ['required', 'integer', 'min:1', 'max:100000'],
            'monthly_price' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:500'],
            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer', 'distinct', 'exists:zones,id'],
        ];
    }
}
