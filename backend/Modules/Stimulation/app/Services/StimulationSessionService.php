<?php

declare(strict_types=1);

namespace Modules\Stimulation\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Stimulation\Models\StimulationSession;
use Modules\Stimulation\Models\StimulationSessionMovement;
use Modules\Stimulation\Models\StimulationSessionRelaxationZone;

/**
 * REQ-SS-003 / design D7 — massage movements and relaxation zones are
 * written ONLY through the session's create/update payload, as declarative
 * arrays synced in the SAME transaction as the session write: rows matching
 * the desired set are created if missing or restored if trashed; rows no
 * longer desired are soft-deleted. No standalone endpoint touches these
 * tables directly.
 */
class StimulationSessionService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StimulationSession
    {
        return DB::transaction(function () use ($attributes): StimulationSession {
            [$movements, $relaxationZones, $sessionAttributes] = $this->splitNestedArrays($attributes);

            $session = StimulationSession::create($sessionAttributes);

            if ($movements !== null) {
                $this->syncMovements($session, $movements);
            }

            if ($relaxationZones !== null) {
                $this->syncRelaxationZones($session, $relaxationZones);
            }

            return $session->fresh(['movements', 'relaxation_zones']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StimulationSession $session, array $attributes): StimulationSession
    {
        return DB::transaction(function () use ($session, $attributes): StimulationSession {
            [$movements, $relaxationZones, $sessionAttributes] = $this->splitNestedArrays($attributes);

            $session->fill($sessionAttributes);
            $session->save();

            if ($movements !== null) {
                $this->syncMovements($session, $movements);
            }

            if ($relaxationZones !== null) {
                $this->syncRelaxationZones($session, $relaxationZones);
            }

            return $session->fresh(['movements', 'relaxation_zones']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: ?array<int, array<string, mixed>>, 1: ?array<int, array<string, mixed>>, 2: array<string, mixed>}
     */
    private function splitNestedArrays(array $attributes): array
    {
        $movements = array_key_exists('movements', $attributes) ? $attributes['movements'] : null;
        $relaxationZones = array_key_exists('relaxation_zones', $attributes) ? $attributes['relaxation_zones'] : null;

        unset($attributes['movements'], $attributes['relaxation_zones']);

        return [$movements, $relaxationZones, $attributes];
    }

    /**
     * @param  array<int, array<string, mixed>>  $movements
     */
    private function syncMovements(StimulationSession $session, array $movements): void
    {
        $desired = collect($movements)
            ->map(fn (array $m): string => $m['body_zone_id'].'|'.$m['massage_movement_id'])
            ->all();

        $existing = StimulationSessionMovement::withTrashed()
            ->where('session_id', $session->id)
            ->get();

        $this->reconcileExisting($existing, $desired, fn (StimulationSessionMovement $row): string => $row->body_zone_id.'|'.$row->massage_movement_id);

        $existingKeys = $existing->map(fn (StimulationSessionMovement $row): string => $row->body_zone_id.'|'.$row->massage_movement_id)->all();

        foreach ($movements as $movement) {
            $key = $movement['body_zone_id'].'|'.$movement['massage_movement_id'];
            if (! in_array($key, $existingKeys, true)) {
                StimulationSessionMovement::create([
                    'session_id' => $session->id,
                    'body_zone_id' => $movement['body_zone_id'],
                    'massage_movement_id' => $movement['massage_movement_id'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $relaxationZones
     */
    private function syncRelaxationZones(StimulationSession $session, array $relaxationZones): void
    {
        $desired = collect($relaxationZones)
            ->map(fn (array $z): string => (string) $z['body_zone_id'])
            ->all();

        $existing = StimulationSessionRelaxationZone::withTrashed()
            ->where('session_id', $session->id)
            ->get();

        $this->reconcileExisting($existing, $desired, fn (StimulationSessionRelaxationZone $row): string => (string) $row->body_zone_id);

        $existingKeys = $existing->map(fn (StimulationSessionRelaxationZone $row): string => (string) $row->body_zone_id)->all();

        foreach ($relaxationZones as $zone) {
            $key = (string) $zone['body_zone_id'];
            if (! in_array($key, $existingKeys, true)) {
                StimulationSessionRelaxationZone::create([
                    'session_id' => $session->id,
                    'body_zone_id' => $zone['body_zone_id'],
                ]);
            }
        }
    }

    /**
     * Restores an existing trashed row that is still desired, and
     * soft-deletes an existing non-trashed row that is no longer desired.
     * Untouched rows (desired + not trashed, or not desired + already
     * trashed) are left as-is.
     *
     * @template TModel of StimulationSessionMovement|StimulationSessionRelaxationZone
     *
     * @param  Collection<int, TModel>  $existing
     * @param  array<int, string>  $desiredKeys
     * @param  callable(TModel): string  $keyOf
     */
    private function reconcileExisting(Collection $existing, array $desiredKeys, callable $keyOf): void
    {
        foreach ($existing as $row) {
            $isDesired = in_array($keyOf($row), $desiredKeys, true);

            if ($isDesired && $row->trashed()) {
                $row->restore();
            } elseif (! $isDesired && ! $row->trashed()) {
                $row->delete();
            }
        }
    }
}
