<?php

namespace App\Services\Mikrotik;

use App\Contracts\MikrotikRouterInspector;
use App\Models\MikrotikImportCandidate;
use App\Models\MikrotikRouter;

class RouterOsMikrotikRouterInspector implements MikrotikRouterInspector
{
    public function __construct(private readonly RouterOsApiClient $client) {}

    public function detectControlMethod(MikrotikRouter $router): array
    {
        $records = $this->recordsBySource($router);
        $counts = array_map('count', $records);
        $detectedMethods = array_values(array_keys(array_filter($counts, fn (int $count) => $count > 0)));

        return [
            'counts' => $counts,
            'detected_methods' => $detectedMethods,
            'primary_method' => $this->primaryMethod($counts),
            'inspected_at' => now()->toISOString(),
        ];
    }

    public function importableRecords(MikrotikRouter $router): array
    {
        return collect($this->recordsBySource($router))
            ->flatMap(fn (array $records) => $records)
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function recordsBySource(MikrotikRouter $router): array
    {
        return [
            MikrotikImportCandidate::SOURCE_PPPOE => array_map(
                fn (array $row) => $this->pppoeRecord($row),
                $this->client->read($router, '/ppp/secret', ['.id', 'name', 'profile', 'disabled', 'comment'])
            ),
            MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE => array_map(
                fn (array $row) => $this->simpleQueueRecord($row),
                $this->client->read($router, '/queue/simple', ['.id', 'name', 'target', 'max-limit', 'disabled', 'comment'])
            ),
            MikrotikImportCandidate::SOURCE_DHCP_MAC => array_merge(
                array_map(
                    fn (array $row) => $this->dhcpRecord($row),
                    $this->client->read($router, '/ip/dhcp-server/lease', ['.id', 'mac-address', 'address', 'host-name', 'comment', 'dynamic', 'disabled'])
                ),
                array_map(
                    fn (array $row) => $this->neighborRecord($row),
                    $this->client->read($router, '/ip/neighbor', ['.id', 'mac-address', 'address', 'identity', 'interface', 'platform', 'version', 'board'])
                )
            ),
            MikrotikImportCandidate::SOURCE_HOTSPOT => array_map(
                fn (array $row) => $this->hotspotRecord($row),
                $this->client->read($router, '/ip/hotspot/user', ['.id', 'name', 'profile', 'disabled', 'comment'])
            ),
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function pppoeRecord(array $row): array
    {
        $row = $this->sanitizeRow($row);

        return [
            'source_type' => MikrotikImportCandidate::SOURCE_PPPOE,
            'external_id' => $row['.id'] ?? null,
            'identifier' => $row['name'] ?? '',
            'display_name' => $row['name'] ?? null,
            'profile' => $row['profile'] ?? null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function simpleQueueRecord(array $row): array
    {
        $row = $this->sanitizeRow($row);
        $target = $this->firstTarget($row['target'] ?? null);

        return [
            'source_type' => MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE,
            'external_id' => $row['.id'] ?? null,
            'identifier' => $row['name'] ?? '',
            'display_name' => $row['name'] ?? null,
            'ip_address' => $target,
            'rate_limit' => $row['max-limit'] ?? null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function dhcpRecord(array $row): array
    {
        $row = $this->sanitizeRow($row);
        $identifier = $row['mac-address'] ?? '';

        return [
            'source_type' => MikrotikImportCandidate::SOURCE_DHCP_MAC,
            'external_id' => $row['.id'] ?? null,
            'identifier' => $identifier,
            'display_name' => $row['host-name'] ?? $row['comment'] ?? $identifier,
            'ip_address' => $row['address'] ?? null,
            'mac_address' => $row['mac-address'] ?? null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function neighborRecord(array $row): array
    {
        $row = $this->sanitizeRow($row);
        $identifier = $row['mac-address'] ?? '';
        $identity = $row['identity'] ?? null;
        $address = $row['address'] ?? null;

        return [
            'source_type' => MikrotikImportCandidate::SOURCE_DHCP_MAC,
            'external_id' => $row['.id'] ?? null,
            'identifier' => $identifier,
            'display_name' => $identity ?: $identifier,
            'ip_address' => filter_var($address, FILTER_VALIDATE_IP) ? $address : null,
            'mac_address' => $row['mac-address'] ?? null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function hotspotRecord(array $row): array
    {
        $row = $this->sanitizeRow($row);

        return [
            'source_type' => MikrotikImportCandidate::SOURCE_HOTSPOT,
            'external_id' => $row['.id'] ?? null,
            'identifier' => $row['name'] ?? '',
            'display_name' => $row['name'] ?? null,
            'profile' => $row['profile'] ?? null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function primaryMethod(array $counts): string
    {
        arsort($counts);
        $method = array_key_first($counts);

        return $method && $counts[$method] > 0 ? $method : 'manual';
    }

    private function firstTarget(?string $target): ?string
    {
        if (! $target) {
            return null;
        }

        $first = trim(explode(',', $target)[0]);
        $withoutMask = explode('/', $first)[0];

        return filter_var($withoutMask, FILTER_VALIDATE_IP) ? $withoutMask : null;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function sanitizeRow(array $row): array
    {
        return array_map(function (string $value): string {
            if (mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }

            $converted = mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');

            return mb_check_encoding($converted, 'UTF-8') ? $converted : iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }, $row);
    }
}
