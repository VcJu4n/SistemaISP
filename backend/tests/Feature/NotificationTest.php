<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Operador Cobranza']);
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_notices_kanban_groups_services_by_due_date_and_suspended_status(): void
    {
        CarbonImmutable::setTestNow('2026-08-01');
        $dueFive = $this->service(['next_due_date' => '2026-08-06']);
        $this->service(['next_due_date' => '2026-08-03']);
        $this->service(['next_due_date' => '2026-08-01']);
        $this->service(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'debt', 'next_due_date' => '2026-07-30']);

        $this->getJson('/api/notices/kanban')
            ->assertOk()
            ->assertJsonPath('data.columns.0.key', NotificationTemplate::PAYMENT_DUE_5)
            ->assertJsonPath('data.columns.0.cards.0.service_id', $dueFive->id)
            ->assertJsonPath('data.columns.0.cards.0.amount', '150.00')
            ->assertJsonCount(1, 'data.columns.1.cards')
            ->assertJsonCount(1, 'data.columns.2.cards')
            ->assertJsonCount(1, 'data.columns.3.cards');
    }

    public function test_whatsapp_notice_renders_template_and_registers_log_and_history(): void
    {
        CarbonImmutable::setTestNow('2026-08-01');
        $service = $this->service(['next_due_date' => '2026-08-06']);

        $this->postJson("/api/notices/{$service->id}/whatsapp", [
            'template_key' => NotificationTemplate::PAYMENT_DUE_5,
        ])->assertCreated()
            ->assertJsonPath('data.log.user.name', 'Operador Cobranza')
            ->assertJsonPath('data.log.type', NotificationTemplate::PAYMENT_DUE_5)
            ->assertJsonPath('data.message', 'Hola Juan Perez, te recordamos que tu servicio de internet vence el 06/08/2026. El monto a pagar es Bs 150.00. Puedes pagar en oficina o por transferencia y enviar el comprobante por este medio.')
            ->assertJsonPath('data.wa_url', 'https://wa.me/71234567?text=Hola%20Juan%20Perez%2C%20te%20recordamos%20que%20tu%20servicio%20de%20internet%20vence%20el%2006%2F08%2F2026.%20El%20monto%20a%20pagar%20es%20Bs%20150.00.%20Puedes%20pagar%20en%20oficina%20o%20por%20transferencia%20y%20enviar%20el%20comprobante%20por%20este%20medio.');

        $this->assertDatabaseHas('notification_logs', [
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'type' => NotificationTemplate::PAYMENT_DUE_5,
            'phone' => '71234567',
        ]);
        $this->assertDatabaseHas('service_histories', [
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'event_type' => 'notification_sent',
        ]);
    }

    public function test_template_can_be_updated(): void
    {
        $template = NotificationTemplate::query()->where('key', NotificationTemplate::PAYMENT_DUE_2)->firstOrFail();

        $this->putJson("/api/notification-templates/{$template->id}", [
            'name' => 'Recordatorio 2 dias',
            'body' => 'Hola {nombre}, debes Bs {monto}.',
            'active' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Recordatorio 2 dias')
            ->assertJsonPath('data.body', 'Hola {nombre}, debes Bs {monto}.');
    }

    public function test_client_notification_logs_are_sorted_descending(): void
    {
        $service = $this->service();
        NotificationLog::query()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'notification_template_id' => NotificationTemplate::query()->where('key', NotificationTemplate::PAYMENT_DUE_5)->value('id'),
            'user_id' => $this->user->id,
            'type' => NotificationTemplate::PAYMENT_DUE_5,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'phone' => '71234567',
            'message' => 'Primero',
            'sent_at' => '2026-08-01 08:00:00',
        ]);
        NotificationLog::query()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'notification_template_id' => NotificationTemplate::query()->where('key', NotificationTemplate::PAYMENT_DUE_2)->value('id'),
            'user_id' => $this->user->id,
            'type' => NotificationTemplate::PAYMENT_DUE_2,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'phone' => '71234567',
            'message' => 'Segundo',
            'sent_at' => '2026-08-02 08:00:00',
        ]);

        $this->getJson("/api/clients/{$service->client_id}/notification-logs")
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Segundo')
            ->assertJsonPath('data.1.message', 'Primero');
    }

    public function test_notification_summary_can_be_filtered_by_period(): void
    {
        $service = $this->service();
        NotificationLog::query()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'notification_template_id' => NotificationTemplate::query()->where('key', NotificationTemplate::PAYMENT_DUE_5)->value('id'),
            'user_id' => $this->user->id,
            'type' => NotificationTemplate::PAYMENT_DUE_5,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'phone' => '71234567',
            'message' => 'Agosto',
            'sent_at' => '2026-08-02 08:00:00',
        ]);
        NotificationLog::query()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'notification_template_id' => NotificationTemplate::query()->where('key', NotificationTemplate::SUSPENDED)->value('id'),
            'user_id' => $this->user->id,
            'type' => NotificationTemplate::SUSPENDED,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'phone' => '71234567',
            'message' => 'Septiembre',
            'sent_at' => '2026-09-02 08:00:00',
        ]);

        $this->getJson('/api/notices/summary?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.notifications_count', 1)
            ->assertJsonPath('data.by_type.0.type', NotificationTemplate::PAYMENT_DUE_5);
    }

    private function service(array $attributes = []): InternetService
    {
        $zone = Zone::factory()->create();
        $client = Client::factory()->create([
            'full_name' => 'Juan Perez',
            'phone' => '71234567',
            'zone_id' => $zone->id,
        ]);
        $plan = Plan::factory()->create(['name' => 'Plan 20 Mbps', 'monthly_price' => 150, 'active' => true]);
        $plan->zones()->attach($zone);

        return InternetService::factory()->create([
            ...$attributes,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
        ]);
    }

}
