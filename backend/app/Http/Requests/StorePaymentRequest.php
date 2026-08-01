<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'paid_at' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(Payment::METHODS)],
            'observation' => ['nullable', 'string', 'max:1000'],
            'reactivate_if_suspended' => ['nullable', 'boolean'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }
}
