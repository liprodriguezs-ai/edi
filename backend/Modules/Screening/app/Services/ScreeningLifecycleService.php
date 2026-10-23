<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use Illuminate\Support\Facades\DB;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Events\ScreeningCompleted;
use Modules\Screening\Exceptions\ScreeningDomainException;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningScoreRun;
use Modules\Screening\Models\ScreeningStatus;

/**
 * complete/rescore/invalidate (design D8/D9/D15, REQ-SCR-009/010/011). Every
 * mutation locks the screening row inside a transaction (D19).
 */
final class ScreeningLifecycleService
{
    public function __construct(
        private readonly RuleSetProvider $ruleSetProvider,
        private readonly ScreeningScoreService $scoreService,
    ) {}

    /**
     * REQ-SCR-009 — scored -> completed.
     */
    public function complete(Screening $screening): Screening
    {
        return DB::transaction(function () use ($screening): Screening {
            /** @var Screening $locked */
            $locked = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->screening_status_id, [ScreeningStatus::COMPLETED, ScreeningStatus::INVALIDATED], true)) {
                throw ScreeningDomainException::screeningFrozen();
            }

            if ($locked->screening_status_id !== ScreeningStatus::SCORED) {
                throw ScreeningDomainException::invalidStatusTransition(
                    'Solo una Prueba EDI calificada puede completarse.'
                );
            }

            $locked->forceFill([
                'screening_status_id' => ScreeningStatus::COMPLETED,
                'completed_at' => now(),
            ])->save();

            // REQ-SCR-016 (design D1) — dispatched synchronously, inside this
            // transaction: a listener failure rolls back the completion, so
            // a completed screening never exists without its conduct.
            event(new ScreeningCompleted($locked));

            return $locked->fresh();
        });
    }

    /**
     * REQ-SCR-010 — every invocation creates a NEW screening_score_run.
     * authoritative=true recomputes the header with the screening's OWN
     * version (D8) after verifying the stored fingerprint (D9); status is
     * NEVER changed here. authoritative=false (default, Q3) is a recorded
     * what-if simulation against any published version and NEVER touches
     * the header or any result row.
     *
     * @param  array{authoritative?: bool, instrument_version_key?: ?string, reason: string}  $data
     * @return array{0: Screening, 1: ScreeningScoreRun}
     */
    public function rescore(Screening $screening, array $data): array
    {
        return DB::transaction(function () use ($screening, $data): array {
            /** @var Screening $locked */
            $locked = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();

            if ($locked->screening_status_id === ScreeningStatus::INVALIDATED) {
                throw ScreeningDomainException::screeningFrozen();
            }

            if (! in_array($locked->screening_status_id, [ScreeningStatus::SCORED, ScreeningStatus::COMPLETED], true)) {
                throw ScreeningDomainException::invalidStatusTransition(
                    'Solo una Prueba EDI calificada o completada puede recalcularse.'
                );
            }

            $authoritative = (bool) ($data['authoritative'] ?? false);
            $ownVersion = InstrumentVersion::findOrFail($locked->instrument_version_id);
            $requestedKey = $data['instrument_version_key'] ?? null;

            if ($authoritative) {
                if ($requestedKey !== null && $requestedKey !== $ownVersion->key) {
                    throw ScreeningDomainException::versionPinned();
                }

                if ($locked->rules_fingerprint !== null && $locked->rules_fingerprint !== $ownVersion->content_hash) {
                    throw ScreeningDomainException::rulesFingerprintMismatch();
                }

                $targetVersion = $ownVersion;
            } else {
                $targetVersion = $requestedKey !== null
                    ? InstrumentVersion::where('key', $requestedKey)->whereNotNull('published_at')->firstOrFail()
                    : $ownVersion;
            }

            $targetRuleSet = $this->ruleSetProvider->for($targetVersion->key);
            $input = $this->scoreService->buildInputForRescore($locked, $ownVersion, $targetRuleSet);
            $result = $this->scoreService->runEngine($input, $targetRuleSet);

            if ($authoritative) {
                $appliedGroupModel = AgeGroup::findOrFail($locked->applied_age_group_id);

                $this->scoreService->persistAuthoritativeResult(
                    $locked,
                    $targetVersion,
                    $appliedGroupModel,
                    $result,
                    ScreeningScoreRun::TRIGGER_RESCORE,
                    $data['reason'],
                    $input->previousResultColorKey,
                    false,
                );

                return [$locked->fresh(), $locked->score_runs()->latest('created_at')->first()];
            }

            $run = ScreeningScoreRun::create([
                'screening_id' => $locked->id,
                'instrument_version_id' => $targetVersion->id,
                'is_authoritative' => false,
                'trigger' => ScreeningScoreRun::TRIGGER_SIMULATION,
                'reason' => $data['reason'],
                'engine_version' => ScreeningScoreService::ENGINE_VERSION,
                'rules_fingerprint' => $targetVersion->content_hash,
                'input_payload' => [
                    'applied_age_group_code' => $result->appliedAgeGroupCode,
                    'previous_result_color_key' => $input->previousResultColorKey,
                ],
                'result_payload' => $result->toArray(),
                'result_color_id' => ResultColor::where('key', $result->globalColorKey)->value('id'),
            ]);

            return [$locked->fresh(), $run];
        });
    }

    /**
     * REQ-SCR-011 — retires without deleting; freezes every mutating
     * endpoint including a future rescore. Excluded from previous-screening
     * resolution (REQ-SCR-012) simply because that query filters on
     * screening_status_id = completed.
     */
    public function invalidate(Screening $screening, array $data): Screening
    {
        return DB::transaction(function () use ($screening, $data): Screening {
            /** @var Screening $locked */
            $locked = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();

            if ($locked->screening_status_id === ScreeningStatus::INVALIDATED) {
                throw ScreeningDomainException::screeningFrozen();
            }

            $locked->forceFill([
                'screening_status_id' => ScreeningStatus::INVALIDATED,
                'invalidation_reason' => $data['reason'],
            ])->save();

            return $locked->fresh();
        });
    }
}
