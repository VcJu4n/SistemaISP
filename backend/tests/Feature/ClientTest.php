<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Zone;
use App\Models\InternetService;
use App\Models\Plan;
use App\Models\MikrotikRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_register_a_client(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $zone = Zone::factory()->create();

        $this->postJson('/api/clients', $this->clientData($zone))
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.full_name', 'Ana Pérez');

        $this->assertDatabaseHas('clients', [
            'document' => '7894561',
            'status' => 'active',
        ]);
    }

    public function test_document_must_be_unique(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $zone = Zone::factory()->create();
        Client::factory()->create(['document' => '7894561', 'zone_id' => $zone]);

        $this->postJson('/api/clients', $this->clientData($zone))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    public function test_administrator_can_update_a_client_without_changing_its_id(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $client = Client::factory()->create();

        $this->putJson("/api/clients/{$client->id}", [
            ...$this->clientData($client->zone),
            'full_name' => 'Ana Pérez Actualizada',
        ])->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.full_name', 'Ana Pérez Actualizada');
    }

    public function test_clients_can_be_searched_by_partial_data(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Client::factory()->create(['full_name' => 'Carlos Mendoza', 'document' => '111111']);
        Client::factory()->create(['full_name' => 'María Flores', 'document' => '222222']);

        $this->getJson('/api/clients?search=mendo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Carlos Mendoza');
    }

    public function test_clients_can_be_filtered_by_status_and_zone(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $north = Zone::factory()->create(['name' => 'Norte']);
        $south = Zone::factory()->create(['name' => 'Sur']);
        Client::factory()->create(['status' => 'active', 'zone_id' => $north]);
        Client::factory()->create(['status' => 'suspended', 'zone_id' => $north]);
        Client::factory()->create(['status' => 'active', 'zone_id' => $south]);

        $this->getJson("/api/clients?status=active&zone_id={$north->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.zone.name', 'Norte');
    }

    public function test_client_location_is_stored_and_returned(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $zone = Zone::factory()->create();

        $this->postJson('/api/clients', [
            ...$this->clientData($zone),
            'latitude' => -17.783421,
            'longitude' => -63.182135,
            'location_reference' => 'Casa azul frente a la cancha.',
        ])->assertCreated()
            ->assertJsonPath('data.latitude', '-17.7834210')
            ->assertJsonPath('data.longitude', '-63.1821350');
    }

    public function test_clients_can_be_filtered_by_mikrotik_router(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $firstRouter = MikrotikRouter::factory()->create();
        $secondRouter = MikrotikRouter::factory()->create();
        $firstClient = Client::factory()->create();
        $secondClient = Client::factory()->create();
        InternetService::factory()->create(['client_id' => $firstClient, 'mikrotik_router_id' => $firstRouter]);
        InternetService::factory()->create(['client_id' => $secondClient, 'mikrotik_router_id' => $secondRouter]);

        $this->getJson("/api/clients?mikrotik_router_id={$firstRouter->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstClient->id)
            ->assertJsonPath('data.0.internet_service.mikrotik_router.name', $firstRouter->name);
    }

    public function test_both_coordinates_are_required_together(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $zone = Zone::factory()->create();

        $this->postJson('/api/clients', [...$this->clientData($zone), 'latitude' => -17.783421])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');
    }

    public function test_archiving_a_client_uses_soft_deletes(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $client = Client::factory()->create();

        $this->deleteJson("/api/clients/{$client->id}")->assertOk();

        $this->assertSoftDeleted($client);
        $this->getJson('/api/clients')->assertJsonCount(0, 'data');
    }

    public function test_client_with_a_service_cannot_be_archived(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $client = Client::factory()->create();
        InternetService::factory()->create([
            'client_id' => $client,
            'plan_id' => Plan::factory(),
        ]);

        $this->deleteJson("/api/clients/{$client->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client');

        $this->assertNotSoftDeleted($client);
    }

    public function test_client_routes_require_authentication(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
        $this->postJson('/api/clients', [])->assertUnauthorized();
    }

    private function clientData(Zone $zone): array
    {
        return [
            'full_name' => 'Ana Pérez',
            'document' => '7894561',
            'phone' => '71234567',
            'email' => 'ana@example.com',
            'address' => 'Av. Principal 123',
            'zone_id' => $zone->id,
            'installation_date' => '2026-07-30',
        ];
    }
}
