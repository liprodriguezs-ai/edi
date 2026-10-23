<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stimulation\Database\Factories\StimulationSessionMovementFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Massage pair applied in the preparation section of a session (REQ-SS-003).
 * Written ONLY through StimulationSessionService's declarative sync (D7) —
 * no standalone Orion resource exposes this table.
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationSessionMovement extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stimulation_session_movement';

    protected $fillable = [
        'session_id',
        'body_zone_id',
        'massage_movement_id',
    ];

    public function stimulation_session(): BelongsTo
    {
        return $this->belongsTo(StimulationSession::class, 'session_id');
    }

    public function body_zone(): BelongsTo
    {
        return $this->belongsTo(BodyZone::class, 'body_zone_id');
    }

    public function massage_movement(): BelongsTo
    {
        return $this->belongsTo(MassageMovement::class, 'massage_movement_id');
    }

    protected static function newFactory(): StimulationSessionMovementFactory
    {
        return StimulationSessionMovementFactory::new();
    }
}
