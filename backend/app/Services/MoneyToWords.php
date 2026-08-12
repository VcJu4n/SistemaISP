<?php

namespace App\Services;

class MoneyToWords
{
    private const UNITS = [
        '', 'Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete', 'Ocho', 'Nueve',
        'Diez', 'Once', 'Doce', 'Trece', 'Catorce', 'Quince', 'Dieciseis', 'Diecisiete',
        'Dieciocho', 'Diecinueve', 'Veinte',
    ];

    private const TENS = [
        30 => 'Treinta',
        40 => 'Cuarenta',
        50 => 'Cincuenta',
        60 => 'Sesenta',
        70 => 'Setenta',
        80 => 'Ochenta',
        90 => 'Noventa',
    ];

    private const HUNDREDS = [
        100 => 'Cien',
        200 => 'Doscientos',
        300 => 'Trescientos',
        400 => 'Cuatrocientos',
        500 => 'Quinientos',
        600 => 'Seiscientos',
        700 => 'Setecientos',
        800 => 'Ochocientos',
        900 => 'Novecientos',
    ];

    public function convert(float|int|string $amount, string $currency = 'Bolivianos'): string
    {
        $fixed = number_format((float) $amount, 2, '.', '');
        [$whole, $cents] = explode('.', $fixed);
        $formattedAmount = str_replace('.', ',', $fixed);

        return "{$this->numberToWords((int) $whole)} {$currency} {$cents}/100 ({$formattedAmount})";
    }

    private function numberToWords(int $value): string
    {
        if ($value === 0) {
            return 'Cero';
        }
        if ($value <= 20) {
            return self::UNITS[$value];
        }
        if ($value < 30) {
            return 'Veinti'.strtolower(self::UNITS[$value - 20]);
        }
        if ($value < 100) {
            $ten = intdiv($value, 10) * 10;
            $unit = $value % 10;

            return $unit === 0 ? self::TENS[$ten] : self::TENS[$ten].' y '.self::UNITS[$unit];
        }
        if ($value < 200) {
            return 'Ciento '.$this->numberToWords($value - 100);
        }
        if ($value < 1000) {
            $hundred = intdiv($value, 100) * 100;
            $rest = $value % 100;

            return $rest === 0 ? self::HUNDREDS[$hundred] : self::HUNDREDS[$hundred].' '.$this->numberToWords($rest);
        }
        if ($value < 2000) {
            $rest = $value - 1000;

            return $rest === 0 ? 'Mil' : 'Mil '.$this->numberToWords($rest);
        }
        if ($value < 1000000) {
            $thousands = intdiv($value, 1000);
            $rest = $value % 1000;
            $prefix = $this->numberToWords($thousands).' Mil';

            return $rest === 0 ? $prefix : $prefix.' '.$this->numberToWords($rest);
        }

        $millions = intdiv($value, 1000000);
        $rest = $value % 1000000;
        $prefix = $millions === 1 ? 'Un Millon' : $this->numberToWords($millions).' Millones';

        return $rest === 0 ? $prefix : $prefix.' '.$this->numberToWords($rest);
    }
}
