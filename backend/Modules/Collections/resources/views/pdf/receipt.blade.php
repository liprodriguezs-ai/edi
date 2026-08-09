<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $payment->receipt_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; margin: 32px; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .muted { color: #6b7280; font-size: 10px; }
        .header { border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { text-align: left; background: #f3f4f6; padding: 6px 8px; font-size: 11px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .total { font-size: 14px; font-weight: bold; text-align: right; margin-top: 12px; }
        .footer { margin-top: 28px; font-size: 10px; color: #6b7280; }
    </style>
</head>
<body>
@php
    $money = static fn (int $minor): string => \QCH\Core\Support\TenantMoney::format($minor);
    $totalRaw = (int) $payment->getRawOriginal('total_amount');
@endphp

<div class="header">
    <h1>Recibo de pago</h1>
    <div class="muted">Folio digital: {{ $payment->receipt_number }} · Generado: {{ $generatedAt }}</div>
</div>

<table>
    <tr><td><strong>Unidad</strong></td><td>{{ $payment->unit?->number ?? '—' }}</td></tr>
    <tr><td><strong>Fecha de pago</strong></td><td>{{ optional($payment->paid_at)->format('d/m/Y') }}</td></tr>
    <tr><td><strong>Método</strong></td><td>{{ $payment->payment_method?->name ?? '—' }}</td></tr>
    @if ($payment->physical_receipt_number)
        <tr><td><strong>Folio físico</strong></td><td>{{ $payment->physical_receipt_number }}</td></tr>
    @endif
    @if ($payment->reference)
        <tr><td><strong>Referencia</strong></td><td>{{ $payment->reference }}</td></tr>
    @endif
</table>

@if ($payment->applications->isNotEmpty())
    <table>
        <thead>
        <tr>
            <th>Concepto</th>
            <th>Vencimiento</th>
            <th style="text-align: right;">Monto aplicado</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($payment->applications as $application)
            <tr>
                <td>{{ $application->charge?->concept?->name ?? 'Cargo' }}</td>
                <td>{{ optional($application->charge?->due_date)->format('d/m/Y') ?? '—' }}</td>
                <td style="text-align: right;">{{ $money((int) $application->getRawOriginal('applied_amount')) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<p class="total">Total pagado: {{ $money($totalRaw) }}</p>

<p class="footer">
    Este recibo fue generado automáticamente por Kivo7. Conserva este documento como comprobante de tu pago.
</p>
</body>
</html>
