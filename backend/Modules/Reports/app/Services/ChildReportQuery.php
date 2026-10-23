<?php

namespace Modules\Reports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\ChildCaregiver;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\Instrument\Models\ResultColor;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use QCH\Core\Support\TenantTime;

/**
 * One-row-per-child query behind the Concentrado EDI and the Reporte
 * general (S4/S5).
 *
 * Every per-child "latest/previous" is a PostgreSQL LATERAL subquery built
 * from a tenant-scoped Eloquent builder, so the whole page is ONE statement
 * (plus the paginator's COUNT) whatever the page size — no N+1. Each
 * subquery keeps its model's global scopes (TenantScope + SoftDeletes), so
 * the query never reads another tenant's rows.
 *
 * Counted tests: scored or completed, with a result, never draft or
 * invalidated, ordered by the visit date (then creation) — the same
 * counted set the indicators use, narrowed to finished statuses.
 */
class ChildReportQuery
{
    /**
     * @param  array{health_center_ids?: string[], from?: ?string, to?: ?string, search?: ?string}  $filters
     */
    public function base(array $filters): Builder
    {
        $query = Child::query()
            ->leftJoin('health_center as hc', 'hc.id', '=', 'child.health_center_id')
            ->leftJoinLateral($this->screeningAt(0), 'ls')
            ->leftJoinLateral($this->screeningAt(1), 'ps')
            ->leftJoinLateral($this->primaryCaregiver(), 'cg')
            ->leftJoinLateral($this->latestAssessment(), 'na')
            ->select([
                'child.id', 'child.first_name', 'child.last_name_paternal', 'child.last_name_maternal',
                'child.birth_date', 'child.curp', 'child.external_id', 'child.health_center_id',
                'hc.name as health_center_name',
                'cg.caregiver_first_name', 'cg.caregiver_last_name_paternal', 'cg.caregiver_last_name_maternal', 'cg.caregiver_phone',
                'na.measured_on as nutrition_measured_on', 'na.weight_grams as nutrition_weight_grams', 'na.height_mm as nutrition_height_mm',
                'na.wfh_key', 'na.wfh_name', 'na.wfa_key', 'na.wfa_name', 'na.hfa_key', 'na.hfa_name',
            ]);

        foreach (['ls' => 'last', 'ps' => 'previous'] as $alias => $prefix) {
            $query->addSelect([
                "{$alias}.id as {$prefix}_id",
                "{$alias}.visit_date as {$prefix}_visit_date",
                "{$alias}.result_key as {$prefix}_result_key",
                "{$alias}.result_name as {$prefix}_result_name",
                "{$alias}.risk_factor_count as {$prefix}_risk_factor_count",
                "{$alias}.corrected_age_days as {$prefix}_corrected_age_days",
                "{$alias}.impacted_area_ids as {$prefix}_impacted_area_ids",
                "{$alias}.evaluator_name as {$prefix}_evaluator_name",
            ]);
        }

        $this->applyFilters($query, $filters);

        return $query
            ->orderBy('child.last_name_paternal')
            ->orderBy('child.last_name_maternal')
            ->orderBy('child.first_name')
            ->orderBy('child.id');
    }

    /**
     * Concentrado-only columns: SDG, follow-ups of the last test and the
     * next evaluation (next active appointment, else the earliest pending
     * follow-up due date of the last test).
     */
    public function withConcentrateColumns(Builder $query): Builder
    {
        return $query
            ->leftJoinLateral($this->birthRecord(), 'br')
            ->leftJoinLateral($this->followUpsOfLastScreening(), 'fu')
            ->leftJoinLateral($this->nextAppointment(), 'ap')
            ->addSelect([
                'br.gestational_age_weeks',
                'fu.follow_up_summary', 'fu.next_follow_up_due_date',
                'ap.next_appointment_at',
            ]);
    }

    /**
     * @param  array{health_center_ids?: string[], from?: ?string, to?: ?string, search?: ?string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['health_center_ids'])) {
            $query->whereIn('child.health_center_id', $filters['health_center_ids']);
        }

        if (! empty($filters['from'])) {
            $query->where('ls.visit_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('ls.visit_date', '<=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw("CONCAT_WS(' ', child.first_name, child.last_name_paternal, child.last_name_maternal) ILIKE ?", [$like])
                    ->orWhereRaw("CONCAT_WS(' ', child.last_name_paternal, child.last_name_maternal, child.first_name) ILIKE ?", [$like])
                    ->orWhere('child.curp', 'ILIKE', $like)
                    ->orWhere('child.external_id', 'ILIKE', $like);
            });
        }
    }

    /** The child's Nth most recent counted test (0 = latest, 1 = previous). */
    private function screeningAt(int $offset): Builder
    {
        return Screening::query()
            ->join('visit as sv', 'sv.id', '=', 'screening.visit_id')
            ->leftJoin('result_color as rc', 'rc.id', '=', 'screening.result_color_id')
            ->leftJoin('practitioner_profile as pp', 'pp.id', '=', 'sv.practitioner_id')
            ->leftJoin('user as pu', 'pu.id', '=', 'pp.user_id')
            ->whereColumn('screening.child_id', 'child.id')
            ->whereNull('sv.deleted_at')
            ->whereIn('screening.screening_status_id', [ScreeningStatus::SCORED, ScreeningStatus::COMPLETED])
            ->whereNotNull('screening.result_color_id')
            ->select([
                'screening.id', 'screening.risk_factor_count', 'screening.corrected_age_days',
                'sv.visit_date', 'rc.key as result_key', 'rc.name as result_name',
            ])
            ->selectRaw("NULLIF(TRIM(CONCAT_WS(' ', pu.name, pu.last_name, pu.second_last_name)), '') as evaluator_name")
            ->selectRaw(
                "(SELECT string_agg(sar.development_area_id::text, ',') FROM screening_area_result sar
                  WHERE sar.screening_id = screening.id AND sar.deleted_at IS NULL AND sar.result_color_id <> ?) as impacted_area_ids",
                [ResultColor::GREEN]
            )
            ->orderByDesc('sv.visit_date')
            ->orderByDesc('screening.created_at')
            ->orderByDesc('screening.id')
            ->offset($offset)
            ->limit(1);
    }

    /** Primary caregiver first, else the first one linked. */
    private function primaryCaregiver(): Builder
    {
        return ChildCaregiver::query()
            ->join('caregiver as c', 'c.id', '=', 'child_caregiver.caregiver_id')
            ->whereColumn('child_caregiver.child_id', 'child.id')
            ->whereNull('c.deleted_at')
            ->select([
                'c.first_name as caregiver_first_name',
                'c.last_name_paternal as caregiver_last_name_paternal',
                'c.last_name_maternal as caregiver_last_name_maternal',
                'c.phone as caregiver_phone',
            ])
            ->orderByDesc('child_caregiver.is_primary')
            ->orderBy('child_caregiver.created_at')
            ->limit(1);
    }

    /** "Última medición": the latest nutritional assessment by measured_on. */
    private function latestAssessment(): Builder
    {
        return NutritionalAssessment::query()
            ->leftJoin('nutritional_status as wfh', 'wfh.id', '=', 'nutritional_assessment.weight_for_height_status_id')
            ->leftJoin('nutritional_status as wfa', 'wfa.id', '=', 'nutritional_assessment.weight_for_age_status_id')
            ->leftJoin('nutritional_status as hfa', 'hfa.id', '=', 'nutritional_assessment.height_for_age_status_id')
            ->whereColumn('nutritional_assessment.child_id', 'child.id')
            ->select([
                'nutritional_assessment.measured_on', 'nutritional_assessment.weight_grams', 'nutritional_assessment.height_mm',
                'wfh.key as wfh_key', 'wfh.name as wfh_name',
                'wfa.key as wfa_key', 'wfa.name as wfa_name',
                'hfa.key as hfa_key', 'hfa.name as hfa_name',
            ])
            ->orderByDesc('nutritional_assessment.measured_on')
            ->orderByDesc('nutritional_assessment.created_at')
            ->limit(1);
    }

    private function birthRecord(): Builder
    {
        return BirthRecord::query()
            ->whereColumn('birth_record.child_id', 'child.id')
            ->select(['birth_record.gestational_age_weeks'])
            ->orderByDesc('birth_record.created_at')
            ->limit(1);
    }

    /** Follow-ups opened by the last counted test, cancelled ones excluded. */
    private function followUpsOfLastScreening(): Builder
    {
        return FollowUp::query()
            ->join('follow_up_type as fut', 'fut.id', '=', 'follow_up.follow_up_type_id')
            ->join('follow_up_status as fus', 'fus.id', '=', 'follow_up.follow_up_status_id')
            ->whereColumn('follow_up.screening_id', 'ls.id')
            ->where('follow_up.follow_up_status_id', '!=', FollowUpStatus::CANCELLED)
            ->selectRaw("string_agg(fut.name || ' (' || fus.name || ')', '; ' ORDER BY follow_up.due_date) as follow_up_summary")
            ->selectRaw(
                'MIN(follow_up.due_date) FILTER (WHERE follow_up.follow_up_status_id IN (?, ?) AND follow_up.due_date >= ?) as next_follow_up_due_date',
                [FollowUpStatus::PENDING, FollowUpStatus::IN_PROGRESS, TenantTime::today()]
            );
    }

    private function nextAppointment(): Builder
    {
        return Appointment::query()
            ->whereColumn('appointment.child_id', 'child.id')
            ->whereIn('appointment.appointment_status_id', AppointmentStatus::OCCUPYING)
            ->where('appointment.starts_at', '>=', CarbonImmutable::now('UTC'))
            ->selectRaw('MIN(appointment.starts_at) as next_appointment_at');
    }
}
