{{--
  Official EDI screening document (FUA) — REQ-SCR-015.

  Renders STORED values only. Nothing here computes a colour, a count or a group:
  every value was written when the screening was scored, and printing it again
  months later must yield the same sheet even if the instrument was re-seeded.

  Deliberately a clean official layout, not a pixel replica of the legacy form.
--}}
@php
    /** Colour chip for a stored result colour. */
    $chip = static function ($color) {
        $key = $color?->key;
        $bg = match ($key) {
            'green' => '#1a7f37',
            'yellow' => '#b8860b',
            'red' => '#b42318',
            default => '#6b7280',
        };

        return [$bg, $color?->name ?? 'Sin calificar'];
    };

    [$globalBg, $globalLabel] = $chip($screening->result_color);

    $mm = static fn (?int $v) => $v === null ? '—' : number_format($v / 10, 1, '.', '').' cm';
    $kg = static fn (?int $v) => $v === null ? '—' : number_format($v / 1000, 3, '.', '').' kg';
    $date = static fn ($d) => $d ? $d->format('d/m/Y') : '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Prueba EDI · {{ $screening->visit?->receipt_number }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { color: #6b7280; font-size: 9px; margin: 0; }
        .head { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .band { display: block; margin: 12px 0; padding: 8px 10px; color: #fff; border-radius: 4px; }
        .band .lbl { font-size: 9px; opacity: .85; }
        .band .val { font-size: 14px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { text-align: left; padding: 4px 6px; vertical-align: top; }
        .grid td { border-bottom: 1px solid #e5e7eb; width: 25%; }
        .grid .k { color: #6b7280; font-size: 8px; text-transform: uppercase; letter-spacing: .3px; }
        .areas th { background: #f3f4f6; border-bottom: 1px solid #d1d5db; font-size: 9px; }
        .areas td { border-bottom: 1px solid #e5e7eb; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
        .msg { border: 1px solid #e5e7eb; border-left: 4px solid #9ca3af; padding: 8px 10px; margin-bottom: 12px; }
        .msg h3 { margin: 0 0 4px; font-size: 11px; }
        .msg p { margin: 0; line-height: 1.45; }
        .foot { margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #6b7280; font-size: 8px; }
        .sign { margin-top: 26px; }
        .sign td { text-align: center; padding-top: 26px; border-top: 1px solid #9ca3af; width: 45%; font-size: 9px; }
        .spacer { border: 0 !important; width: 10% !important; }
    </style>
</head>
<body>

<div class="head">
    <h1>Evaluación del Desarrollo Infantil (EDI)</h1>
    <p class="sub">
        Folio {{ $screening->visit?->receipt_number ?? '—' }}
        · {{ $screening->visit?->health_center?->name ?? 'Centro no especificado' }}
        · Aplicado el {{ $date($screening->scored_at) }}
    </p>
</div>

<table class="grid">
    <tr>
        <td><div class="k">Niña / Niño</div>
            {{ trim(($screening->child?->first_name ?? '').' '.($screening->child?->last_name_paternal ?? '').' '.($screening->child?->last_name_maternal ?? '')) ?: '—' }}</td>
        <td><div class="k">CURP</div>{{ $screening->child?->curp ?: '—' }}</td>
        <td><div class="k">Sexo</div>{{ $screening->child?->sex?->name ?? '—' }}</td>
        <td><div class="k">Fecha de nacimiento</div>{{ $date($screening->child?->birth_date) }}</td>
    </tr>
    <tr>
        <td><div class="k">Edad cronológica</div>{{ $screening->chronological_age_days }} días</td>
        <td><div class="k">Edad corregida</div>
            {{ $screening->corrected_age_days !== null ? $screening->corrected_age_days.' días' : 'No aplica' }}</td>
        @php
            $appliedGroup = $screening->applied_age_group;
            $groupMonths = $appliedGroup?->min_age_months !== null && $appliedGroup?->max_age_months !== null
                ? ($appliedGroup->min_age_months === $appliedGroup->max_age_months
                    ? $appliedGroup->min_age_months.' '.($appliedGroup->min_age_months === 1 ? 'mes' : 'meses')
                    : $appliedGroup->min_age_months.' a '.$appliedGroup->max_age_months.' meses')
                : null;
        @endphp
        <td><div class="k">Grupo aplicado</div>
            @if ($appliedGroup)
                Grupo: {{ $appliedGroup->ordinal }} — {{ $appliedGroup->name }}@if ($groupMonths) ({{ $groupMonths }})@endif
            @else
                —
            @endif
            @if ($screening->isAgeGroupChosen())
                <br><strong>Prueba EDI a elección</strong>
            @endif
        </td>
        <td><div class="k">Versión del instrumento</div>{{ $screening->instrument_version?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td><div class="k">Peso</div>{{ $kg($screening->weight_grams) }}</td>
        <td><div class="k">Talla</div>{{ $mm($screening->height_mm) }}</td>
        <td><div class="k">Perímetro cefálico</div>{{ $mm($screening->head_circumference_mm) }}</td>
        <td><div class="k">Situación</div>{{ $screening->situation?->name ?? '—' }}</td>
    </tr>
</table>

@isset($nutritionalAssessment)
<table class="grid">
    <tr>
        <td><div class="k">Estado nutricional · Peso para la talla</div>{{ $nutritionalAssessment->weight_for_height_status?->name ?? '—' }}</td>
        <td><div class="k">Peso para la edad</div>{{ $nutritionalAssessment->weight_for_age_status?->name ?? '—' }}</td>
        <td><div class="k">Talla para la edad</div>{{ $nutritionalAssessment->height_for_age_status?->name ?? '—' }}</td>
    </tr>
</table>
@endisset

<div class="band" style="background: {{ $globalBg }};">
    <div class="lbl">Resultado global de la Prueba EDI</div>
    <div class="val">{{ $globalLabel }}</div>
</div>

<table class="areas">
    <thead>
        <tr>
            <th style="width: 46%;">Área de desarrollo</th>
            <th style="width: 22%;">Resultado</th>
            <th style="width: 32%;">Reactivos (rojo / amarillo / verde)</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($screening->area_results as $area)
        @php [$bg, $label] = $chip($area->result_color); @endphp
        <tr>
            <td>{{ $area->development_area?->name ?? $area->development_area?->code ?? '—' }}</td>
            <td><span class="dot" style="background: {{ $bg }};"></span>{{ $label }}</td>
            <td>{{ $area->red_count }} / {{ $area->yellow_count }} / {{ $area->green_count }}</td>
        </tr>
    @empty
        <tr><td colspan="3">Sin resultados por área registrados.</td></tr>
    @endforelse
    </tbody>
</table>

<table class="grid">
    <tr>
        <td style="width: 50%;"><div class="k">Señales de alerta</div>{{ $screening->alert_sign_count ?? 0 }}</td>
        <td style="width: 50%;"><div class="k">Factores de riesgo biológico</div>{{ $screening->risk_factor_count ?? 0 }}</td>
    </tr>
</table>

@if ($screening->global_result_message)
    <div class="msg" style="border-left-color: {{ $globalBg }};">
        <h3>{{ $screening->global_result_message->title }}</h3>
        <p>{!! nl2br(e($screening->global_result_message->body)) !!}</p>
    </div>
@endif

@if ($screening->notes)
    <div class="msg">
        <h3>Observaciones</h3>
        <p>{!! nl2br(e($screening->notes)) !!}</p>
    </div>
@endif

<table class="sign">
    <tr>
        <td>{{ $screening->visit?->practitioner?->user?->name ?? 'Personal de salud' }}<br>Aplicó la Prueba EDI</td>
        <td class="spacer"></td>
        <td>Madre, padre o tutor<br>Recibí el resultado y las indicaciones</td>
    </tr>
</table>

<div class="foot">
    Documento generado a partir de la Prueba EDI registrada. Refleja los valores almacenados al calificar
    ({{ $date($screening->scored_at) }}) y no vuelve a calcularse.
    Motor {{ $screening->engine_version ?? '—' }}.
</div>

</body>
</html>
