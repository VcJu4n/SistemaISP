<?php

namespace Database\Factories;

use App\Models\MikrotikImportCandidate;
use App\Models\MikrotikRouter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MikrotikImportCandidate> */
class MikrotikImportCandidateFactory extends Factory
{
    protected $model = MikrotikImportCandidate::class;

    public function definition(): array
    {
        return [
            'mikrotik_router_id' => MikrotikRouter::factory(),
            'source_type' => MikrotikImportCandidate::SOURCE_PPPOE,
            'identifier' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'status' => MikrotikImportCandidate::STATUS_UNLINKED,
            'last_seen_at' => now(),
        ];
    }
}
