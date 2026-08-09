<?php

namespace Modules\DataImport\Services;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * TemplateWorkbook — composes all sheets for the payment import template.
 *
 * Sheets produced (in order):
 *  1. PAGOS           — fillable columns for payment rows
 *  2. Tipos de Unidad — tenant unit types
 *  3. Estados de Unidad — tenant unit statuses
 *  4. Cuentas Bancarias — tenant bank accounts
 *  5. Métodos de Pago — central payment methods catalog
 *  6. LÉEME           — import manual (plan §6)
 */
class TemplateWorkbook implements WithMultipleSheets
{
    /**
     * @param  array<int, array<int, string>>  $unitTypes
     * @param  array<int, array<int, string>>  $unitStatuses
     * @param  array<int, array<int, string>>  $bankAccounts
     * @param  array<int, array<int, string>>  $paymentMethods
     */
    public function __construct(
        private readonly array $unitTypes,
        private readonly array $unitStatuses,
        private readonly array $bankAccounts,
        private readonly array $paymentMethods,
    ) {}

    /**
     * @return array<int, TemplateSheet>
     */
    public function sheets(): array
    {
        return [
            new TemplateSheet('PAGOS', TemplateGenerator::PAGOS_HEADERS_PUBLIC, []),
            new TemplateSheet('Tipos de Unidad', ['nombre'], $this->unitTypes),
            new TemplateSheet('Estados de Unidad', ['nombre'], $this->unitStatuses),
            new TemplateSheet('Cuentas Bancarias', ['nombre'], $this->bankAccounts),
            new TemplateSheet('Métodos de Pago', ['nombre'], $this->paymentMethods),
            new TemplateSheet('LÉEME', ['Punto', 'Instrucción'], TemplateGenerator::LEEME_ROWS_PUBLIC),
        ];
    }
}
