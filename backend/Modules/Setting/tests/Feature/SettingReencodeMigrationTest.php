<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Setting\Models\Setting;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the `2026_08_07_120000_reencode_setting_value_as_json` data
 * migration (`setting-value-native-json`, tasks T3.2-T3.4).
 *
 * Rows are seeded via raw `DB::table('setting')->insert()` — bypassing the
 * model entirely (no cast, no `saving` guard) — so the raw bytes on disk are
 * EXACTLY the legacy pre-cutover shapes the migration is meant to repair.
 * `RefreshDatabase` already ran this migration's `up()` once (as part of
 * `migrate:fresh`) before every test method here, against an empty table —
 * a harmless no-op. Each test inserts its own legacy rows AFTER that and
 * re-invokes `up()`/`down()` directly to exercise the migration's own logic.
 */
final class SettingReencodeMigrationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * Loads the anonymous migration instance directly from disk — the
     * standard technique for exercising a specific migration's up()/down()
     * outside the normal migrate/rollback batch flow (mirrors
     * Modules/Notification/tests/Feature/EnableAmenityReservationEmailsMigrationTest.php).
     */
    private function migrationInstance(): object
    {
        return require base_path(
            'Modules/Setting/database/migrations/2026_08_07_120000_reencode_setting_value_as_json.php'
        );
    }

    /**
     * Inserts a raw `setting` row bypassing the model (no cast, no guard) so
     * `$rawValue` lands on disk byte-for-byte as given.
     */
    private function insertRawSetting(
        string $tenantId,
        string $slug,
        ?string $rawValue,
        string $type,
        bool $trashed = false,
    ): string {
        $id = (string) Str::uuid();

        DB::table('setting')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $slug,
            'slug' => $slug,
            'value' => $rawValue,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $trashed ? now() : null,
        ]);

        return $id;
    }

    private function rawValueOf(string $id): ?string
    {
        return DB::table('setting')->where('id', $id)->value('value');
    }

    // ── T3.2 — Idempotency ────────────────────────────────────────────────────

    public function test_migration_is_idempotent(): void
    {
        $tenant = $this->makeTenant('Migration Idempotency Cluster');

        $boolId = $this->insertRawSetting($tenant->id, 'legacy.bool', '1', Setting::TYPE_BOOLEAN);
        $stringId = $this->insertRawSetting($tenant->id, 'legacy.string', 'R-2026-000123', Setting::TYPE_STRING);

        $this->migrationInstance()->up();

        $afterFirstRun = [
            'bool' => $this->rawValueOf($boolId),
            'string' => $this->rawValueOf($stringId),
        ];

        // Re-running must not error and must leave the already-migrated bytes unchanged.
        $this->migrationInstance()->up();

        $this->assertSame($afterFirstRun['bool'], $this->rawValueOf($boolId));
        $this->assertSame($afterFirstRun['string'], $this->rawValueOf($stringId));
        $this->assertSame('true', $this->rawValueOf($boolId));
        $this->assertSame(json_encode('R-2026-000123'), $this->rawValueOf($stringId));
    }

    // ── T3.3 — Fail-fast census + legacy boolean spelling + bare string re-encoded ──

    /**
     * A row whose bytes disagree with its declared `type` ABORTS the migration
     * before anything is written, rather than being skipped and logged.
     *
     * Skip-and-log was the original design, and it is not safe here: production
     * runs `php artisan migrate --force` on deploy, so nobody inspects the data
     * first, and a skipped `type=string` row decodes to null afterwards —
     * `getDelinquencySettings()` then substitutes a hard-coded default that looks
     * like a saved value, and the next save persists it over the cluster's real
     * customization. Failing the deploy is the safe outcome: nothing is written,
     * and the operator gets per-type counts instead of a warning nobody reads.
     */
    public function test_type_value_disagreement_aborts_before_writing_anything(): void
    {
        $tenant = $this->makeTenant('Migration Abort Cluster');

        $badId = $this->insertRawSetting($tenant->id, 'legacy.bad.integer', 'not-a-number', Setting::TYPE_INTEGER);
        $goodId = $this->insertRawSetting($tenant->id, 'legacy.good.string', 'R-2026-000123', Setting::TYPE_STRING);

        try {
            $this->migrationInstance()->up();
            $this->fail('The migration must refuse to run when a row disagrees with its declared type.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('aborted before writing anything', $e->getMessage());
            $this->assertStringContainsString('integer=1', $e->getMessage(), 'The operator needs per-type counts.');
        }

        $this->assertSame('not-a-number', $this->rawValueOf($badId), 'The disagreeing row must be untouched.');
        $this->assertSame(
            'R-2026-000123',
            $this->rawValueOf($goodId),
            'Convertible rows must ALSO be untouched — the abort happens before any write, so the table is never left half-migrated.'
        );

        // This class does not wrap tests in a transaction ($connectionsToTransact
        // is empty), so the deliberately-corrupt row would survive into every
        // later test and abort their own up() calls. Remove it here.
        DB::table('setting')->where('id', $badId)->delete();
    }

    public function test_legacy_boolean_spelling_migrates_to_native_true(): void
    {
        $tenant = $this->makeTenant('Migration Legacy Bool Cluster');

        $one = $this->insertRawSetting($tenant->id, 'legacy.bool.one', '1', Setting::TYPE_BOOLEAN);
        $yes = $this->insertRawSetting($tenant->id, 'legacy.bool.yes', 'yes', Setting::TYPE_BOOLEAN);
        $zero = $this->insertRawSetting($tenant->id, 'legacy.bool.zero', '0', Setting::TYPE_BOOLEAN);
        $no = $this->insertRawSetting($tenant->id, 'legacy.bool.no', 'no', Setting::TYPE_BOOLEAN);

        $this->migrationInstance()->up();

        $this->assertSame('true', $this->rawValueOf($one));
        $this->assertSame('true', $this->rawValueOf($yes));
        $this->assertSame('false', $this->rawValueOf($zero));
        $this->assertSame('false', $this->rawValueOf($no));

        $tenant->run(function () use ($one, $yes, $zero, $no): void {
            $this->assertSame(true, Setting::find($one)->resolved_value);
            $this->assertSame(true, Setting::find($yes)->resolved_value);
            $this->assertSame(false, Setting::find($zero)->resolved_value);
            $this->assertSame(false, Setting::find($no)->resolved_value);
        });
    }

    public function test_bare_string_is_reencoded_and_reads_back_identical(): void
    {
        $tenant = $this->makeTenant('Migration Bare String Cluster');

        $id = $this->insertRawSetting($tenant->id, 'legacy.receipt', 'R-2026-000123', Setting::TYPE_STRING);

        $this->migrationInstance()->up();

        $this->assertSame(json_encode('R-2026-000123'), $this->rawValueOf($id));

        $tenant->run(function () use ($id): void {
            $this->assertSame('R-2026-000123', Setting::find($id)->resolved_value);
        });
    }

    // ── T3.4 — Cross-tenant / soft-deleted coverage ───────────────────────────

    public function test_soft_deleted_and_cross_tenant_rows_are_both_migrated(): void
    {
        $tenantA = $this->makeTenant('Migration Cross Tenant A');
        $tenantB = $this->makeTenant('Migration Cross Tenant B');

        $trashedId = $this->insertRawSetting($tenantA->id, 'legacy.trashed', '1', Setting::TYPE_BOOLEAN, trashed: true);
        $otherTenantId = $this->insertRawSetting($tenantB->id, 'legacy.other.tenant', 'R-2026-000999', Setting::TYPE_STRING);

        $this->migrationInstance()->up();

        $this->assertSame('true', $this->rawValueOf($trashedId), 'A soft-deleted row must still be migrated.');
        $this->assertSame(
            json_encode('R-2026-000999'),
            $this->rawValueOf($otherTenantId),
            'A row belonging to a different tenant must still be migrated (not scoped away by TenantScope).'
        );
    }

    // ── down() reversibility ───────────────────────────────────────────────────

    public function test_down_restores_bare_string_and_leaves_other_types_untouched(): void
    {
        $tenant = $this->makeTenant('Migration Down Cluster');

        $stringId = $this->insertRawSetting($tenant->id, 'legacy.receipt.down', 'R-2026-000456', Setting::TYPE_STRING);
        $boolId = $this->insertRawSetting($tenant->id, 'legacy.bool.down', '1', Setting::TYPE_BOOLEAN);

        $this->migrationInstance()->up();
        $this->assertSame(json_encode('R-2026-000456'), $this->rawValueOf($stringId));
        $this->assertSame('true', $this->rawValueOf($boolId));

        $this->migrationInstance()->down();

        $this->assertSame('R-2026-000456', $this->rawValueOf($stringId), 'down() must decode the string type back to bare text.');
        $this->assertSame('true', $this->rawValueOf($boolId), 'down() must never touch a boolean row — already valid JSON in both worlds.');
    }
}
