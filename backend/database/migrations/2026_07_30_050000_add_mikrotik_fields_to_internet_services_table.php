<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->unsignedBigInteger('mikrotik_router_id')->nullable()->after('notes')->index();
            $table->string('mikrotik_control_method', 30)->default('manual')->after('mikrotik_router_id')->index();
            $table->string('pppoe_username')->nullable()->after('mikrotik_control_method');
            $table->string('pppoe_profile')->nullable()->after('pppoe_username');
            $table->string('simple_queue_name')->nullable()->after('pppoe_profile');
            $table->ipAddress('service_ip_address')->nullable()->after('simple_queue_name');
            $table->string('service_mac_address', 20)->nullable()->after('service_ip_address');
            $table->ipAddress('client_antenna_ip')->nullable()->after('service_mac_address');
            $table->string('client_antenna_mac', 20)->nullable()->after('client_antenna_ip');
            $table->string('client_antenna_brand_model')->nullable()->after('client_antenna_mac');
            $table->string('client_antenna_device_name')->nullable()->after('client_antenna_brand_model');
            $table->text('technical_notes')->nullable()->after('client_antenna_device_name');
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn([
                'mikrotik_router_id',
                'mikrotik_control_method',
                'pppoe_username',
                'pppoe_profile',
                'simple_queue_name',
                'service_ip_address',
                'service_mac_address',
                'client_antenna_ip',
                'client_antenna_mac',
                'client_antenna_brand_model',
                'client_antenna_device_name',
                'technical_notes',
            ]);
        });
    }
};
