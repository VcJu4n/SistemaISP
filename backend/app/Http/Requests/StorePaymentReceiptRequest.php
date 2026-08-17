<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'internet_service_id' => ['required', 'integer', 'exists:internet_services,id'],
            'payment_date' => ['required', 'date'],
            'cutoff_date' => ['nullable', 'date'],
            'billing_period' => ['nullable', 'string', 'max:120'],
            'concept' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'max:30'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'iva_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'retention_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'send_email' => ['nullable', 'boolean'],
            'email_to' => ['nullable', 'email', 'max:255'],
        ];
    }
}
