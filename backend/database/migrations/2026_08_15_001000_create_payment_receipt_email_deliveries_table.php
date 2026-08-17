<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipt_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('status', 20)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['payment_receipt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipt_email_deliveries');
    }
};
