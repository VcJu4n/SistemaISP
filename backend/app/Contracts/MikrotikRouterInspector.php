<?php

namespace App\Contracts;

use App\Models\MikrotikRouter;

interface MikrotikRouterInspector
{
    /**
     * @return array{counts: array<string, int>, detected_methods: list<string>, primary_method: string, inspected_at: string}
     */
    public function detectControlMethod(MikrotikRouter $router): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function importableRecords(MikrotikRouter $router): array;
}
