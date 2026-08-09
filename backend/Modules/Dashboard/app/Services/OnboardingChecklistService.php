<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Collections\Models\ChargeConcept;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Treasury\Models\BankAccount;

class OnboardingChecklistService
{
    /**
     * Required total (informational constant; optional items excluded).
     */
    public const REQUIRED_TOTAL = 8;

    /**
     * Returns the full checklist payload for the given user.
     *
     * Non-admin users receive a short-circuited "complete" response with no
     * items, so the frontend can safely show the dashboard without hitting the
     * individual predicates.
     *
     * @return array{
     *   items: list<array{id:string,title:string,description:string,details:string,route:string,required:bool,completed:bool,count:int}>,
     *   required_total: int,
     *   required_completed: int,
     *   progress_percentage: int,
     *   is_complete: bool
     * }
     */
    public function checklist(User $user): array
    {
        if (! $user->hasAnyRole(['administrator', 'account.owner'])) {
            return [
                'items' => [],
                'required_total' => 0,
                'required_completed' => 0,
                'progress_percentage' => 100,
                'is_complete' => true,
            ];
        }

        $items = $this->buildItems();

        $requiredCompleted = collect($items)
            ->filter(fn (array $item) => $item['required'] && $item['completed'])
            ->count();

        $progressPercentage = self::REQUIRED_TOTAL > 0
            ? (int) round(($requiredCompleted / self::REQUIRED_TOTAL) * 100)
            : 100;

        $isComplete = $requiredCompleted === self::REQUIRED_TOTAL;

        return [
            'items' => array_values($items),
            'required_total' => self::REQUIRED_TOTAL,
            'required_completed' => $requiredCompleted,
            'progress_percentage' => $progressPercentage,
            'is_complete' => $isComplete,
        ];
    }

    /**
     * Build the ordered item list with completed flags derived from live tenant data.
     *
     * @return list<array{id:string,title:string,description:string,details:string,route:string,required:bool,completed:bool,count:int}>
     */
    private function buildItems(): array
    {
        return [
            $this->item(
                id: 'legal_branding',
                title: 'Identidad legal y branding',
                description: 'Configura los datos legales y visuales de tu condominio.',
                details: 'Registra la razón social y el RFC del condominio para emitir recibos y documentos oficiales.',
                route: '/configuration?tab=general',
                required: true,
                completed: Company::query()
                    ->main()
                    ->whereNotNull('legal_name')
                    ->where('legal_name', '!=', '')
                    ->whereNotNull('rfc')
                    ->where('rfc', '!=', '')
                    ->exists(),
            ),
            $this->item(
                id: 'collection_params',
                title: 'Parámetros de cobranza',
                description: 'Define el día de pago y los días de gracia del condominio.',
                details: 'Establece las reglas de cobro para que el sistema calcule vencimientos y recargos correctamente.',
                route: '/configuration?tab=collections',
                required: true,
                completed: Setting::query()
                    ->whereIn('slug', ['collections.charge_generation_day', 'collections.due_soon_days'])
                    ->whereNotNull('value')
                    ->where('value', '!=', '')
                    ->exists(),
            ),
            $this->item(
                id: 'bank_account',
                title: 'Cuenta bancaria',
                description: 'Registra la cuenta donde se depositarán los pagos.',
                details: 'Los pagos de mantenimiento se asignan a una cuenta bancaria o caja del condominio para controlar el saldo.',
                route: '/bank-account',
                required: true,
                completed: BankAccount::query()->exists(),
            ),
            $this->item(
                id: 'charge_concepts',
                title: 'Conceptos de cobro',
                description: 'Crea al menos un concepto de cobro (ej. mantenimiento mensual).',
                details: 'Los conceptos de cobro definen qué se cobra, con qué periodicidad y a qué unidades aplica.',
                route: '/charge-concept',
                required: true,
                // Excludes the seeded technical "OTHER" concept: it exists in EVERY tenant
                // from day one (it only backs the free-text cobro directo path), so counting
                // it would mark this step complete before the admin created a single concept.
                completed: ChargeConcept::query()
                    ->where('code', '!=', ChargeConcept::CODE_OTHER)
                    ->exists(),
            ),
            $this->item(
                id: 'board_treasurer',
                title: 'Mesa directiva con tesorero',
                description: 'Abre un período de mesa directiva y asigna un tesorero.',
                details: 'El tesorero tiene acceso a los módulos de cobranza y tesorería. Se requiere un período activo con este cargo.',
                route: '/board-period',
                required: true,
                completed: BoardPeriod::query()
                    ->where('board_period_status_id', BoardPeriodStatus::CURRENT)
                    ->whereHas('members', fn ($q) => $q->where('board_role_id', BoardRole::TREASURER))
                    ->exists(),
            ),
            $this->item(
                id: 'towers_sections',
                title: 'Torres o secciones',
                description: 'Registra al menos una torre o sección del condominio.',
                details: 'La estructura física del condominio es necesaria para asignar unidades y residentes.',
                route: '/tower',
                required: true,
                completed: Tower::query()->exists() || Section::query()->exists(),
            ),
            $this->item(
                id: 'units',
                title: 'Unidades',
                description: 'Registra las viviendas o departamentos del condominio.',
                details: 'Cada unidad representa una casa o departamento al que se le generarán cargos y se le asignarán residentes.',
                route: '/unit',
                required: true,
                completed: Unit::query()->exists(),
            ),
            $this->item(
                id: 'residents',
                title: 'Residentes',
                description: 'Asigna al menos un residente a una unidad.',
                details: 'Los residentes son los propietarios, arrendatarios o contactos de cobro asociados a cada unidad.',
                route: '/unit-user',
                required: true,
                completed: UnitUser::query()->exists(),
            ),
        ];
    }

    /**
     * Build a single checklist item array.
     *
     * @return array{id:string,title:string,description:string,details:string,route:string,required:bool,completed:bool,count:int}
     */
    private function item(
        string $id,
        string $title,
        string $description,
        string $details,
        string $route,
        bool $required,
        bool $completed,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'details' => $details,
            'route' => $route,
            'required' => $required,
            'completed' => $completed,
            'count' => 0,
        ];
    }
}
