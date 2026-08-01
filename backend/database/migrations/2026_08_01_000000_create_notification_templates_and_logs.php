<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->text('body');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('internet_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('channel', 30)->default('whatsapp')->index();
            $table->string('phone', 30);
            $table->text('message');
            $table->timestamp('sent_at')->index();
            $table->timestamps();

            $table->index(['client_id', 'sent_at']);
            $table->index(['internet_service_id', 'type']);
        });

        DB::table('notification_templates')->insert([
            [
                'key' => 'payment_due_5',
                'name' => 'Vence en 5 dias',
                'body' => 'Hola {nombre}, te recordamos que tu servicio de internet vence el {fecha}. El monto a pagar es Bs {monto}. {instrucciones_pago}',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'payment_due_2',
                'name' => 'Vence en 2 dias',
                'body' => 'Hola {nombre}, tu pago vence en {dias} dias ({fecha}). Monto pendiente: Bs {monto}. {instrucciones_pago}',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'payment_due_today',
                'name' => 'Vence hoy',
                'body' => 'Hola {nombre}, tu servicio vence hoy ({fecha}). Para evitar interrupciones, realiza el pago de Bs {monto}. {instrucciones_pago}',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'suspended',
                'name' => 'Suspendidos',
                'body' => 'Hola {nombre}, tu servicio se encuentra suspendido por pago pendiente de Bs {monto}. {instrucciones_pago}',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
    }
};
