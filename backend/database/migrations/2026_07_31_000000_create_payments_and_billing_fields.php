<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->date('next_due_date')->nullable()->after('installation_date')->index();
        });

        Schema::table('service_histories', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('internet_service_id')->constrained()->nullOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('internet_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('paid_at')->index();
            $table->string('billing_period', 7)->index();
            $table->string('payment_method', 30)->index();
            $table->text('observation')->nullable();
            $table->date('previous_due_date')->nullable();
            $table->date('next_due_date')->nullable();
            $table->boolean('duplicate_confirmed')->default(false);
            $table->timestamps();

            $table->index(['internet_service_id', 'billing_period']);
            $table->index(['client_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('service_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn('next_due_date');
        });
    }
};
