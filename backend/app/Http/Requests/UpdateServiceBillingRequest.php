<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:31'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:15'],
            'billing_amount' => ['nullable', 'numeric', 'min:0'],
            'billing_enabled' => ['required', 'boolean'],
        ];
    }
}
