<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_routers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->ipAddress('ip_address');
            $table->unsignedSmallInteger('api_port')->default(8728);
            $table->string('username');
            $table->text('password');
            $table->boolean('use_ssl')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->string('connection_status', 20)->default('pending')->index();
            $table->timestamp('last_successful_connection_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['ip_address', 'api_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_routers');
    }
};
