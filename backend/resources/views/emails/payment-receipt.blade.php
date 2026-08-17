<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de pago {{ $receipt->receipt_number }}</title>
</head>
<body style="margin:0;background:#f3f7f5;color:#14201c;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:720px;margin:0 auto;padding:24px;">
        <p style="margin:0 0 14px;color:#66756f;font-size:14px;">Hola {{ $receipt->client_name }},</p>
        <p style="margin:0 0 22px;color:#30453c;font-size:14px;line-height:1.5;">
            Te enviamos el comprobante de pago registrado en nuestro sistema.
        </p>

        <article style="background:#fff;border:2px solid #3b4652;padding:18px;">
            <header style="background:#08b558;color:#fff;text-align:center;font-size:22px;font-weight:800;padding:8px 10px;">
                RECIBO DE PAGO
            </header>
            <section style="display:grid;grid-template-columns:1fr 230px;gap:16px;border:2px solid #3b4652;border-top:0;padding:12px;">
                <div>
                    <strong style="display:block;font-size:18px;color:#1473aa;">SERVI-TEC</strong>
                    <p style="margin:6px 0 0;font-size:13px;">Servicio de telecomunicaciones</p>
                </div>
                <div style="border-left:1px solid #aab2bd;padding-left:12px;font-size:13px;font-weight:700;">
                    <p style="margin:0 0 7px;">Nro. RECIBO: {{ $receipt->receipt_number }}</p>
                    <p style="margin:0 0 7px;">FECHA: {{ optional($receipt->payment_date)->format('d/m/Y') }}</p>
                    <p style="margin:0;">CORTE: {{ optional($receipt->cutoff_date)->format('d/m/Y') ?? '-' }}</p>
                </div>
            </section>

            <div style="display:grid;grid-template-columns:120px 1fr;margin-top:10px;font-size:13px;">
                <strong style="border:1px solid #9aa8b6;border-right:0;background:#edf2f7;padding:8px;text-align:center;">RECIBI DE:</strong>
                <span style="border:1px solid #9aa8b6;padding:8px;">{{ $receipt->client_name }}</span>
            </div>
            <div style="display:grid;grid-template-columns:120px 1fr 130px;margin-top:7px;font-size:13px;">
                <strong style="border:1px solid #9aa8b6;border-right:0;background:#edf2f7;padding:8px;text-align:center;">LA SUMA DE:</strong>
                <span style="border:1px solid #9aa8b6;border-right:0;padding:8px;">{{ $receipt->amount_words }}</span>
                <b style="border:1px solid #d7c15b;background:#ffe77a;padding:8px;text-align:right;">{{ number_format((float) $receipt->total_received, 2, ',', '.') }} Bs</b>
            </div>

            <table style="width:100%;margin-top:12px;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr>
                        <th style="border:1px solid #3b4652;background:#edf2f7;padding:8px;">CONCEPTO</th>
                        <th style="border:1px solid #3b4652;background:#edf2f7;padding:8px;">PERIODO A CANCELAR SERVICIO</th>
                        <th style="border:1px solid #3b4652;background:#edf2f7;padding:8px;">MONTO</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border:1px solid #3b4652;padding:8px;">{{ $receipt->concept }}</td>
                        <td style="border:1px solid #3b4652;padding:8px;text-align:center;">{{ $receipt->billing_period ?: ($receipt->observations ?: '-') }}</td>
                        <td style="border:1px solid #3b4652;padding:8px;text-align:right;">{{ number_format((float) $receipt->total_received, 2, ',', '.') }} Bs</td>
                    </tr>
                </tbody>
            </table>
        </article>

        <p style="margin:18px 0 0;color:#66756f;font-size:12px;line-height:1.5;">
            Este correo fue generado automaticamente por SistemaISP. Si tienes dudas sobre el pago, responde al canal de atencion de la empresa.
        </p>
    </div>
</body>
</html>
