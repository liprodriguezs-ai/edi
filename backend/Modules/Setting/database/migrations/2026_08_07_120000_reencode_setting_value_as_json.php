<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Models\Setting;
use Stancl\Tenancy\Database\TenantScope;

/**
 * Re-encodes every `setting.value` row's raw bytes into valid JSON matching
 * its declared `type`, so the model's native `'value' => 'json'` cast (see
 * `Setting::casts()`) reads every row correctly.
 *
 * Design D4 / spec "Data migration correctness". Deliberately conservative:
 * a row whose bytes disagree with its declared `type` is SKIPPED and logged
 * for human review — never guessed. `type=integer`/`type=json` rows are
 * already valid JSON byte-for-byte in the overwhelming case (a digit string
 * IS valid JSON; a bare `[3,1,0]` IS valid JSON) — this migration's real
 * work is `type=string` (bare text → JSON-quoted) and legacy boolean
 * spellings (`'1'`/`'yes'`/`'0'`/`'no'` → native `true`/`false`).
 *
 * Cross-tenant + trash-aware (`withoutGlobalScope(TenantScope::class)
 * ->withTrashed()`), idempotent (an already-valid row is skipped, so a
 * second run changes zero rows), and writes via a PK-keyed
 * `DB::table('setting')->update()` — no model events, so this trusted actor
 * never trips the new `saving` guard and leaves no audit/timestamp churn.
 *
 * Plain model writes, no schema change, so the migration ->comment() column
 * rule does not apply (mirrors
 * Modules/Notification/database/migrations/2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php).
 *
 * OUT OF SCOPE for `setting-value-native-json`'s apply: actually RUNNING
 * `php artisan migrate` anywhere. Writing this file does not mutate data —
 * see the change's Phase 0 (production census) precondition.
 */
return new class extends Migration
{
    public function up(): void
    {
        self::preflightOrFail();

        $counts = ['update' => 0, 'skip' => 0, 'skip_log' => 0];

        Setting::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->orderBy('id')
            ->chunkById(200, function ($settings) use (&$counts): void {
                foreach ($settings as $setting) {
                    $type = $setting->type;
                    $decision = self::decide($setting->getRawOriginal('value'), $type);

                    $counts[$decision['action']]++;

                    self::apply($setting, $type, $decision);
                }
            });

        // The safety argument for this migration is "skip rather than guess", so the
        // skip set MUST reach a human: a skipped row keeps its legacy bytes, and a
        // legacy type=string row decodes to null — which the read path may silently
        // replace with a hard-coded default (see ConfigurationController::
        // getDelinquencySettings). One summary line makes the deploy log
        // self-describing instead of forcing an operator to grep for warnings.
        Log::info('setting-value-native-json migration finished', [
            're_encoded' => $counts['update'],
            'already_valid' => $counts['skip'],
            'skipped_for_review' => $counts['skip_log'],
        ]);
    }

    /**
     * Reverses ONLY `type=string` rows that are valid JSON strings, decoding
     * them back to the legacy bare-text format. `boolean`/`integer`/`json`
     * rows are byte-identical in both worlds (already valid JSON before this
     * migration ever touched them) and are left untouched — this is exactly
     * why `type` is retained rather than dropped.
     */
    public function down(): void
    {
        Setting::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->where('type', Setting::TYPE_STRING)
            ->orderBy('id')
            ->chunkById(200, function ($settings): void {
                foreach ($settings as $setting) {
                    $raw = $setting->getRawOriginal('value');

                    if ($raw === null) {
                        continue;
                    }

                    $decoded = json_decode($raw);

                    if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                        DB::table('setting')->where('id', $setting->id)->update(['value' => $decoded]);
                    }
                }
            });
    }

    /**
     * Self-census: refuse to run when ANY row's bytes disagree with its declared
     * `type`, BEFORE a single row is written.
     *
     * Why this is a hard abort rather than a warning. A skipped row keeps its
     * legacy bytes, and a legacy `type=string` row decodes to `null` under the new
     * cast. Two read paths then diverge, and the quiet one is the dangerous one:
     * `getPackageSettings()` renders blank, but `getDelinquencySettings()` does
     * `?->resolved_value ?? $default` — it substitutes a hard-coded default that
     * LOOKS like a saved value, and the next "Guardar" persists that default over
     * the organization's real customization. A 200 response, no error, data gone.
     *
     * Production runs `php artisan migrate --force` on deploy, so nobody gets to
     * inspect the data first. Failing the deploy is therefore the SAFE outcome:
     * Laravel wraps this migration in a transaction, so an abort leaves every row
     * exactly as it was, and the operator gets per-type counts instead of a
     * `Log::warning` nobody reads. Fix the offending rows (or teach this migration
     * their shape), then deploy again.
     *
     * @throws RuntimeException when any row would be skipped
     */
    private static function preflightOrFail(): void
    {
        /** @var array<string, int> $offenders */
        $offenders = [];
        /** @var list<string> $samples */
        $samples = [];

        Setting::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->orderBy('id')
            ->chunkById(200, function ($settings) use (&$offenders, &$samples): void {
                foreach ($settings as $setting) {
                    if (self::decide($setting->getRawOriginal('value'), $setting->type)['action'] !== 'skip_log') {
                        continue;
                    }

                    $key = $setting->type ?? 'sin tipo';
                    $offenders[$key] = ($offenders[$key] ?? 0) + 1;

                    // A bounded sample of real slugs turns "something is wrong" into
                    // "go look at THIS row"; the full set is one query away.
                    if (count($samples) < 5) {
                        $samples[] = $setting->slug.' (tenant '.$setting->tenant_id.')';
                    }
                }
            });

        if ($offenders === []) {
            return;
        }

        $detail = implode(', ', array_map(
            static fn (string $type, int $count): string => "{$type}={$count}",
            array_keys($offenders),
            $offenders
        ));

        throw new RuntimeException(
            'setting-value-native-json: aborted before writing anything — '.
            array_sum($offenders).' setting row(s) hold bytes that disagree with their declared type ('.$detail.'). '.
            'Migrating around them would leave those settings reading as null, which silently replaces organization '.
            'customizations with code defaults. Offending rows include: '.implode('; ', $samples).'.'
        );
    }

    /**
     * Single decision function shared by the preflight census and the write pass,
     * so the two can never disagree about what counts as an offending row.
     *
     * @return array{action: 'skip'|'update'|'skip_log', value?: string}
     */
    private static function decide(?string $raw, ?string $type): array
    {
        return match ($type) {
            Setting::TYPE_BOOLEAN => self::decideBoolean($raw),
            Setting::TYPE_INTEGER => self::decideScalarAlreadyJson($raw, 'integer'),
            Setting::TYPE_JSON => self::decideScalarAlreadyJson($raw, 'array'),
            Setting::TYPE_STRING => self::decideString($raw),
            default => ['action' => 'skip_log'],
        };
    }

    /**
     * @return array{action: 'skip'|'update'|'skip_log', value?: string}
     */
    private static function decideBoolean(?string $raw): array
    {
        if ($raw === null) {
            return ['action' => 'skip'];
        }

        $decoded = json_decode($raw);
        if (json_last_error() === JSON_ERROR_NONE && is_bool($decoded)) {
            return ['action' => 'skip'];
        }

        $normalized = strtolower(trim($raw));

        return match (true) {
            in_array($normalized, ['1', 'yes'], true) => ['action' => 'update', 'value' => 'true'],
            in_array($normalized, ['0', 'no'], true) => ['action' => 'update', 'value' => 'false'],
            default => ['action' => 'skip_log'],
        };
    }

    /**
     * `integer` and `json` rows are already valid JSON byte-for-byte in the
     * overwhelming case — this migration's job for them is ONLY to verify
     * that and skip; a row that fails this check disagrees with its
     * declared type and must never be guessed.
     *
     * @return array{action: 'skip'|'skip_log'}
     */
    private static function decideScalarAlreadyJson(?string $raw, string $expectedPhpType): array
    {
        if ($raw === null) {
            return ['action' => 'skip'];
        }

        $decoded = json_decode($raw, true);
        $matches = match ($expectedPhpType) {
            'integer' => json_last_error() === JSON_ERROR_NONE && is_int($decoded),
            'array' => json_last_error() === JSON_ERROR_NONE && is_array($decoded),
            default => false,
        };

        return $matches ? ['action' => 'skip'] : ['action' => 'skip_log'];
    }

    /**
     * @return array{action: 'skip'|'update'|'skip_log', value?: string}
     */
    private static function decideString(?string $raw): array
    {
        if ($raw === null) {
            return ['action' => 'skip'];
        }

        if ($raw === '') {
            // Never guess an empty string into a value — the read path already
            // degrades gracefully (cast → null) for an unmigrated row.
            return ['action' => 'skip_log'];
        }

        $decoded = json_decode($raw);
        if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
            // Known ambiguity (design D4): a bare string that IS itself a valid
            // JSON string literal (e.g. the raw bytes are literally `"hello"`)
            // is indistinguishable from an already-migrated row and is skipped.
            return ['action' => 'skip'];
        }

        return ['action' => 'update', 'value' => json_encode($raw)];
    }

    /**
     * @param  array{action: 'skip'|'update'|'skip_log', value?: string}  $decision
     */
    private static function apply(Setting $setting, string $type, array $decision): void
    {
        if ($decision['action'] === 'update') {
            DB::table('setting')->where('id', $setting->id)->update(['value' => $decision['value']]);

            return;
        }

        if ($decision['action'] === 'skip_log') {
            Log::warning('setting-value-native-json migration: type/value disagreement, row skipped', [
                'id' => $setting->id,
                'tenant_id' => $setting->tenant_id,
                'slug' => $setting->slug,
                'type' => $type,
            ]);
        }
    }
};
