<?php

namespace Database\Factories;

use App\Models\PaymentReceipt;
use App\Models\PaymentReceiptEmailDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentReceiptEmailDelivery> */
class PaymentReceiptEmailDeliveryFactory extends Factory
{
    protected $model = PaymentReceiptEmailDelivery::class;

    public function definition(): array
    {
        return [
            'payment_receipt_id' => PaymentReceipt::factory(),
            'sent_by' => User::factory(),
            'recipient_email' => fake()->safeEmail(),
            'status' => PaymentReceiptEmailDelivery::STATUS_SENT,
            'error_message' => null,
            'sent_at' => now(),
        ];
    }
}
