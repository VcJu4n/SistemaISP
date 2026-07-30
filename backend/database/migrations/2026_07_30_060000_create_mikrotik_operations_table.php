<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internet_service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mikrotik_router_id')->nullable()->index();
            $table->string('action', 30)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'action']);
            $table->index(['internet_service_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_operations');
    }
};
