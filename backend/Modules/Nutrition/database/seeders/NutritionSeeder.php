<?php

namespace Modules\Nutrition\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Modules\ChildRecord\Models\Sex;
use Modules\Nutrition\Models\HeightForAgeReference;
use Modules\Nutrition\Models\NutritionalStatus;
use Modules\Nutrition\Models\WeightForAgeReference;
use Modules\Nutrition\Models\WeightForHeightReference;
use RuntimeException;

/**
 * Central, idempotent seed of the nutrition module (S5): the
 * nutritional_status catalog (fixed UUIDs) and the three WHO reference
 * tables, read from csv/ (grams/millimetres; exported from legacy
 * cat_peso_talla / cat_peso_edad / cat_talla_edad). Every write is an
 * upsert on the natural key, so re-running it never duplicates rows.
 */
class NutritionSeeder extends Seeder
{
    private const SEX_IDS = [
        'male' => Sex::MALE,
        'female' => Sex::FEMALE,
    ];

    private const SD_COLUMNS = ['sd_minus3', 'sd_minus2', 'sd_minus1', 'median', 'sd_plus1', 'sd_plus2', 'sd_plus3'];

    public function run(): void
    {
        $this->seedStatuses();
        $this->seedReference(WeightForHeightReference::class, 'weight_for_height.csv', ['band', 'height_mm']);
        $this->seedReference(WeightForAgeReference::class, 'weight_for_age.csv', ['age_months']);
        $this->seedReference(HeightForAgeReference::class, 'height_for_age.csv', ['age_months']);
    }

    private function seedStatuses(): void
    {
        $rows = [];
        $order = 1;
        foreach (NutritionalStatus::CATALOG as $key => [$id, $name]) {
            $rows[] = ['id' => $id, 'key' => $key, 'name' => $name, 'sort_order' => $order++, 'deleted_at' => null];
        }

        NutritionalStatus::upsert($rows, ['id'], ['key', 'name', 'sort_order', 'deleted_at']);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $keyColumns  natural key besides sex_id
     */
    private function seedReference(string $model, string $file, array $keyColumns): void
    {
        $rows = [];
        foreach ($this->readCsv($file) as $line) {
            $row = ['sex_id' => self::SEX_IDS[$line['sex']] ?? throw new RuntimeException("Unknown sex '{$line['sex']}' in {$file}")];
            foreach ($keyColumns as $column) {
                $row[$column] = $column === 'band' ? $line[$column] : (int) $line[$column];
            }
            foreach (self::SD_COLUMNS as $column) {
                $row[$column] = (int) $line[$column];
            }
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $model::upsert($chunk, ['sex_id', ...$keyColumns], self::SD_COLUMNS);
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $file): array
    {
        $path = __DIR__.'/csv/'.$file;
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        $header = fgetcsv($handle, escape: '\\');
        $rows = [];
        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            if ($values === [null]) {
                continue;
            }
            $rows[] = array_combine($header, $values);
        }
        fclose($handle);

        return $rows;
    }
}
