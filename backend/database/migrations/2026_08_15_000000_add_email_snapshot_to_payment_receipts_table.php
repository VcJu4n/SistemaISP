<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->string('client_email')->nullable()->after('client_phone');
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->dropColumn('client_email');
        });
    }
};
