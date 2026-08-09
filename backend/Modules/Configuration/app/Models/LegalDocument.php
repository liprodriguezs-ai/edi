<?php

namespace Modules\Configuration\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * LegalDocument — Module 12 (§16.3.8): a versioned official cluster document.
 *
 * Tenant-scoped. The file is stored in the 'document' Spatie media collection
 * (App\Models\Media, UUID keys). Each new upload of the same TYPE supersedes the
 * previous row (is_current=false on the old one, version+1 on the new one), so
 * the version history is preserved per the spec ("Subido ... Versión 3.2").
 */
#[ObservedBy([GlobalObserver::class])]
class LegalDocument extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'legal_document';

    public const TYPE_REGLAMENTO = 'reglamento';

    public const TYPE_ACTA_CONSTITUTIVA = 'acta_constitutiva';

    public const TYPE_AVISO_PRIVACIDAD = 'aviso_privacidad';

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_REGLAMENTO,
        self::TYPE_ACTA_CONSTITUTIVA,
        self::TYPE_AVISO_PRIVACIDAD,
    ];

    protected $fillable = [
        'type',
        'title',
        'version',
        'is_current',
        'is_visible_to_residents',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_current' => 'boolean',
            'is_visible_to_residents' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('document')->singleFile();
    }
}
