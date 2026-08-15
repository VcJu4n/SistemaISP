<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table): void {
            $table->timestamp('last_checked_at')->nullable()->after('connection_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table): void {
            $table->dropColumn('last_checked_at');
        });
    }
};
