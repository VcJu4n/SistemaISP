<?php

namespace Tests\Feature;

use App\Models\InternetService;
use App\Models\MikrotikRouter;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_dashboard_is_scoped_to_selected_router(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $selectedRouter = MikrotikRouter::factory()->create();
        $otherRouter = MikrotikRouter::factory()->create();
        $selectedService = InternetService::factory()->create([
            'mikrotik_router_id' => $selectedRouter,
            'status' => 'active',
            'next_due_date' => now()->subDay()->toDateString(),
        ]);
        $otherService = InternetService::factory()->create([
            'mikrotik_router_id' => $otherRouter,
            'status' => 'active',
            'next_due_date' => now()->subDay()->toDateString(),
        ]);
        Payment::factory()->create([
            'client_id' => $selectedService->client_id,
            'internet_service_id' => $selectedService,
            'paid_at' => now()->toDateString(),
            'amount' => 100,
        ]);
        Payment::factory()->create([
            'client_id' => $otherService->client_id,
            'internet_service_id' => $otherService,
            'paid_at' => now()->toDateString(),
            'amount' => 900,
        ]);

        $this->getJson("/api/dashboard/billing-summary?mikrotik_router_id={$selectedRouter->id}")
            ->assertOk()
            ->assertJsonPath('data.active_services', 1)
            ->assertJsonPath('data.overdue_services', 1)
            ->assertJsonPath('data.payments_this_month', 1)
            ->assertJsonPath('data.collected_this_month', '100.00');
    }
}
