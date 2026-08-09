<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use Modules\Setting\Services\SettingService;
use Spatie\Permission\Models\Role;

class OnboardingService
{
    private const ONBOARDING_LOGO_DIR = 'app/public/onboarding';

    private string $envPath;

    private string $settingsPath;

    public function __construct()
    {
        $this->envPath = base_path('.env');
        $this->settingsPath = storage_path('app/onboarding_settings.json');
    }

    public static function getOnboardingLogoDirectory(): string
    {
        return storage_path(self::ONBOARDING_LOGO_DIR);
    }

    private function ensureOnboardingLogoDirExists(): void
    {
        $dir = self::getOnboardingLogoDirectory();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // Estado
    public function isEnabled(): bool
    {
        $value = $this->readEnvVariable('ONBOARDING');

        return in_array(strtolower((string) $value), ['true', '1'], true);
    }

    public function getAppConfig(?string $domain = null, ?string $tenantId = null): array
    {
        $domain = parse_url($domain, PHP_URL_HOST);
        $company = $this->findCompany($domain, $tenantId);
        $jsonSettings = $this->loadSettings();

        // Determinar nombre: Company > JSON > .env > config
        $appName = $company->name
            ?? $jsonSettings['app_name']
            ?? $this->readEnvVariable('APP_NAME')
            ?? config('app.name', '');
        $appName = trim((string) $appName, '"\'') ?: 'Mi Empresa';

        // needs_onboarding: true solo cuando no existe ningún registro de empresa en BD
        $needsOnboarding = ($company === null);

        // como obtengo solo solo www.hixoryz.cm de http://www.hixoryz.cm:8080

        return [
            'app_name' => $appName,
            'logo_url' => $company->logo_url ?? null,
            'favicon_url' => $company->favicon_url ?? null,
            'needs_onboarding' => $needsOnboarding,
            'domain' => $company->domain ?? null,
            'settings' => SettingService::getPublicKeyValue(),
        ];
    }

    private function findCompany(?string $domain, ?string $tenantId = null): ?object
    {
        // Prioridad 1: por tenant_id del usuario autenticado (más preciso). Company
        // tiene su propia columna tenant_id; NO se busca por PK con un id de tenant.
        if ($tenantId !== null) {
            $company = Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
            if ($company) {
                return $company;
            }
        }

        // Prioridad 2: por dominio del host del request
        $company = Company::withoutGlobalScopes()->where('domain', 'like', '%'.$domain.'%')->first();
        if ($company) {
            return $company;
        }

        // Prioridad 3: primer registro (single-tenant / fallback universal)
        return Company::withoutGlobalScopes()->first();
    }

    public function getLogoPath(): ?string
    {
        $settings = $this->loadSettings();
        if (empty($settings['logo_path'])) {
            return null;
        }
        $this->ensureOnboardingLogoDirExists();
        $path = self::getOnboardingLogoDirectory().\DIRECTORY_SEPARATOR.$settings['logo_path'];

        return is_file($path) ? $path : null;
    }

    // Paso 1: Configuración general
    public function saveGeneral(array $data, ?UploadedFile $logo = null): void
    {
        $settings = $this->loadSettings();
        $settings['app_name'] = $data['app_name'];

        $logoStoragePath = null;

        if ($logo) {
            $this->ensureOnboardingLogoDirExists();
            $filename = 'logo.'.$logo->getClientOriginalExtension();
            $logo->storeAs('onboarding', $filename, 'public');
            $settings['logo_path'] = $filename;
            $logoStoragePath = 'onboarding/'.$filename;
        }

        $this->saveSettings($settings);

        // Actualizar APP_NAME en .env
        $this->writeEnvVariable('APP_NAME', '"'.$data['app_name'].'"');

        // Sincronizar con la tabla Company (crear o actualizar el primer registro)
        $this->syncCompany($data['app_name'], $logoStoragePath, $data['domain'] ?? null);
    }

    private function syncCompany(string $appName, ?string $logoPath, ?string $domain): void
    {
        try {
            // Usamos DB directamente para evitar el model caching
            $existing = DB::table('company')->whereNull('deleted_at')->orderBy('created_at')->first();

            $attributes = ['name' => $appName, 'updated_at' => now()];

            if ($logoPath !== null) {
                $attributes['logo'] = $logoPath;
            }

            if ($domain !== null) {
                $attributes['domain'] = $domain;
            }

            if ($existing) {
                DB::table('company')->where('id', $existing->id)->update($attributes);
            } else {
                DB::table('company')->insert(array_merge([
                    'id' => Str::uuid()->toString(),
                    'slug' => Str::slug($appName),
                    'created_at' => now(),
                ], $attributes));
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo sincronizar Company durante onboarding: '.$e->getMessage());
        }
    }

    public function testDatabaseConnection(array $data): void
    {
        $host = $data['host'];
        $port = $data['port'];
        $username = $data['username'];
        $password = $data['password'] ?? '';

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);

        unset($pdo);
    }

    public function saveDatabase(array $data): void
    {
        $host = $data['host'];
        $port = $data['port'];
        $database = $data['database'];
        $username = $data['username'];
        $password = $data['password'] ?? '';

        $this->testDatabaseConnection($data);

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        unset($pdo);

        $this->writeEnvVariable('DB_HOST', $host);
        $this->writeEnvVariable('DB_PORT', (string) $port);
        $this->writeEnvVariable('DB_DATABASE', $database);
        $this->writeEnvVariable('DB_USERNAME', $username);
        $this->writeEnvVariable('DB_PASSWORD', $password);
    }

    public function runMigrationsProcess(): void
    {
        set_time_limit(0);

        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');

        $command = escapeshellarg($phpBinary).' '.escapeshellarg($artisan).' migrate --force 2>&1';

        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        $outputText = implode("\n", $output);

        if ($returnCode !== 0) {
            Log::error('Migraciones fallaron: '.$outputText);
            throw new \RuntimeException('Error al ejecutar migraciones: '.$outputText);
        }

        Log::info('Migraciones ejecutadas correctamente.');
    }

    // Paso 3: Crear usuario administrador
    public function createAdmin(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'last_name' => $data['last_name'] ?? '',
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
            'onboarding' => 1,
            'onboarding_completed_at' => now(),
        ]);

        // Asignar rol super-admin
        try {
            if (class_exists(Role::class)) {
                $role = Role::firstOrCreate(
                    ['name' => 'super-admin', 'guard_name' => 'api']
                );
                $user->assignRole($role);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo asignar rol al admin: '.$e->getMessage());
        }

        return $user;
    }

    // Paso 4: Finalizar
    public function complete(): void
    {
        set_time_limit(0);
        $this->runSeedersAsProcess();

        // Sincronización final: si el JSON tiene datos que aún no están en Company, los aplica.
        $settings = $this->loadSettings();
        if (! empty($settings['app_name'])) {
            $logoPath = ! empty($settings['logo_path']) ? 'onboarding/'.$settings['logo_path'] : null;
            $this->syncCompany($settings['app_name'], $logoPath, null);
        }

        $this->writeEnvVariable('ONBOARDING', 'false');

        try {
            Artisan::call('config:clear');
        } catch (\Throwable $e) {
            Log::warning('No se pudo limpiar config cache: '.$e->getMessage());
        }
    }

    private function runSeedersAsProcess(): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');

        $command = escapeshellarg($phpBinary).' '.escapeshellarg($artisan).' db:seed --force 2>&1';

        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        $outputText = implode("\n", $output);

        if ($returnCode !== 0) {
            Log::warning('Seeder completado con advertencias: '.$outputText);
        } else {
            Log::info('Seeders ejecutados correctamente.');
        }
    }

    // Helpers privados
    private function writeEnvVariable(string $key, string $value): void
    {
        $content = file_get_contents($this->envPath);

        if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $content)) {
            $content = preg_replace(
                '/^'.preg_quote($key, '/').'=.*/m',
                $key.'='.$value,
                $content
            );
        } else {
            $content .= PHP_EOL.$key.'='.$value;
        }

        file_put_contents($this->envPath, $content);
    }

    private function readEnvVariable(string $key): ?string
    {
        $content = file_get_contents($this->envPath);
        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $content, $matches);

        return isset($matches[1]) ? trim($matches[1]) : null;
    }

    private function loadSettings(): array
    {
        if (! file_exists($this->settingsPath)) {
            return [];
        }
        $json = file_get_contents($this->settingsPath);

        return json_decode($json, true) ?? [];
    }

    private function saveSettings(array $settings): void
    {
        $dir = dirname($this->settingsPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    }
}
