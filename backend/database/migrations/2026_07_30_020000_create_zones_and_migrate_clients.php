<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('address')->constrained()->restrictOnDelete();
        });

        DB::table('clients')
            ->select('coverage_zone')
            ->whereNotNull('coverage_zone')
            ->where('coverage_zone', '!=', '')
            ->distinct()
            ->orderBy('coverage_zone')
            ->each(function (object $row): void {
                $zoneId = DB::table('zones')->insertGetId([
                    'name' => $row->coverage_zone,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('clients')
                    ->where('coverage_zone', $row->coverage_zone)
                    ->update(['zone_id' => $zoneId]);
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['coverage_zone']);
            $table->dropColumn('coverage_zone');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('coverage_zone', 100)->nullable();
        });

        DB::table('clients')
            ->join('zones', 'zones.id', '=', 'clients.zone_id')
            ->update(['coverage_zone' => DB::raw('zones.name')]);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->index('coverage_zone');
        });

        Schema::dropIfExists('zones');
    }
};
