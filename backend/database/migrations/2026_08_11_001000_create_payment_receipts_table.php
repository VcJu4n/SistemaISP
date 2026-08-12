<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 20)->unique();
            $table->foreignId('internet_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_name');
            $table->string('client_document', 30)->nullable();
            $table->string('client_phone', 30)->nullable();
            $table->string('plan_name', 100)->nullable();
            $table->date('payment_date');
            $table->date('cutoff_date')->nullable();
            $table->string('billing_period', 120)->nullable();
            $table->string('concept');
            $table->text('observations')->nullable();
            $table->string('currency', 30)->default('Bolivianos');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('iva_rate', 5, 2)->default(0);
            $table->decimal('retention_rate', 5, 2)->default(0);
            $table->decimal('iva', 10, 2)->default(0);
            $table->decimal('retention', 10, 2)->default(0);
            $table->decimal('total_received', 10, 2);
            $table->string('amount_words');
            $table->timestamps();

            $table->index(['payment_date', 'receipt_number']);
            $table->index(['internet_service_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
