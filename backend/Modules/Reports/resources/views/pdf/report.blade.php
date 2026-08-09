<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $reportTitle }}</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #1a56db;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        .header h1 {
            font-size: 18px;
            color: #1a56db;
            margin: 0 0 4px 0;
        }
        .header .meta {
            font-size: 10px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        th {
            background-color: #1a56db;
            color: #fff;
            padding: 6px 8px;
            text-align: left;
            font-size: 10px;
        }
        td {
            padding: 5px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }
        tr:nth-child(even) td {
            background-color: #f9fafb;
        }
        .summary {
            margin-top: 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            padding: 10px 14px;
        }
        .summary .row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
        }
        .footer {
            margin-top: 24px;
            font-size: 9px;
            color: #999;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }
        .header-top {
            display: table;
            width: 100%;
        }
        .header-logo {
            display: table-cell;
            width: 64px;
            vertical-align: middle;
        }
        .header-logo img {
            max-width: 56px;
            max-height: 56px;
        }
        .header-text {
            display: table-cell;
            vertical-align: middle;
        }
        .badge-official {
            display: inline-block;
            font-size: 9px;
            font-weight: bold;
            color: #1a56db;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 3px;
            padding: 2px 6px;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .watermark {
            position: fixed;
            top: 45%;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 64px;
            color: #1a56db;
            opacity: 0.05;
            transform: rotate(-30deg);
            z-index: -1;
        }
    </style>
</head>
<body>
    @if (!empty($watermark))
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <div class="header">
        <div class="header-top">
            @if (!empty($logoData))
                <div class="header-logo">
                    <img src="{{ $logoData }}" alt="logo" />
                </div>
            @endif
            <div class="header-text">
                <div class="badge-official">OFICIAL · {{ $clusterName ?? 'Kivo7' }}</div>
                <h1>{{ $reportTitle }}</h1>
                <div class="meta">
                    @if ($clusterName)
                        Condominio: <strong>{{ $clusterName }}</strong> &nbsp;|&nbsp;
                    @endif
                    Folio: <strong>{{ $folio }}</strong> &nbsp;|&nbsp;
                    Generado: <strong>{{ $generatedAt }}</strong>
                </div>
            </div>
        </div>
    </div>

    @if (!empty($summaryRows))
        <div class="summary">
            @foreach ($summaryRows as $label => $value)
                <div class="row">
                    <span>{{ $label }}</span>
                    <strong>{{ $value }}</strong>
                </div>
            @endforeach
        </div>
    @endif

    @if (!empty($tableHeaders) && !empty($tableRows))
        <table>
            <thead>
                <tr>
                    @foreach ($tableHeaders as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($tableRows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Kivo7 — Reporte generado automáticamente. Total de registros: {{ count($tableRows ?? []) }}
    </div>
</body>
</html>
