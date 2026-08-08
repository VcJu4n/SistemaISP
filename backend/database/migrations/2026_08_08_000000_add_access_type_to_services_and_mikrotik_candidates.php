<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table): void {
            $table->string('access_type', 20)->nullable()->after('status')->index();
        });

        Schema::table('mikrotik_import_candidates', function (Blueprint $table): void {
            $table->string('access_type', 20)->nullable()->after('source_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_import_candidates', fn (Blueprint $table) => $table->dropColumn('access_type'));
        Schema::table('internet_services', fn (Blueprint $table) => $table->dropColumn('access_type'));
    }
};
