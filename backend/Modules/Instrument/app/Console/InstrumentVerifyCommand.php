<?php

namespace Modules\Instrument\Console;

use Illuminate\Console\Command;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Services\InstrumentContentHasher;
use Modules\Instrument\Services\InstrumentVerificationService;

/**
 * `php artisan instrument:verify {version-key}` (design §7): runs every
 * check (V1-V10) against a version, prints every failure, exits 1 if any (0
 * otherwise). Used as a CI gate (Feature test) for both seeded editions.
 */
class InstrumentVerifyCommand extends Command
{
    protected $signature = 'instrument:verify {version : instrument_version key}';

    protected $description = 'Validate an EDI instrument version against all structural/content invariants (design §7)';

    public function handle(): int
    {
        $key = (string) $this->argument('version');

        $version = InstrumentVersion::query()->where('key', $key)->first();

        if ($version === null) {
            $this->error("instrument_version with key '{$key}' not found.");

            return self::FAILURE;
        }

        $verifier = new InstrumentVerificationService(new InstrumentContentHasher);
        $failures = $verifier->verify($version);

        if ($failures === []) {
            $this->info("instrument:verify {$key}: OK (all checks passed).");

            return self::SUCCESS;
        }

        $this->error("instrument:verify {$key}: FAILED (".count($failures).' failure(s)):');
        foreach ($failures as $failure) {
            $this->line("  - {$failure}");
        }

        return self::FAILURE;
    }
}
