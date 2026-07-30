<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('download_mbps');
            $table->unsignedInteger('upload_mbps');
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('plan_zone', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->primary(['plan_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_zone');
        Schema::dropIfExists('plans');
    }
};
