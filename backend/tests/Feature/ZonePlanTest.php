<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZonePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_zone_can_be_registered_and_updated(): void
    {
        $zoneId = $this->postJson('/api/zones', ['name' => 'Zona Norte', 'description' => 'Cobertura norte'])
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->json('data.id');

        $this->putJson("/api/zones/{$zoneId}", ['name' => 'Zona Norte', 'description' => null, 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }

    public function test_zone_names_must_be_unique(): void
    {
        Zone::factory()->create(['name' => 'Centro']);
        $this->postJson('/api/zones', ['name' => 'Centro'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_zone_list_includes_client_count_and_filters(): void
    {
        $zone = Zone::factory()->create(['name' => 'Centro', 'active' => true]);
        Client::factory()->count(2)->create(['zone_id' => $zone]);
        Zone::factory()->create(['name' => 'Sur', 'active' => false]);

        $this->getJson('/api/zones?search=cent&active=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.clients_count', 2);
    }

    public function test_plan_can_be_registered_for_multiple_zones(): void
    {
        $zones = Zone::factory()->count(2)->create();

        $this->postJson('/api/plans', $this->planData($zones->pluck('id')->all()))
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonCount(2, 'data.zones');
    }

    public function test_plan_validates_speeds_price_and_zones(): void
    {
        $this->postJson('/api/plans', [
            ...$this->planData([]), 'download_mbps' => 0, 'upload_mbps' => 0, 'monthly_price' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['download_mbps', 'upload_mbps', 'monthly_price', 'zone_ids']);
    }

    public function test_plan_can_be_edited_deactivated_and_filtered_by_zone(): void
    {
        $north = Zone::factory()->create();
        $south = Zone::factory()->create();
        $plan = Plan::factory()->create();
        $plan->zones()->attach($north);

        $this->putJson("/api/plans/{$plan->id}", [
            ...$this->planData([$south->id]), 'name' => $plan->name, 'active' => false,
        ])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.zones.0.id', $south->id);

        $this->getJson("/api/plans?zone_id={$south->id}&active=0")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    private function planData(array $zoneIds): array
    {
        return [
            'name' => 'Plan Hogar 30', 'download_mbps' => 30, 'upload_mbps' => 15,
            'monthly_price' => 150, 'description' => 'Plan residencial', 'zone_ids' => $zoneIds,
        ];
    }
}
