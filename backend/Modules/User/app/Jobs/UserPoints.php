<?php

namespace Modules\User\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Gamification\Models\Gamification;
use Modules\Gamification\Models\GamificationLevel;
use Modules\Gamification\Models\GamificationPointsHistory;

class UserPoints implements ShouldQueue
{
    public function getGamification($entity)
    {
        $user = $entity->user;
        $gamification = Gamification::where('user_id', $user->id)->first();
        if (! $gamification) {
            $gamification = Gamification::create([
                'user_id' => $user->id,
                'gamification_level_id' => GamificationLevel::DISTRIBUIDOR,
            ]);
        }

        return $gamification;
    }

    public function addPoints($entity, $points, $metadata = [])
    {
        $gamification = $this->getGamification($entity);
        $data = $this->getCreateAssign($entity, $points, $gamification);
        $totalPoints = $gamification->points + $points;
        $data['points_after'] = $totalPoints;
        $data['points'] = $points;
        $data['points_before'] = $gamification->points ?? 0;
        $data['metadata'] = $metadata;
        GamificationPointsHistory::create($data);
        $gamification->points = $totalPoints < 0 ? 0 : $totalPoints;
        $gamification->save();
    }

    public function substractPoints($entity, $points, $metadata = [])
    {
        $gamification = $this->getGamification($entity);
        $data = $this->getCreateAssign($entity, $points, $gamification);
        $totalPoints = $gamification->points - $points;
        $data['points_before'] = $gamification->points ?? 0;
        $data['points'] = $points;
        $data['points_after'] = $totalPoints;
        $data['metadata'] = $metadata;
        GamificationPointsHistory::create($data);
        $gamification->points = $totalPoints < 0 ? 0 : $totalPoints;
        $gamification->save();
    }

    public function getCreateAssign($entity, $points, $gamification)
    {
        return [
            'model_id' => $entity->id,
            'model_type' => get_class($entity),
            'points' => $points,
            'month' => now()->month,
            'year' => now()->year,
            'gamification_level_id' => $gamification->gamification_level_id,
            'points_before' => $gamification->points,
            'points_after' => $gamification->points + $points,
            'user_id' => $gamification->user_id,
        ];
    }

    public function getAssign($entity)
    {
        $assign = GamificationPointsHistory::where('model_id', $entity->id)->where('model_type', get_class($entity))->first();

        return $assign;
    }
}
