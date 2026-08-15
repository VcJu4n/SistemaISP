<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_import_candidates', function (Blueprint $table): void {
            $table->string('classification', 20)->default('device')->index()->after('source_type');
        });

        DB::table('mikrotik_import_candidates')
            ->whereIn('source_type', ['pppoe', 'simple_queue'])
            ->update(['classification' => 'service']);

        DB::table('mikrotik_import_candidates')
            ->where('source_type', 'dhcp_mac')
            ->orderBy('id')
            ->each(function (object $candidate): void {
                $payload = is_string($candidate->raw_payload)
                    ? json_decode($candidate->raw_payload, true)
                    : (array) $candidate->raw_payload;
                $comment = trim((string) ($payload['comment'] ?? ''));
                $dynamic = filter_var($payload['dynamic'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! $dynamic && preg_match('/^(CPE|CLIENTE)-/i', $comment) === 1) {
                    DB::table('mikrotik_import_candidates')
                        ->where('id', $candidate->id)
                        ->update(['classification' => 'antenna']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('mikrotik_import_candidates', function (Blueprint $table): void {
            $table->dropColumn('classification');
        });
    }
};
