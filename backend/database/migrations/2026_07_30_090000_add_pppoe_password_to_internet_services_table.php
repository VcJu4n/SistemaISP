<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->text('pppoe_password')->nullable()->after('pppoe_username');
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn('pppoe_password');
        });
    }
};
