<?php

namespace App\Services;

use InvalidArgumentException;

class ReceiptCalculator
{
    /**
     * @return array{subtotal: float, iva: float, retention: float, total_received: float}
     */
    public function calculate(float|int|string $subtotal, float|int|string $ivaRate = 0, float|int|string $retentionRate = 0): array
    {
        $base = $this->toMoney($subtotal);
        $iva = $this->toMoney($base * ((float) $ivaRate / 100));
        $retention = $this->toMoney($base * ((float) $retentionRate / 100));
        $totalReceived = $this->toMoney($base + $iva - $retention);

        return [
            'subtotal' => $base,
            'iva' => $iva,
            'retention' => $retention,
            'total_received' => $totalReceived,
        ];
    }

    private function toMoney(float|int|string $value): float
    {
        $number = (float) $value;
        if (! is_numeric($value) || $number < 0) {
            throw new InvalidArgumentException('El monto debe ser un numero positivo.');
        }

        return round($number, 2);
    }
}
