<?php

namespace Modules\Reports\Services;

use Carbon\CarbonImmutable;
use QCH\Core\Support\TenantTime;
use stdClass;

/**
 * Turns one flat row of ChildReportQuery into the API payload of the
 * Concentrado EDI / Reporte general. Pure projection: no query runs here.
 */
class ChildReportRowMapper
{
    public function __construct(private readonly ImpactAxisCatalog $axes) {}

    /**
     * @return array<string, mixed>
     */
    public function general(stdClass $row): array
    {
        $today = CarbonImmutable::parse(TenantTime::today());
        $birthDate = CarbonImmutable::parse((string) $row->birth_date);
        $age = $birthDate->diff($today);

        return [
            'id' => $row->id,
            'first_name' => $row->first_name,
            'last_name_paternal' => $row->last_name_paternal,
            'last_name_maternal' => $row->last_name_maternal,
            'child_name' => $this->fullName($row->first_name, $row->last_name_paternal, $row->last_name_maternal),
            'curp' => $row->curp,
            'external_id' => $row->external_id,
            'birth_date' => $birthDate->format('Y-m-d'),
            'age_label' => $this->ageLabel($age->y, $age->m, $age->d),
            'age_months' => $age->y * 12 + $age->m,
            'health_center_name' => $row->health_center_name,
            'caregiver_name' => $row->caregiver_first_name === null
                ? null
                : $this->fullName($row->caregiver_first_name, $row->caregiver_last_name_paternal, $row->caregiver_last_name_maternal),
            'caregiver_phone' => $row->caregiver_phone,
            'nutrition' => $this->nutrition($row),
            'last_screening' => $this->screening($row, 'last'),
            'previous_screening' => $this->screening($row, 'previous'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function concentrate(stdClass $row): array
    {
        $base = $this->general($row);

        return [
            ...$base,
            'gestational_age_weeks' => $row->gestational_age_weeks === null ? null : (int) $row->gestational_age_weeks,
            'evaluator_name' => $row->last_evaluator_name,
            'follow_up_summary' => $row->follow_up_summary,
            'next_evaluation' => $this->nextEvaluation($row),
        ];
    }

    /**
     * @return array{id: string, date: string, result: array{key: string, name: string}, impacts: array<string, bool>, corrected_age_label: ?string}|null
     */
    private function screening(stdClass $row, string $prefix): ?array
    {
        if ($row->{"{$prefix}_id"} === null) {
            return null;
        }

        $visitDate = CarbonImmutable::parse((string) $row->{"{$prefix}_visit_date"});
        $impacted = array_filter(explode(',', (string) $row->{"{$prefix}_impacted_area_ids"}));

        $impacts = [];
        foreach ($this->axes->axes() as $axis) {
            $areaId = $axis['area_id'];
            $impacts[$axis['code']] = $areaId === null
                ? (int) $row->{"{$prefix}_risk_factor_count"} > 0
                : in_array($areaId, $impacted, true);
        }

        $correctedDays = $row->{"{$prefix}_corrected_age_days"};

        return [
            'id' => $row->{"{$prefix}_id"},
            'date' => $visitDate->format('Y-m-d'),
            'result' => ['key' => $row->{"{$prefix}_result_key"}, 'name' => $row->{"{$prefix}_result_name"}],
            'impacts' => $impacts,
            'corrected_age_label' => $correctedDays === null ? null : $this->correctedAgeLabel($visitDate, (int) $correctedDays),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nutrition(stdClass $row): ?array
    {
        if ($row->nutrition_measured_on === null) {
            return null;
        }

        return [
            'measured_on' => CarbonImmutable::parse((string) $row->nutrition_measured_on)->format('Y-m-d'),
            'weight_grams' => $row->nutrition_weight_grams === null ? null : (int) $row->nutrition_weight_grams,
            'height_mm' => $row->nutrition_height_mm === null ? null : (int) $row->nutrition_height_mm,
            'weight_for_height_status' => $this->status($row->wfh_key, $row->wfh_name),
            'weight_for_age_status' => $this->status($row->wfa_key, $row->wfa_name),
            'height_for_age_status' => $this->status($row->hfa_key, $row->hfa_name),
        ];
    }

    /**
     * @return array{key: string, name: string}|null
     */
    private function status(?string $key, ?string $name): ?array
    {
        return $key === null ? null : ['key' => $key, 'name' => (string) $name];
    }

    /** Next evaluation month (Y-m): next active appointment, else the next pending follow-up. */
    private function nextEvaluation(stdClass $row): ?string
    {
        if ($row->next_appointment_at !== null) {
            return TenantTime::inTenantZone((string) $row->next_appointment_at)->format('Y-m');
        }

        if ($row->next_follow_up_due_date !== null) {
            return CarbonImmutable::parse((string) $row->next_follow_up_due_date)->format('Y-m');
        }

        return null;
    }

    private function correctedAgeLabel(CarbonImmutable $visitDate, int $correctedDays): string
    {
        $age = $visitDate->subDays($correctedDays)->diff($visitDate);
        $months = $age->y * 12 + $age->m;

        return "{$months} mes(es) y {$age->d} día(s)";
    }

    private function ageLabel(int $years, int $months, int $days): string
    {
        return "{$years} año(s), {$months} mes(es) y {$days} día(s)";
    }

    private function fullName(?string $first, ?string $paternal, ?string $maternal): string
    {
        return trim(implode(' ', array_filter([$first, $paternal, $maternal], fn ($part) => $part !== null && $part !== '')));
    }
}
