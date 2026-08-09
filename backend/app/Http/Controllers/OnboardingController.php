<?php

namespace App\Http\Controllers;

use App\Http\Requests\Onboarding\AdminRequest;
use App\Http\Requests\Onboarding\DatabaseRequest;
use App\Http\Requests\Onboarding\GeneralRequest;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Services\SettingService;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $service,
        private readonly SettingService $settingService
    ) {}

    // Lectura directa del .env
    public function status(): JsonResponse
    {
        return response()->json([
            'onboarding' => $this->service->isEnabled(),
        ]);
    }

    public function appConfig(Request $request): JsonResponse
    {
        $tenantId = null;
        $domain = null;

        try {
            $user = $request->user('sanctum');
            // The central User has no `tenant_id` column — its active cluster is
            // last_active_tenant_id (tenant membership is many-to-many via user_tenant).
            if ($user && ! empty($user->last_active_tenant_id)) {
                $tenantId = $user->last_active_tenant_id;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $domain = $request->server('HTTP_ORIGIN');

        $config = $this->service->getAppConfig($domain, $tenantId);

        return response()->json($config);
    }

    // POST /onboarding/general
    public function general(GeneralRequest $request): JsonResponse
    {
        try {
            $this->service->saveGeneral(
                $request->validated(),
                $request->file('logo')
            );

            return response()->json(['message' => 'Configuración general guardada.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // POST /onboarding/test-database
    public function testDatabase(DatabaseRequest $request): JsonResponse
    {
        try {
            $this->service->testDatabaseConnection($request->validated());

            return response()->json(['message' => 'Conexión exitosa.', 'success' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo conectar: '.$e->getMessage(),
                'success' => false,
            ], 422);
        }
    }

    public function database(DatabaseRequest $request): JsonResponse
    {
        try {
            $this->service->saveDatabase($request->validated());

            return response()->json(['message' => 'Credenciales guardadas. Base de datos creada.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function migrate(): JsonResponse
    {
        try {
            $this->service->runMigrationsProcess();

            return response()->json(['message' => 'Migraciones ejecutadas correctamente.']);
        } catch (\Throwable $e) {
            Log::error('onboarding migrate: '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // POST /onboarding/admin
    public function admin(AdminRequest $request): JsonResponse
    {
        try {
            $user = $this->service->createAdmin($request->validated());

            return response()->json([
                'message' => 'Administrador creado exitosamente.',
                'user' => ['id' => $user->id, 'email' => $user->email],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // POST /onboarding/complete
    public function complete(): JsonResponse
    {
        try {
            if (! $this->service->isEnabled()) {
                return response()->json(['message' => 'Configuración ya completada. Redirige al login.']);
            }

            $this->service->complete();

            return response()->json(['message' => 'Configuración completada. Redirige al login.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
