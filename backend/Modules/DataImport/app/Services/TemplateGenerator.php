<?php

namespace Modules\DataImport\Services;

use Maatwebsite\Excel\Facades\Excel;
use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Treasury\Models\BankAccount;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TemplateGenerator — builds the .xlsx import template for the authenticated tenant.
 *
 * Sheets produced:
 *  1. PAGOS           — fillable column headers (plan §3)
 *  2. Tipos de Unidad — tenant unit types reference
 *  3. Estados de Unidad — tenant unit statuses reference
 *  4. Cuentas Bancarias — tenant bank accounts reference
 *  5. Métodos de Pago — central payment methods catalog
 *  6. LÉEME           — import manual (plan §6)
 */
class TemplateGenerator
{
    /**
     * All column headers for the PAGOS sheet in the order defined in plan §3.
     * Public so TemplateWorkbook can reference them.
     *
     * @var array<int, string>
     */
    public const PAGOS_HEADERS_PUBLIC = [
        'tipo_unidad',
        'numero',
        'torre',
        'piso',
        'seccion',
        'estado_unidad',
        'm2_construido',
        'm2_terreno',
        'nombre',
        'apellido_paterno',
        'apellido_materno',
        'email',
        'telefono',
        'relacion',
        'titular_de_pago',
        'rfc',
        'curp',
        'fecha_nacimiento',
        'fecha_pago',
        'monto',
        'metodo_pago',
        'cuenta_destino',
        'referencia',
        'folio',
        'notas',
    ];

    /**
     * The import manual text (plan §6) displayed in the LÉEME sheet.
     * Public so TemplateWorkbook can reference them.
     *
     * @var array<int, array<int, string>>
     */
    public const LEEME_ROWS_PUBLIC = [
        ['1. Catálogos primero (en la app)', 'Recomendamos registrar antes los catálogos (tipos y estados de unidad, torres, métodos de pago, cuentas). Al importar: un tipo de unidad o una cuenta destino que no existan se crean automáticamente (con aviso); una torre o sección no registrada NO se crea — la unidad se importa sin ese dato; un estado de unidad no registrado usa el estado por defecto del condominio (y si no tienes ninguno, se crea "Activa").'],
        ['2. Una fila = un pago', 'Cada fila trae los datos del pago, más los de la unidad y el residente al que pertenece. No hace falta cargar unidades aparte: si la unidad no existe, la creamos.'],
        ['3. Obligatorios', 'Unidad: tipo + número. Residente: nombre + apellido paterno. Pago: fecha + monto. El resto es opcional.'],
        ['4. Avisos vs errores', 'Si algo referenciado no está registrado (un tipo, una cuenta), te lo marcamos en amarillo y seguimos. Solo se frena una fila (rojo) si falta un obligatorio o un dato está mal escrito.'],
        ['5. El pago se aplica solo', 'Lo aplicamos a los cargos pendientes de la unidad (más viejo primero); si sobra, queda como saldo a favor.'],
        ['6. Vuelve a subirlo cuantas veces quieras', 'No se duplica. Un pago ya importado se detecta y se omite automáticamente.'],
    ];

    /**
     * Generate and return a download response for the template .xlsx.
     */
    public function download(): StreamedResponse|BinaryFileResponse
    {
        $workbook = new TemplateWorkbook(
            unitTypes: $this->loadUnitTypes(),
            unitStatuses: $this->loadUnitStatuses(),
            bankAccounts: $this->loadBankAccounts(),
            paymentMethods: $this->loadPaymentMethods(),
        );

        return Excel::download($workbook, 'template-importacion-pagos.xlsx');
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function loadUnitTypes(): array
    {
        return UnitType::query()
            ->where('tenant_id', tenant('id'))
            ->orderBy('order')
            ->orderBy('name')
            ->get(['name'])
            ->map(fn ($t) => [$t->name])
            ->toArray();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function loadUnitStatuses(): array
    {
        return UnitStatus::query()
            ->where('tenant_id', tenant('id'))
            ->orderBy('name')
            ->get(['name'])
            ->map(fn ($s) => [$s->name])
            ->toArray();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function loadBankAccounts(): array
    {
        return BankAccount::query()
            ->where('tenant_id', tenant('id'))
            ->orderBy('name')
            ->get(['name'])
            ->map(fn ($b) => [$b->name])
            ->toArray();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function loadPaymentMethods(): array
    {
        return PaymentMethod::query()
            ->orderBy('name')
            ->get(['name'])
            ->map(fn ($m) => [$m->name])
            ->toArray();
    }
}
