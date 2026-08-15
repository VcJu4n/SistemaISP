<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'location_reference')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->text('location_reference')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'location_reference')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('location_reference');
        });
    }
};
