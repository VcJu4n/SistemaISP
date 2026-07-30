<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->foreign('mikrotik_router_id')
                ->references('id')
                ->on('mikrotik_routers')
                ->nullOnDelete();
        });

        Schema::table('mikrotik_operations', function (Blueprint $table) {
            $table->foreign('mikrotik_router_id')
                ->references('id')
                ->on('mikrotik_routers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_operations', function (Blueprint $table) {
            $table->dropForeign(['mikrotik_router_id']);
        });

        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropForeign(['mikrotik_router_id']);
        });
    }
};
