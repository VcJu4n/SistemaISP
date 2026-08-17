<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internet_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('period_label', 120);
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('cutoff_date');
            $table->date('grace_deadline');
            $table->string('concept');
            $table->string('currency', 30)->default('Bolivianos');
            $table->decimal('original_amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('balance', 10, 2);
            $table->string('status', 20)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['internet_service_id', 'period_year', 'period_month'], 'billing_charges_service_period_unique');
            $table->index(['period_year', 'period_month', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->foreignId('billing_charge_id')
                ->nullable()
                ->after('receipt_number')
                ->constrained('billing_charges')
                ->nullOnDelete();

            $table->index(['billing_charge_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->dropForeign(['billing_charge_id']);
            $table->dropIndex(['billing_charge_id', 'payment_date']);
            $table->dropColumn('billing_charge_id');
        });

        Schema::dropIfExists('billing_charges');
    }
};
