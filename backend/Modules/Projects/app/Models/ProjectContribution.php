<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Housing\Models\Unit;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class ProjectContribution extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'project_contribution';

    protected $fillable = [
        'community_project_id',
        'unit_id',
        'expected_amount',
        'contributed_amount',
        'declined',
        'project_contribution_status_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => TenantMoneyCast::class,
            'contributed_amount' => TenantMoneyCast::class,
            'declined' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(CommunityProject::class, 'community_project_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function contributionStatus(): BelongsTo
    {
        return $this->belongsTo(ProjectContributionStatus::class, 'project_contribution_status_id');
    }

    public function reportColumns(): array
    {
        return [
            'Proyecto' => function ($result) {
                return $result->project?->title;
            },
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Monto esperado' => function ($result) {
                return $result->expected_amount ? (float) $result->expected_amount->formatByDecimal() : 0;
            },
            'Monto aportado' => function ($result) {
                return $result->contributed_amount ? (float) $result->contributed_amount->formatByDecimal() : 0;
            },
            'Declinada' => function ($result) {
                return $result->declined ? 'Sí' : 'No';
            },
            'Estado' => function ($result) {
                return $result->contributionStatus?->name;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
