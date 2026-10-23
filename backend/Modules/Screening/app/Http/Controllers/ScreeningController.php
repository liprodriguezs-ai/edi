<?php

namespace Modules\Screening\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Exceptions\ScreeningDomainException;
use Modules\Screening\Http\Requests\ScreeningAnswerRequest;
use Modules\Screening\Http\Requests\ScreeningCreateRequest;
use Modules\Screening\Http\Requests\ScreeningInvalidateRequest;
use Modules\Screening\Http\Requests\ScreeningRescoreRequest;
use Modules\Screening\Http\Requests\ScreeningUpdateRequest;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Services\AgeGroupCatalog;
use Modules\Screening\Services\AlternateRequirementService;
use Modules\Screening\Services\CorrectedAgeCalculator;
use Modules\Screening\Services\ScreeningAnswerService;
use Modules\Screening\Services\ScreeningApplicationService;
use Modules\Screening\Services\ScreeningLifecycleService;
use Modules\Screening\Services\ScreeningScoreService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Support\TenantTime;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orion serves index/show/update (somatometry + notes only, REQ-SCR-003).
 * Every other action (create, item bank, answers, score, lifecycle) is a
 * custom method registered directly in routes/api.php -- design §4.
 */
class ScreeningController extends Controller
{
    protected $model = Screening::class;

    protected $keyForPermission = 'screening';

    public function includes(): array
    {
        return [
            'visit', 'visit.health_center', 'visit.practitioner', 'visit.practitioner.user', 'child',
            'instrument_version', 'age_group', 'applied_age_group', 'age_derived_age_group',
            'screening_status', 'result_color', 'global_result_message', 'situation',
            // previous_result_color added by edi-screening-ui (REQ-SUI-008 previous-test
            // comparison) — was declared on the model but never eager loaded.
            'previous_screening', 'previous_result_color',
            'answers', 'answers.result_color',
            'area_results', 'area_results.development_area', 'area_results.result_color',
            'risk_factor_answers', 'risk_factor_answers.risk_factor',
            'findings', 'findings.finding_type',
            // .instrument_version/.result_color added by edi-screening-ui
            // (ScreeningTimeline needs the version key + run color; a bare
            // 'score_runs' does not eager-load its own relations).
            'score_runs', 'score_runs.instrument_version', 'score_runs.result_color',
            'confirmatory_test', 'confirmatory_test.confirmatory_test_result',
        ];
    }

    /**
     * `result_color_id` and `visit.health_center_id` were added by
     * edi-screening-ui (REQ-SUI-002) so the list filters (estado, color
     * global, centro de salud) can actually reach the API — dot-notation
     * relation filters follow the same convention as UserController
     * (`roles.slug`) / AuditController (`user.email`). Additive
     * API-surface change only, no domain/scoring behavior touched.
     */
    public function filterableBy(): array
    {
        return ['child_id', 'visit_id', 'screening_status_id', 'result_color_id', 'visit.health_center_id'];
    }

    /**
     * Orion only eager-loads includes() when the client explicitly asks for
     * them via ?include=. The whole point of this resource is the computed
     * result, so every relation is always loaded on index/show/update too
     * (consistent with the custom actions below, which ->load() explicitly).
     */
    public function alwaysIncludes(): array
    {
        return $this->includes();
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        if (in_array($entity->screening_status_id, [ScreeningStatus::COMPLETED, ScreeningStatus::INVALIDATED], true)) {
            throw ScreeningDomainException::screeningFrozen();
        }

        $validated = app(ScreeningUpdateRequest::class)->validated();
        $entity->fill($validated);
        $headCircumferenceChanged = $entity->isDirty('head_circumference_mm');

        $entity->save();

        if ($headCircumferenceChanged && $entity->screening_status_id === ScreeningStatus::SCORED) {
            $entity->forceFill([
                'screening_status_id' => ScreeningStatus::DRAFT,
                'result_color_id' => null,
                'situation_id' => null,
                'alert_sign_count' => null,
                'risk_factor_count' => null,
                'global_result_message_id' => null,
                'scored_at' => null,
            ])->save();
        }
    }

    /**
     * POST /api/screening — REQ-SCR-002: create draft + item bank. Named
     * `createScreening` (not `store`) because `store` is already declared,
     * with an incompatible signature, on Orion's base Controller.
     */
    public function createScreening(ScreeningCreateRequest $request, ScreeningApplicationService $service): JsonResponse
    {
        $result = $service->createDraft($request->validated());
        $screening = $result['screening']->load($this->includes());

        return response()->json([
            'data' => $screening,
            'item_bank' => $result['item_bank'],
        ], 201);
    }

    /**
     * GET /api/screening/{screening}/items — item bank re-fetch (resume,
     * post-422 retry), with the axes that currently need the previous group.
     */
    public function items(Screening $screening, ScreeningApplicationService $service, AlternateRequirementService $alternateRequirements): JsonResponse
    {
        $result = $service->itemBank($screening);

        return response()->json([
            'item_bank' => $result['item_bank'],
            'alternate_requirements' => $alternateRequirements->forClient($screening),
        ]);
    }

    /**
     * PUT /api/screening/{screening}/answer — REQ-SCR-004. Also reports the
     * axes that need the previous group, so the evaluator sees it while
     * capturing instead of only when scoring.
     */
    public function answer(
        Screening $screening,
        ScreeningAnswerRequest $request,
        ScreeningAnswerService $service,
        AlternateRequirementService $alternateRequirements,
    ): JsonResponse {
        $updated = $service->upsert($screening, $request->validated())->load($this->includes());

        return response()->json([
            'data' => $updated,
            'alternate_requirements' => $alternateRequirements->forClient($updated),
        ]);
    }

    /**
     * POST /api/screening/{screening}/score — REQ-SCR-005/006.
     */
    public function score(Screening $screening, ScreeningScoreService $service): JsonResponse
    {
        $updated = $service->score($screening)->load($this->includes());

        return response()->json(['data' => $updated]);
    }

    /**
     * POST /api/screening/{screening}/complete — REQ-SCR-009.
     */
    public function complete(Screening $screening, ScreeningLifecycleService $service): JsonResponse
    {
        $updated = $service->complete($screening)->load($this->includes());

        return response()->json(['data' => $updated]);
    }

    /**
     * POST /api/screening/{screening}/rescore — REQ-SCR-010.
     */
    public function rescore(Screening $screening, ScreeningRescoreRequest $request, ScreeningLifecycleService $service): JsonResponse
    {
        [$updated, $run] = $service->rescore($screening, $request->validated());

        return response()->json([
            'data' => $updated->load($this->includes()),
            'run' => $run->load('instrument_version', 'result_color'),
        ]);
    }

    /**
     * POST /api/screening/{screening}/invalidate — REQ-SCR-011.
     */
    public function invalidate(Screening $screening, ScreeningInvalidateRequest $request, ScreeningLifecycleService $service): JsonResponse
    {
        $updated = $service->invalidate($screening, $request->validated())->load($this->includes());

        return response()->json(['data' => $updated]);
    }

    /**
     * Instrument versions a screening may be rescored against.
     *
     * The rescore dialog needs these, but the raw instrument catalog is closed to
     * every organization role on purpose -- it is a platform-admin tool, and the
     * permission seed states the Screening module must serve tenant-facing reads
     * "through its own endpoints, never by browsing the raw instrument". Without
     * this, rescoring was unreachable: the role holding the screenings could not
     * read the catalog, and the role that could read it holds no screenings.
     *
     * Published versions only, and only the three fields the selector renders.
     */
    public function instrumentVersions(): JsonResponse
    {
        $versions = InstrumentVersion::query()
            ->whereNotNull('published_at')
            ->orderByDesc('is_default')
            ->orderByDesc('published_at')
            ->get(['id', 'key', 'name', 'is_default']);

        return response()->json(['data' => $versions]);
    }

    /**
     * GET /api/screening/age-groups — the official groups an evaluator may pick
     * for a "Prueba EDI a elección" (S2). Gated by create.screening: only who
     * can start a test needs the list.
     */
    public function ageGroups(AgeGroupCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->officialGroupsOfDefaultVersion()]);
    }

    /**
     * GET /api/screening/corrected-age — the "Edad corregida" calculator (S6).
     * A custom read action, so it validates inline. The reference date defaults
     * to the tenant's today, never the server's.
     */
    public function correctedAge(HttpRequest $request, CorrectedAgeCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'birth_date' => ['required', 'date_format:Y-m-d'],
            'gestational_weeks' => ['required', 'integer', 'between:20,44'],
            'reference_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:birth_date'],
        ], [
            'birth_date.required' => 'La fecha de nacimiento es obligatoria.',
            'birth_date.date_format' => 'La fecha de nacimiento no es válida.',
            'gestational_weeks.required' => 'Las semanas de gestación son obligatorias.',
            'gestational_weeks.integer' => 'Las semanas de gestación deben ser un número entero.',
            'gestational_weeks.between' => 'Las semanas de gestación deben estar entre 20 y 44.',
            'reference_date.date_format' => 'La fecha de referencia no es válida.',
            'reference_date.after_or_equal' => 'La fecha de referencia no puede ser anterior a la fecha de nacimiento.',
        ]);

        $referenceDate = $validated['reference_date'] ?? TenantTime::today();

        if ($referenceDate < $validated['birth_date']) {
            throw ValidationException::withMessages([
                'reference_date' => 'La fecha de referencia no puede ser anterior a la fecha de nacimiento.',
            ]);
        }

        return response()->json(['data' => $calculator->calculate(
            $validated['birth_date'],
            $referenceDate,
            (int) $validated['gestational_weeks'],
        )]);
    }

    /**
     * The official FUA document (REQ-SCR-015).
     *
     * A PROJECTION of what was stored, never a recomputation. A screening applied
     * months ago must print exactly what was recorded then, even if the instrument
     * has been re-seeded since -- re-running the engine here would let a document
     * silently disagree with the record it claims to represent. Hence: eager-load
     * the stored rows, render, and never touch ScoringEngine.
     *
     * Draft screenings have no result to print, so they are a 409 rather than an
     * empty page.
     */
    public function document(Screening $screening): Response
    {
        if (! in_array($screening->screening_status_id, [ScreeningStatus::SCORED, ScreeningStatus::COMPLETED], true)) {
            throw ScreeningDomainException::invalidStatusTransition(
                'La Prueba EDI aún no tiene resultado: no se puede emitir el documento.'
            );
        }

        $screening->load([
            'child.sex',
            'visit.health_center',
            'visit.practitioner.user',
            'instrument_version',
            'applied_age_group',
            'result_color',
            'global_result_message',
            'situation',
            'screening_status',
            'area_results.development_area',
            'area_results.result_color',
        ]);

        $pdf = Pdf::loadView('screening::document', [
            'screening' => $screening,
        ]);

        return $pdf->stream("prueba-edi-{$screening->visit?->receipt_number}.pdf");
    }
}
