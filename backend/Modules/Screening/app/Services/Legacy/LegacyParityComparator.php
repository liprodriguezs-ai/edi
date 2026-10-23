<?php

declare(strict_types=1);

namespace Modules\Screening\Services\Legacy;

use Modules\Screening\Services\Scoring\Values\ResultColorKey;

/**
 * Compares the legacy stored EDI result against the result the new scoring
 * engine recomputes from the same answers. Pure and DB-free: it takes already
 * extracted scalar values and returns one report row with per-field and overall
 * match flags. This is the unit of the migration parity document (old vs new).
 */
final class LegacyParityComparator
{
    /** Legacy semaphore label -> engine color vocabulary. */
    private const COLOR_MAP = [
        'VERDE' => ResultColorKey::GREEN,
        'AMARILLO' => ResultColorKey::YELLOW,
        'ROJO' => ResultColorKey::RED,
    ];

    private const UNKNOWN = 'UNKNOWN';

    /**
     * @param  array{global_color:string, situacion:int|string|null, total_ale:int|string|null, total_frb:int|string|null}  $legacy
     * @param  array{global_color:string, sis_code:int|null, alert_sign_count:int, risk_factor_count:int}  $computed
     * @return array<string, int|string|bool|null>
     */
    public function compare(int $legacyId, string $groupCode, array $legacy, array $computed): array
    {
        $legacyColor = self::COLOR_MAP[strtoupper(trim($legacy['global_color']))] ?? self::UNKNOWN;
        $newColor = $computed['global_color'];

        $legacySituation = $legacy['situacion'] === null ? null : (int) $legacy['situacion'];
        $newSituation = $computed['sis_code'];

        $legacyAle = (int) $legacy['total_ale'];
        $legacyFrb = (int) $legacy['total_frb'];

        $colorMatch = $legacyColor !== self::UNKNOWN && $legacyColor === $newColor;
        $aleMatch = $legacyAle === $computed['alert_sign_count'];
        $frbMatch = $legacyFrb === $computed['risk_factor_count'];

        // Situation is only comparable for first-time states (1 NORMAL, 2 REZAGO,
        // 3 RIESGO). Recovered/follow-up (4/5/6) derive from prior-screening
        // history not replayed here, so they are n/a (null) and never break the
        // overall match — mirrors OracleReplayTest's 1/2/3-only assertion.
        $situationComparable = in_array($legacySituation, [1, 2, 3], true);
        $situationMatch = $situationComparable ? ($legacySituation === $newSituation) : null;

        return [
            'legacy_id' => $legacyId,
            'group_code' => $groupCode,
            'legacy_color' => $legacyColor,
            'new_color' => $newColor,
            'color_match' => $colorMatch,
            'legacy_situation' => $legacySituation,
            'new_situation' => $newSituation,
            'situation_match' => $situationMatch,
            'legacy_ale' => $legacyAle,
            'new_ale' => $computed['alert_sign_count'],
            'ale_match' => $aleMatch,
            'legacy_frb' => $legacyFrb,
            'new_frb' => $computed['risk_factor_count'],
            'frb_match' => $frbMatch,
            'all_match' => $colorMatch && $aleMatch && $frbMatch && $situationMatch !== false,
        ];
    }
}
