<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'latitude')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->decimal('latitude', 10, 7)->nullable()->after('location_reference');
            });
        }

        if (! Schema::hasColumn('clients', 'longitude')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }
    }

    public function down(): void
    {
        foreach (['latitude', 'longitude'] as $column) {
            if (Schema::hasColumn('clients', $column)) {
                Schema::table('clients', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
