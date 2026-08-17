<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendPaymentReceiptEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_to' => ['nullable', 'email', 'max:255'],
        ];
    }
}
