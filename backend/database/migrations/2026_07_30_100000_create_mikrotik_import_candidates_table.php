<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_import_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('internet_service_id')->nullable()->constrained('internet_services')->nullOnDelete();
            $table->string('source_type', 30);
            $table->string('external_id')->nullable();
            $table->string('identifier');
            $table->string('display_name')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('mac_address', 20)->nullable();
            $table->string('profile')->nullable();
            $table->string('rate_limit')->nullable();
            $table->string('status', 20)->default('unlinked')->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['mikrotik_router_id', 'source_type', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_import_candidates');
    }
};
