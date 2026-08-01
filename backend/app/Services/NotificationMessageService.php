<?php

namespace App\Services;

use App\Models\InternetService;
use App\Models\NotificationTemplate;
use Carbon\CarbonImmutable;

class NotificationMessageService
{
    public function render(NotificationTemplate $template, InternetService $service, ?int $daysRemaining = null): string
    {
        $service->loadMissing(['client', 'plan']);
        $dueDate = $service->next_due_date
            ? CarbonImmutable::parse($service->next_due_date)
            : null;

        $variables = [
            '{nombre}' => $service->client->full_name,
            '{monto}' => number_format((float) ($service->plan->monthly_price ?? 0), 2, '.', ''),
            '{fecha}' => $dueDate?->format('d/m/Y') ?? 'sin fecha registrada',
            '{dias}' => (string) ($daysRemaining ?? ($dueDate ? CarbonImmutable::today()->diffInDays($dueDate, false) : 0)),
            '{instrucciones_pago}' => 'Puedes pagar en oficina o por transferencia y enviar el comprobante por este medio.',
        ];

        return strtr($template->body, $variables);
    }

    public function whatsappUrl(string $phone, string $message): string
    {
        return 'https://wa.me/'.$this->normalizePhone($phone).'?text='.rawurlencode($message);
    }

    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
