<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityBlackout;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S3 — amenity domain model: amenity / amenity_slot / amenity_blackout are
 * tenant-scoped (BaseModel::columns($table, true) + BelongsToTenant); money
 * columns (cost_amount/deposit_amount) round-trip via TenantMoneyCast in
 * minor units; the amenity has a `gallery` media collection; `(tenant_id,
 * name)` is unique per tenant.
 */
final class AmenityDomainModelTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function fakeImageFile(string $name = 'photo.jpg'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'amenity_gallery_test_');
        $this->tempFiles[] = $tmpPath;

        // Minimal valid JPEG header bytes so Spatie's MIME sniffing accepts it.
        $jpegHeader = "\xFF\xD8\xFF\xE0".str_repeat('x', 1000);
        file_put_contents($tmpPath, $jpegHeader);

        return new UploadedFile($tmpPath, $name, 'image/jpeg', null, true);
    }

    private function baseAmenityAttributes(): array
    {
        return [
            'name' => 'Salón de eventos '.uniqid(),
            'capacity' => 40,
            'available_weekdays' => [1, 2, 3, 4, 5],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ];
    }

    // =========================================================================
    // Migrations run + basic model creation
    // =========================================================================

    public function test_amenity_slot_and_blackout_tables_exist_and_are_tenant_scoped(): void
    {
        foreach (['amenity', 'amenity_slot', 'amenity_blackout'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} must exist.");
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'), "Table {$table} must be tenant-scoped (tenant_id).");
        }
    }

    public function test_amenity_slot_and_blackout_belong_to_an_amenity(): void
    {
        $tenant = $this->makeTenant('Amenity Domain Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $amenity = Amenity::create($this->baseAmenityAttributes());

            $slot = AmenitySlot::create([
                'amenity_id' => $amenity->id,
                'weekday' => 2,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
            ]);

            $blackout = AmenityBlackout::create([
                'amenity_id' => $amenity->id,
                'start_date' => now()->addDays(5)->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'reason' => 'Mantenimiento',
            ]);

            $this->assertSame($amenity->id, $slot->amenity->id);
            $this->assertSame($amenity->id, $blackout->amenity->id);
            $this->assertSame(1, $amenity->slots()->count());
            $this->assertSame(1, $amenity->blackouts()->count());
        });
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_amenity_is_tenant_scoped_and_isolated_between_tenants(): void
    {
        $tenantA = $this->makeTenant('Amenity Tenant A');
        $tenantB = $this->makeTenant('Amenity Tenant B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            Amenity::create($this->baseAmenityAttributes());
            $this->assertSame(1, Amenity::count());
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Amenity::count(), 'Tenant B must not see Tenant A amenities.');
        });
    }

    // =========================================================================
    // Unique (tenant_id, name)
    // =========================================================================

    public function test_amenity_name_is_unique_per_tenant(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant('Amenity Unique Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $attributes = $this->baseAmenityAttributes();
            Amenity::create($attributes);
            Amenity::create($attributes); // same tenant, same name → must violate the unique index
        });
    }

    public function test_creating_an_amenity_with_a_soft_deleted_amenitys_name_succeeds(): void
    {
        $tenant = $this->makeTenant('Amenity Reused Name Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $attributes = $this->baseAmenityAttributes();

            $original = Amenity::create($attributes);
            $original->delete();

            $reused = Amenity::create($attributes); // same tenant, same name, but the original is soft-deleted

            $this->assertSame($attributes['name'], $reused->name);
            $this->assertSame(1, Amenity::count(), 'Only the alive amenity must be visible.');
        });
    }

    public function test_creating_an_amenity_with_a_still_active_amenitys_name_fails(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant('Amenity Active Duplicate Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $attributes = $this->baseAmenityAttributes();

            Amenity::create($attributes); // stays alive, not soft-deleted
            Amenity::create($attributes); // same tenant, same name → must violate the partial unique index
        });
    }

    public function test_same_amenity_name_is_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Amenity Cross Tenant A');
        $tenantB = $this->makeTenant('Amenity Cross Tenant B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $sharedName = 'Alberca Común';

        $tenantA->run(function () use ($sharedName): void {
            Amenity::create(array_merge($this->baseAmenityAttributes(), ['name' => $sharedName]));
        });

        $tenantB->run(function () use ($sharedName): void {
            $amenity = Amenity::create(array_merge($this->baseAmenityAttributes(), ['name' => $sharedName]));
            $this->assertSame($sharedName, $amenity->name);
        });
    }

    // =========================================================================
    // Money casts (minor units round-trip)
    // =========================================================================

    public function test_money_columns_round_trip_as_minor_units(): void
    {
        $tenant = $this->makeTenant('Amenity Money Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $amenity = Amenity::create(array_merge($this->baseAmenityAttributes(), [
                'has_cost' => true,
                'cost_amount' => 150000, // $1,500.00 in minor units
                'requires_deposit' => true,
                'deposit_amount' => 50000, // $500.00 in minor units
                'deposit_due_days' => 3,
            ]));

            $fresh = Amenity::findOrFail($amenity->id);

            $this->assertSame('150000', $fresh->cost_amount->getAmount());
            $this->assertSame('50000', $fresh->deposit_amount->getAmount());
        });
    }

    public function test_money_columns_are_nullable_when_cost_and_deposit_not_required(): void
    {
        $tenant = $this->makeTenant('Amenity Free Money Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $amenity = Amenity::create($this->baseAmenityAttributes());

            $this->assertNull($amenity->cost_amount);
            $this->assertNull($amenity->deposit_amount);
        });
    }

    // =========================================================================
    // Gallery media collection
    // =========================================================================

    public function test_amenity_gallery_media_collection_is_registered(): void
    {
        $tenant = $this->makeTenant('Amenity Gallery Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $amenity = Amenity::create($this->baseAmenityAttributes());

            $collectionNames = $amenity->getRegisteredMediaCollections()->pluck('name');
            $this->assertTrue($collectionNames->contains('gallery'), 'Amenity must register a gallery media collection.');
        });
    }

    public function test_amenity_gallery_accepts_multiple_images(): void
    {
        $tenant = $this->makeTenant('Amenity Gallery Upload Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $amenity = Amenity::create($this->baseAmenityAttributes());

            $amenity->addMedia($this->fakeImageFile('foto1.jpg'))->toMediaCollection('gallery');
            $amenity->addMedia($this->fakeImageFile('foto2.jpg'))->toMediaCollection('gallery');

            $amenity->refresh();
            $media = $amenity->getMedia('gallery');

            $this->assertSame(2, $media->count(), 'Gallery must accept multiple images (not singleFile).');
            $this->assertSame('gallery', $media->first()->collection_name);
        });
    }
}
