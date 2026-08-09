<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Modules\Company\Models\Company;

class OnboardingChecklistService
{
    /**
     * Required total (informational constant; optional items excluded).
     */
    public const REQUIRED_TOTAL = 1;

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
    /**
     * Build the ordered item list with completed flags derived from live tenant data.
     *
     * The template shipped 8 steps for the condominium setup (towers, units,
     * board, collection parameters, bank account...). Those modules are gone, so
     * only the domain-agnostic step survives. Add one item per EDI setup step as
     * its module lands, and bump REQUIRED_TOTAL to match.
     *
     * @return list<array{id:string,title:string,description:string,details:string,route:string,required:bool,completed:bool,count:int}>
     */
    private function buildItems(): array
    {
        return [
            $this->item(
                id: 'legal_branding',
                title: 'Identidad legal y branding',
                description: 'Configura los datos legales y visuales de tu organización.',
                details: 'Registra la razón social y el RFC para emitir documentos oficiales.',
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
        ];
    }

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
