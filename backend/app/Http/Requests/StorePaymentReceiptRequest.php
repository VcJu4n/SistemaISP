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
            'billing_charge_id' => ['required', 'integer', 'exists:billing_charges,id'],
            'payment_date' => ['required', 'date'],
            'observations' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'max:30'],
            'payment_amount' => ['nullable', 'numeric', 'min:0.01'],
            'iva_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'retention_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'send_email' => ['nullable', 'boolean'],
            'email_to' => ['nullable', 'email', 'max:255'],
        ];
    }
}
