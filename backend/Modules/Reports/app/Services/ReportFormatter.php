<?php

namespace Modules\Reports\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Cknow\Money\Money;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Company\Models\Company;
use Modules\Reports\Exports\ReportExport;
use QCH\Core\Support\TenantMoney;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportFormatter — converts aggregated report data into PDF or Excel responses.
 *
 * Keeps formatting logic out of the controller and the data service.
 */
class ReportFormatter
{
    /**
     * Return a PDF response for the given report data.
     *
     * @param  array<string, mixed>  $reportData
     */
    public function pdf(string $key, array $reportData): Response
    {
        $folio = strtoupper(Str::random(8));
        $content = $this->pdfContent($key, $reportData, $folio);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="reporte_'.$key.'_'.$folio.'.pdf"',
        ]);
    }

    /**
     * Build raw PDF bytes for storage (used by the async job).
     *
     * @param  array<string, mixed>  $reportData
     */
    public function pdfContent(string $key, array $reportData, ?string $folio = null): string
    {
        [$headers, $rows, $summary] = $this->buildTable($key, $reportData);

        $clusterName = $this->resolveClusterName();
        $folio = $folio ?? $this->buildFolio($key);
        $generatedAt = now()->format('d/m/Y H:i');
        $reportTitle = $this->humanTitle($key);
        $logoData = $this->resolveClusterLogo();

        $pdf = Pdf::loadView('reports::pdf.report', [
            'reportTitle' => $reportTitle,
            'clusterName' => $clusterName,
            'folio' => $folio,
            'generatedAt' => $generatedAt,
            'tableHeaders' => $headers,
            'tableRows' => $rows,
            'summaryRows' => $summary,
            'logoData' => $logoData,
            'watermark' => 'OFICIAL · '.($clusterName ?? 'Kivo7'),
        ]);

        return $pdf->output();
    }

    /**
     * Expose table data for job-based Excel exports.
     *
     * @param  array<string, mixed>  $reportData
     * @return array{0: array<string>, 1: array<array<mixed>>}
     */
    public function tableData(string $key, array $reportData): array
    {
        [$headers, $rows] = $this->buildTable($key, $reportData);

        return [$headers, $rows];
    }

    /**
     * Return an Excel (xlsx) response for the given report data.
     *
     * @param  array<string, mixed>  $reportData
     */
    public function excel(string $key, array $reportData): BinaryFileResponse|StreamedResponse
    {
        [$headers, $rows] = $this->buildTable($key, $reportData);

        $export = new ReportExport($rows, $headers, $this->humanTitle($key));
        $filename = "reporte_{$key}_".now()->format('Ymd_His').'.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * Return a CSV response for the given report data (§15.4.2).
     *
     * @param  array<string, mixed>  $reportData
     */
    public function csv(string $key, array $reportData): BinaryFileResponse|StreamedResponse
    {
        [$headers, $rows] = $this->buildTable($key, $reportData);

        $export = new ReportExport($rows, $headers, $this->humanTitle($key));
        $filename = "reporte_{$key}_".now()->format('Ymd_His').'.csv';

        return Excel::download($export, $filename, ExcelFormat::CSV, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Build table headers + rows from report data keyed by report type.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildTable(string $key, array $data): array
    {
        return match ($key) {
            'delinquency' => $this->buildDelinquency($data),
            'income_statement' => $this->buildIncomeStatement($data),
            'account_balances' => $this->buildAccountBalances($data),
            'collection_progress' => $this->buildCollectionProgress($data),
            'cash_flow' => $this->buildCashFlow($data),
            'expenses_by_category' => $this->buildExpensesByCategory($data),
            'annual_fiscal' => $this->buildAnnualFiscal($data),
            'unit_statement' => $this->buildUnitStatement($data),
            'global_statement' => $this->buildGlobalStatement($data),
            'payment_history' => $this->buildPaymentHistory($data),
            'credit_balances' => $this->buildCreditBalances($data),
            'access_log_report' => $this->buildAccessLogReport($data),
            'staff_report' => $this->buildStaffReport($data),
            'provider_history' => $this->buildProviderHistory($data),
            'project_progress' => $this->buildProjectProgress($data),
            'resident_directory' => $this->buildResidentDirectory($data),
            'active_board' => $this->buildActiveBoard($data),
            'announcements_sent' => $this->buildAnnouncementsSent($data),
            'audit_log' => $this->buildAuditLog($data),
            default => [[], [], []],
        };
    }

    /**
     * A04 — Bitácora de auditoría (reports-03). Rows come from DB::table('audits')
     * as stdClass objects.
     *
     * @return array{0: array<string>, 1: array<array<string>>, 2: array<string>}
     */
    private function buildAuditLog(array $data): array
    {
        $headers = ['Fecha', 'Usuario', 'Evento', 'Entidad', 'ID registro'];

        $rows = collect($data['data'] ?? [])->map(function ($audit): array {
            $audit = (object) $audit;

            return [
                (string) ($audit->created_at ?? ''),
                (string) ($audit->user_id ?? 'Sistema'),
                (string) ($audit->event ?? ''),
                class_basename((string) ($audit->auditable_type ?? '')),
                (string) ($audit->auditable_id ?? ''),
            ];
        })->all();

        return [$headers, $rows, []];
    }

    /**
     * Normaliza un monto a minor units: tolera el cast Cknow\Money\Money,
     * su forma serializada (array con amount) y escalares crudos.
     */
    private function minor(mixed $value): int
    {
        if ($value instanceof Money) {
            return (int) $value->getMoney()->getAmount();
        }

        if (is_array($value) && isset($value['amount'])) {
            return (int) $value['amount'];
        }

        return (int) ($value ?? 0);
    }

    private function money(mixed $minor): string
    {
        return number_format($this->minor($minor) / 100, 2);
    }

    private function moneyWithCurrency(mixed $minor): string
    {
        return TenantMoney::format($this->minor($minor));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildDelinquency(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Unidad', 'Cargos vencidos', 'Monto vencido ('.$currency.')', 'Más antiguo'];
        $rows = [];

        foreach ($data['units'] ?? [] as $unit) {
            $rows[] = [
                $unit['unit_full_identifier'] ?? $unit['unit_number'] ?? '—',
                $unit['overdue_charges'] ?? 0,
                number_format($this->minor($unit['total_overdue_amount'] ?? 0) / 100, 2),
                $unit['oldest_due_date'] ?? '—',
            ];
        }

        $summary = [
            'Total unidades morosas' => (string) ($data['unit_count'] ?? 0),
            'Monto total vencido' => $this->moneyWithCurrency($data['total_overdue_amount'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildIncomeStatement(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Tipo', 'Descripción', 'Fecha', 'Monto ('.$currency.')'];
        $rows = [];

        foreach ($data['payments'] ?? [] as $payment) {
            $rows[] = [
                'Ingreso',
                $payment['receipt_number'] ?? '—',
                $payment['paid_at'] ?? '—',
                number_format($this->minor($payment['total_amount'] ?? 0) / 100, 2),
            ];
        }

        foreach ($data['expenses'] ?? [] as $expense) {
            $rows[] = [
                'Egreso',
                $expense['concept'] ?? '—',
                $expense['paid_at'] ?? '—',
                '-'.number_format($this->minor($expense['amount'] ?? 0) / 100, 2),
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Ingresos totales' => $this->moneyWithCurrency($data['total_income'] ?? 0),
            'Egresos totales' => $this->moneyWithCurrency($data['total_expenses'] ?? 0),
            'Resultado neto' => $this->moneyWithCurrency($data['net'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildAccountBalances(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Cuenta', 'Tipo', 'Propósito', 'Moneda', 'Saldo inicial ('.$currency.')'];
        $rows = [];

        foreach ($data['data'] ?? [] as $account) {
            $rows[] = [
                $account['name'] ?? '—',
                $account['type']['name'] ?? '—',
                $account['purpose']['name'] ?? '—',
                $account['currency'] ?? '—',
                number_format($this->minor($account['initial_balance'] ?? 0) / 100, 2),
            ];
        }

        return [$headers, $rows, []];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildCollectionProgress(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Métrica', 'Valor'];
        $rows = [
            ['Total de cargos', $data['total_charges'] ?? 0],
            ['Pagados', $data['paid_count'] ?? 0],
            ['Vencidos', $data['overdue_count'] ?? 0],
            ['Pendientes', $data['pending_count'] ?? 0],
            ['Avance (%)', number_format($data['progress_pct'] ?? 0, 2).'%'],
            ['Total esperado ('.$currency.')', $this->moneyWithCurrency($data['total_expected'] ?? 0)],
            ['Total recaudado ('.$currency.')', $this->moneyWithCurrency($data['total_collected'] ?? 0)],
        ];

        $summary = [
            'Avance de cobranza' => number_format($data['progress_pct'] ?? 0, 2).'%',
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildCashFlow(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Período', 'Ingresos ('.$currency.')', 'Egresos ('.$currency.')', 'Neto ('.$currency.')', 'Saldo acumulado ('.$currency.')'];
        $rows = [];

        foreach ($data['buckets'] ?? [] as $bucket) {
            $rows[] = [
                $bucket['period'] ?? '—',
                $this->money($bucket['income'] ?? 0),
                $this->money($bucket['expenses'] ?? 0),
                $this->money($bucket['net'] ?? 0),
                $this->money($bucket['running_balance'] ?? 0),
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Ingresos totales' => $this->moneyWithCurrency($data['total_income'] ?? 0),
            'Egresos totales' => $this->moneyWithCurrency($data['total_expenses'] ?? 0),
            'Flujo neto' => $this->moneyWithCurrency($data['net'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildExpensesByCategory(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Categoría', 'N° egresos', 'Total ('.$currency.')'];
        $rows = [];

        foreach ($data['categories'] ?? [] as $cat) {
            $rows[] = [
                $cat['category_name'] ?? '—',
                $cat['expense_count'] ?? 0,
                $this->money($cat['total_amount'] ?? 0),
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Egresos totales' => $this->moneyWithCurrency($data['total_expenses'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildAnnualFiscal(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Categoría de egreso', 'N° egresos', 'Total ('.$currency.')'];
        $rows = [];

        foreach ($data['categories'] ?? [] as $cat) {
            $rows[] = [
                $cat['category_name'] ?? '—',
                $cat['expense_count'] ?? 0,
                $this->money($cat['total_amount'] ?? 0),
            ];
        }

        $summary = [
            'Año fiscal' => (string) ($data['year'] ?? ''),
            'Ingresos del año' => $this->moneyWithCurrency($data['total_income'] ?? 0),
            'Egresos del año' => $this->moneyWithCurrency($data['total_expenses'] ?? 0),
            'Resultado neto' => $this->moneyWithCurrency($data['net'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildUnitStatement(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Tipo', 'Concepto / Folio', 'Fecha', 'Esperado/Pagado ('.$currency.')', 'Saldo/Estado'];
        $rows = [];

        foreach ($data['charges'] ?? [] as $charge) {
            $rows[] = [
                'Cargo',
                $charge['concept'] ?? '—',
                $charge['due_date'] ?? '—',
                $this->money($charge['expected_amount'] ?? 0).' / '.$this->money($charge['paid_amount'] ?? 0),
                $this->money($charge['outstanding'] ?? 0).' · '.($charge['status'] ?? '—'),
            ];
        }

        foreach ($data['payments'] ?? [] as $payment) {
            $rows[] = [
                'Pago',
                $payment['receipt_number'] ?? '—',
                $payment['paid_at'] ?? '—',
                $this->money($payment['total_amount'] ?? 0),
                ($payment['method'] ?? '—').' · '.($payment['status'] ?? '—'),
            ];
        }

        $summary = [
            'Unidad' => (string) ($data['unit_number'] ?? '—'),
            'Total esperado' => $this->moneyWithCurrency($data['total_expected'] ?? 0),
            'Total pagado' => $this->moneyWithCurrency($data['total_paid'] ?? 0),
            'Saldo pendiente' => $this->moneyWithCurrency($data['total_outstanding'] ?? 0),
            'Saldo a favor' => $this->moneyWithCurrency($data['credit_balance'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildGlobalStatement(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Unidad', 'Esperado ('.$currency.')', 'Pagado ('.$currency.')', 'Adeudo ('.$currency.')', 'Estado'];
        $rows = [];

        foreach ($data['units'] ?? [] as $unit) {
            $rows[] = [
                $unit['unit_number'] ?? '—',
                $this->money($unit['total_expected'] ?? 0),
                $this->money($unit['total_paid'] ?? 0),
                $this->money($unit['total_outstanding'] ?? 0),
                $unit['status'] ?? '—',
            ];
        }

        $summary = [
            'Unidades' => (string) ($data['unit_count'] ?? 0),
            'Total esperado' => $this->moneyWithCurrency($data['total_expected'] ?? 0),
            'Total pagado' => $this->moneyWithCurrency($data['total_paid'] ?? 0),
            'Adeudo total' => $this->moneyWithCurrency($data['total_outstanding'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildPaymentHistory(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Unidad', 'Fecha', 'Folio', 'Método', 'Monto ('.$currency.')', 'Estado'];
        $rows = [];

        foreach ($data['payments'] ?? [] as $payment) {
            $rows[] = [
                $payment['unit_number'] ?? '—',
                $payment['paid_at'] ?? '—',
                $payment['receipt_number'] ?? '—',
                $payment['method'] ?? '—',
                $this->money($payment['total_amount'] ?? 0),
                $payment['status'] ?? '—',
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Pagos' => (string) ($data['payment_count'] ?? 0),
            'Monto total' => $this->moneyWithCurrency($data['total_amount'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildCreditBalances(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Unidad', 'Saldo a favor ('.$currency.')'];
        $rows = [];

        foreach ($data['units'] ?? [] as $unit) {
            $rows[] = [
                $unit['unit_number'] ?? '—',
                $this->money($unit['credit_balance'] ?? 0),
            ];
        }

        $summary = [
            'Unidades con saldo a favor' => (string) ($data['unit_count'] ?? 0),
            'Saldo a favor total' => $this->moneyWithCurrency($data['total_credit'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildAccessLogReport(array $data): array
    {
        $headers = ['Visitante', 'Unidad', 'Entrada', 'Salida', 'Vigilante'];
        $rows = [];

        foreach ($data['visits'] ?? [] as $visit) {
            $rows[] = [
                $visit['visitor_name'] ?? '—',
                $visit['unit_number'] ?? '—',
                $visit['entered_at'] ?? '—',
                $visit['exited_at'] ?? '—',
                $visit['guard'] ?? '—',
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Total de visitas' => (string) ($data['visit_count'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildStaffReport(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Empleado', 'Puesto', 'Salario base ('.$currency.')', 'Turno'];
        $rows = [];

        foreach ($data['employees'] ?? [] as $employee) {
            $rows[] = [
                $employee['name'] ?? '—',
                $employee['position'] ?? '—',
                $this->money($employee['base_salary'] ?? 0),
                $employee['shift'] ?? '—',
            ];
        }

        $summary = [
            'Empleados activos' => (string) ($data['employee_count'] ?? 0),
            'Nómina total' => $this->moneyWithCurrency($data['total_payroll'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildProviderHistory(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Proveedor', 'N° pagos', 'Total pagado ('.$currency.')'];
        $rows = [];

        foreach ($data['providers'] ?? [] as $provider) {
            $rows[] = [
                $provider['provider_name'] ?? '—',
                $provider['payment_count'] ?? 0,
                $this->money($provider['total_paid'] ?? 0),
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Total pagado a proveedores' => $this->moneyWithCurrency($data['total_paid'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildProjectProgress(array $data): array
    {
        $currency = TenantMoney::currency();
        $headers = ['Proyecto', 'Estado', 'Meta ('.$currency.')', 'Recaudado ('.$currency.')', 'Disponible ('.$currency.')', 'Avance', 'Aportaciones'];
        $rows = [];

        foreach ($data['projects'] ?? [] as $project) {
            $rows[] = [
                $project['title'] ?? '—',
                $project['status'] ?? '—',
                $this->money($project['goal_amount'] ?? 0),
                $this->money($project['raised_amount'] ?? 0),
                $this->money($project['available_amount'] ?? 0),
                number_format($project['progress_pct'] ?? 0, 2).'%',
                $project['contribution_count'] ?? 0,
            ];
        }

        $summary = [
            'Proyectos' => (string) ($data['project_count'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildResidentDirectory(array $data): array
    {
        $headers = ['Unidad', 'Nombre', 'Relación', 'Teléfono', 'Email'];
        $rows = [];

        foreach ($data['residents'] ?? [] as $resident) {
            $rows[] = [
                $resident['unit_number'] ?? '—',
                $resident['name'] ?? '—',
                $resident['relationship'] ?? '—',
                $resident['phone'] ?? '—',
                $resident['email'] ?? '—',
            ];
        }

        $summary = [
            'Total de residentes' => (string) ($data['resident_count'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildActiveBoard(array $data): array
    {
        $headers = ['Nombre', 'Cargo', 'Unidad'];
        $rows = [];

        foreach ($data['members'] ?? [] as $member) {
            $rows[] = [
                $member['name'] ?? '—',
                $member['role'] ?? '—',
                $member['unit'] ?? '—',
            ];
        }

        $summary = [
            'Período' => $data['period'] ?? 'Sin mesa activa',
            'Integrantes' => (string) ($data['member_count'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string>, 1: array<array<mixed>>, 2: array<string, string>}
     */
    private function buildAnnouncementsSent(array $data): array
    {
        $headers = ['Título', 'Tipo', 'Prioridad', 'Canales', 'Lecturas', 'Publicado'];
        $rows = [];

        foreach ($data['announcements'] ?? [] as $announcement) {
            $rows[] = [
                $announcement['title'] ?? '—',
                $announcement['type'] ?? '—',
                $announcement['priority'] ?? '—',
                $announcement['channels'] ?? '—',
                $announcement['read_count'] ?? 0,
                $announcement['published_at'] ?? '—',
            ];
        }

        $summary = [
            'Período' => ($data['period_start'] ?? '').' – '.($data['period_end'] ?? ''),
            'Avisos publicados' => (string) ($data['announcement_count'] ?? 0),
        ];

        return [$headers, $rows, $summary];
    }

    public function humanTitle(string $key): string
    {
        return match ($key) {
            'delinquency' => 'Reporte de Morosidad',
            'income_statement' => 'Estado de Resultados',
            'account_balances' => 'Saldos de Cuentas',
            'collection_progress' => 'Avance de Cobranza',
            'audit_log' => 'Bitácora de Auditoría',
            'cash_flow' => 'Flujo de Efectivo',
            'expenses_by_category' => 'Egresos por Categoría',
            'annual_fiscal' => 'Reporte Fiscal Anual',
            'unit_statement' => 'Estado de Cuenta de Unidad',
            'global_statement' => 'Estado de Cuenta Global',
            'payment_history' => 'Histórico de Pagos',
            'credit_balances' => 'Saldos a Favor',
            'access_log_report' => 'Bitácora de Accesos',
            'staff_report' => 'Reporte de Personal',
            'provider_history' => 'Histórico de Proveedores',
            'project_progress' => 'Avance de Proyectos',
            'resident_directory' => 'Directorio de Residentes',
            'active_board' => 'Mesa Directiva Activa',
            'announcements_sent' => 'Comunicación Enviada',
            default => 'Reporte',
        };
    }

    private function resolveClusterName(): ?string
    {
        try {
            return Company::query()->main()->value('name');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a sequential-ish folio: REP-YYYYMMDD-XXXX (date + random suffix).
     */
    private function buildFolio(string $key): string
    {
        return 'REP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }

    /**
     * Resolve the main company logo as a base64 data URI for embedding in the PDF.
     * Returns null when no logo is set (PDF still renders without it).
     */
    private function resolveClusterLogo(): ?string
    {
        try {
            $company = Company::query()->main()->first();

            if (! $company) {
                return null;
            }

            $path = $company->getFirstMediaPath('logo');

            if (! $path || ! is_file($path)) {
                return null;
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                return null;
            }

            $mime = mime_content_type($path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return null;
        }
    }
}
