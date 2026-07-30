<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internet_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->date('installation_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 30)->nullable();
            $table->text('suspension_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('service_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internet_service_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 30)->index();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_histories');
        Schema::dropIfExists('internet_services');
    }
};
