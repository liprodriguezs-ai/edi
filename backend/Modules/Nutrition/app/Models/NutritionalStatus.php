<?php

namespace Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of nutritional status bands (S5). Fixed UUIDs, seeded by
 * NutritionSeeder, read-only for tenant roles. NutritionalStatusClassifier
 * returns the `key`; idForKey() maps it to the stored id.
 */
#[ObservedBy([GlobalObserver::class])]
class NutritionalStatus extends CentralCatalogModel
{
    protected $table = 'nutritional_status';

    public const SEVERE_MALNUTRITION = 'ed700001-0000-4000-8000-000000000001';

    public const MODERATE_MALNUTRITION = 'ed700001-0000-4000-8000-000000000002';

    public const MILD_MALNUTRITION = 'ed700001-0000-4000-8000-000000000003';

    public const NORMAL_WEIGHT = 'ed700001-0000-4000-8000-000000000004';

    public const OVERWEIGHT = 'ed700001-0000-4000-8000-000000000005';

    public const OBESITY = 'ed700001-0000-4000-8000-000000000006';

    public const SHORT_STATURE = 'ed700001-0000-4000-8000-000000000007';

    public const SLIGHTLY_SHORT_STATURE = 'ed700001-0000-4000-8000-000000000008';

    public const NORMAL_STATURE = 'ed700001-0000-4000-8000-000000000009';

    public const SLIGHTLY_TALL_STATURE = 'ed700001-0000-4000-8000-000000000010';

    public const TALL_STATURE = 'ed700001-0000-4000-8000-000000000011';

    public const NO_REFERENCE = 'ed700001-0000-4000-8000-000000000012';

    /**
     * key => [id, Spanish label], in display order.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CATALOG = [
        'severe_malnutrition' => [self::SEVERE_MALNUTRITION, 'Desnutrición grave'],
        'moderate_malnutrition' => [self::MODERATE_MALNUTRITION, 'Desnutrición moderada'],
        'mild_malnutrition' => [self::MILD_MALNUTRITION, 'Desnutrición leve'],
        'normal_weight' => [self::NORMAL_WEIGHT, 'Peso normal'],
        'overweight' => [self::OVERWEIGHT, 'Sobrepeso'],
        'obesity' => [self::OBESITY, 'Obesidad'],
        'short_stature' => [self::SHORT_STATURE, 'Talla baja'],
        'slightly_short_stature' => [self::SLIGHTLY_SHORT_STATURE, 'Talla ligeramente baja'],
        'normal_stature' => [self::NORMAL_STATURE, 'Talla normal'],
        'slightly_tall_stature' => [self::SLIGHTLY_TALL_STATURE, 'Talla ligeramente alta'],
        'tall_stature' => [self::TALL_STATURE, 'Talla alta'],
        'no_reference' => [self::NO_REFERENCE, 'Sin referencia'],
    ];

    protected $fillable = [
        'id',
        'key',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public static function idForKey(?string $key): ?string
    {
        return $key === null ? null : (self::CATALOG[$key][0] ?? null);
    }
}
