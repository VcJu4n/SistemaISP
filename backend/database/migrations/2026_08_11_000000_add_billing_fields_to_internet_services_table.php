<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table): void {
            $table->unsignedTinyInteger('billing_day')->default(1)->after('notes');
            $table->unsignedTinyInteger('cutoff_day')->default(1)->after('billing_day');
            $table->unsignedTinyInteger('grace_days')->default(3)->after('cutoff_day');
            $table->decimal('billing_amount', 10, 2)->nullable()->after('grace_days');
            $table->boolean('billing_enabled')->default(true)->index()->after('billing_amount');
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_day',
                'cutoff_day',
                'grace_days',
                'billing_amount',
                'billing_enabled',
            ]);
        });
    }
};
