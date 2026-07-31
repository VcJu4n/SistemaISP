<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->unique('pppoe_username');
            $table->unique('simple_queue_name');
            $table->unique('service_ip_address');
            $table->unique('service_mac_address');
            $table->unique('client_antenna_ip');
            $table->unique('client_antenna_mac');
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropUnique(['client_antenna_mac']);
            $table->dropUnique(['client_antenna_ip']);
            $table->dropUnique(['service_mac_address']);
            $table->dropUnique(['service_ip_address']);
            $table->dropUnique(['simple_queue_name']);
            $table->dropUnique(['pppoe_username']);
        });
    }
};
