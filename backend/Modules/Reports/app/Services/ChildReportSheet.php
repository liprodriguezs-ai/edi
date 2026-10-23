<?php

namespace Modules\Reports\Services;

use Carbon\CarbonImmutable;

/**
 * Spanish Excel projection of the Concentrado EDI / Reporte general rows:
 * dates dd/mm/yyyy, weight in kg and height in cm with one decimal,
 * results and nutritional statuses as their Spanish labels.
 */
class ChildReportSheet
{
    public function __construct(private readonly ImpactAxisCatalog $axes) {}

    /**
     * @return string[]
     */
    public function concentrateHeadings(): array
    {
        $impacts = fn (string $prefix): array => array_map(fn (string $code) => "{$prefix}: {$code}", $this->axes->codes());

        return [
            '#', 'Elaboró', 'ID', 'CURP', 'Apellido paterno', 'Apellido materno', 'Nombre(s)', 'Centro de salud',
            'Fecha de nacimiento', 'Edad', 'SDG', 'Edad corregida', 'Edad en meses',
            'Fecha prueba anterior', 'Calificación prueba anterior', ...$impacts('Prueba anterior'),
            'Fecha última prueba', 'Calificación última prueba', ...$impacts('Última prueba'),
            'Cuidador', 'Teléfono',
            'Fecha de última medición', 'Peso (kg)', 'Talla (cm)', 'Peso/talla', 'Peso/edad', 'Talla/edad',
            'Seguimiento y referencias', 'Próxima evaluación (mes-año)',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    public function concentrateLine(int $number, array $row): array
    {
        $last = $row['last_screening'];
        $previous = $row['previous_screening'];

        return [
            $number, $row['evaluator_name'] ?? '', $row['external_id'] ?? '', $row['curp'] ?? '',
            $row['last_name_paternal'], $row['last_name_maternal'] ?? '', $row['first_name'], $row['health_center_name'] ?? '',
            $this->date($row['birth_date']), $row['age_label'], $row['gestational_age_weeks'] ?? '',
            $last['corrected_age_label'] ?? '', $row['age_months'],
            ...$this->screeningCells($previous),
            ...$this->screeningCells($last),
            $row['caregiver_name'] ?? '', $row['caregiver_phone'] ?? '',
            ...$this->nutritionCells($row['nutrition']),
            $row['follow_up_summary'] ?? '',
            $row['next_evaluation'] === null ? '' : CarbonImmutable::createFromFormat('!Y-m', $row['next_evaluation'])->format('m-Y'),
        ];
    }

    /**
     * @return string[]
     */
    public function generalHeadings(): array
    {
        return [
            '#', 'ID', 'CURP', 'Apellido paterno', 'Apellido materno', 'Nombre(s)', 'Centro de salud',
            'Fecha de nacimiento', 'Edad',
            'Fecha de última medición', 'Peso (kg)', 'Talla (cm)', 'Peso/talla', 'Peso/edad', 'Talla/edad',
            'Cuidador', 'Teléfono',
            'Fecha prueba EDI anterior', 'Resultado prueba EDI anterior',
            'Fecha última prueba EDI', 'Resultado última prueba EDI',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    public function generalLine(int $number, array $row): array
    {
        $last = $row['last_screening'];
        $previous = $row['previous_screening'];

        return [
            $number, $row['external_id'] ?? '', $row['curp'] ?? '',
            $row['last_name_paternal'], $row['last_name_maternal'] ?? '', $row['first_name'], $row['health_center_name'] ?? '',
            $this->date($row['birth_date']), $row['age_label'],
            ...$this->nutritionCells($row['nutrition']),
            $row['caregiver_name'] ?? '', $row['caregiver_phone'] ?? '',
            $previous === null ? '' : $this->date($previous['date']), $previous['result']['name'] ?? '',
            $last === null ? '' : $this->date($last['date']), $last['result']['name'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $screening
     * @return array<int, string>
     */
    private function screeningCells(?array $screening): array
    {
        if ($screening === null) {
            return array_fill(0, 2 + count($this->axes->codes()), '');
        }

        return [
            $this->date($screening['date']),
            $screening['result']['name'],
            ...array_map(fn (bool $impact) => $impact ? 'Sí' : '', array_values($screening['impacts'])),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $nutrition
     * @return array<int, string>
     */
    private function nutritionCells(?array $nutrition): array
    {
        if ($nutrition === null) {
            return array_fill(0, 6, '');
        }

        return [
            $this->date($nutrition['measured_on']),
            $this->oneDecimal($nutrition['weight_grams'], 1000),
            $this->oneDecimal($nutrition['height_mm'], 10),
            $nutrition['weight_for_height_status']['name'] ?? '',
            $nutrition['weight_for_age_status']['name'] ?? '',
            $nutrition['height_for_age_status']['name'] ?? '',
        ];
    }

    private function oneDecimal(?int $minorUnits, int $divisor): string
    {
        return $minorUnits === null ? '' : number_format($minorUnits / $divisor, 1, '.', '');
    }

    private function date(string $isoDate): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $isoDate)->format('d/m/Y');
    }
}
