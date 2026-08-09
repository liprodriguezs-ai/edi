<?php

namespace App\Http\Controllers;

use App\Models\Scopes\UserTenantScope;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\SubscriptionStateService;
use Exception;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Company\Services\CompanyService;
use Modules\Email\Jobs\SendAccountOwnerNotificationJob;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Tenant\Models\Tenant;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Services\TrackingDispatcher;
use Modules\User\Services\UserInvitationService;
use QCH\Core\Models\Role;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class AuthController extends Controller
{
    /**
     * Maximum concurrent sessions (devices) per user (§5.4 r6).
     */
    private const MAX_ACTIVE_SESSIONS = 3;

    /**
     * Register a User.
     *
     * @return JsonResponse
     */
    public function register()
    {
        $conn = config('database.default', 'central');
        $validator = Validator::make(request()->all(), [
            'name' => 'required',
            // Human-readable name of the residential cluster being registered.
            // It lands verbatim in tenant.name, which is what the UI shows as the
            // cluster label everywhere (site header, cluster switcher, resident
            // home) and what CompanyService::ensureMainCompanyForTenant later
            // copies into the main company on the first authenticated request.
            'cluster_name' => 'required|string|max:255',
            'email' => 'required|email|unique:'.$conn.'.user,email',
            'password' => 'required|confirmed|min:8',
            'tenant_id' => 'nullable',
            'birthdate' => 'nullable',
            // Optional tracking dedup (CompleteRegistration): shared with the
            // browser's fbq/gtag hit so Meta/GA4 don't double-count. Absent for
            // callers that predate tracking (e.g. older app builds).
            'event_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            Log::error('Register prospect failed', [
                'errors' => $validator->errors(),
            ]);

            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
            // return response()->json($validator->errors()->toJson(), 400);
        }

        $validated = $validator->validated();

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'birthdate' => $validated['birthdate'] ?? null,
            ]);

            // The cluster is named by the person registering it. tenant.name is a
            // display label, not an identifier: it carries no unique constraint,
            // so two clusters may legitimately share a name ("Villas del Sol").
            // Slug collisions are handled downstream where a slug is actually
            // required (CompanyService suffixes company.slug with the tenant key).
            //
            // Born pendiente_pago with ZERO entitlements: registration no longer
            // grants a local trial. The owner is always routed to /select-plan
            // (signup-form.tsx) and the trial now lives in the Stripe Checkout
            // session (MultiItemSubscriptionService::buildCheckout), which is the
            // ONLY thing that can unblock this tenant. SubscriptionStateService
            // reads this as status='none', is_blocked=true — the same "never
            // chose a plan" state /select-plan already renders.
            $tenant = Tenant::create([
                'name' => trim($validated['cluster_name']),
                'status' => Tenant::STATUS_PENDIENTE_PAGO,
            ]);

            $user->tenants()->syncWithoutDetaching([$tenant->id]);
            $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

            DB::commit();

            // Tracking: CompleteRegistration, Facebook ONLY. The browser
            // (useSession.register) already fires CompleteRegistration + StartTrial
            // to gtag/fbq/`/track` using this SAME event_id, so a server-side GA4
            // hit here would double-count (GA4 MP does not dedup against gtag) and
            // a server StartTrial has no dedup key at all. Facebook is kept
            // server-side as an adblocker-resilient CAPI backup, reusing the
            // client's event_id so Meta deduplicates the browser + server hit.
            // Isolated in its own try/catch — a tracking failure must never break
            // registration.
            try {
                $this->dispatchRegistrationTracking($user, $validated['event_id'] ?? null);
            } catch (Exception $e) {
                report($e);
            }

            SendAccountOwnerNotificationJob::dispatch($user);

            $tokens = $this->generateTokens($user, $this->getPermissions($user));
            $tenant = $tenant->load(['payment_type', 'billing_package']);

            $response = response()->json([
                'data' => [
                    'user' => $user,
                    'abilities' => $this->getPermissions($user),
                    'roles' => $user->getRoleNames(),
                    'access_token' => $tokens['access_token'],
                    'expires_in' => config('sanctum.expiration_access_minutes', 60) * 60,
                    'tenant' => $this->presentTenant($tenant),
                    'subscription' => $this->getSubscriptionState($user, $tenant),
                ],
            ]);

            return $this->attachRefreshCookie($response, $tokens['refresh_token']);
        } catch (Exception $exception) {
            report($exception);
            DB::rollBack();
            throw $exception;
        }
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return JsonResponse
     */
    public function login()
    {
        try {
            $credentials = request(['email', 'password']);

            // Uniform 401 for every failure (unknown email, wrong password,
            // passwordless account): no status-code oracle, no unsolicited
            // reset emails (sec-authn-09). Passwordless users go through
            // auth/forgot-password, which is already anti-enumeration.
            if (! auth()->attempt($credentials)) {
                if (! auth()->attempt(['code' => $credentials['email'], 'password' => $credentials['password']])) {
                    return response()->json(['error' => 'Unauthorized'], 401);
                }
            }

            $user = $this->getUser(auth()->user());

            // Block users that are not allowed to log in (data-only residents).
            if (! $user->auth_active) {
                auth()->logout();

                return response()->json(['error' => 'Cuenta desactivada'], 403);
            }
            $tokens = $this->generateTokens($user, $this->getPermissions(auth()->user()));

            // Resolve the active tenant using the same logic as InitializeTenancyFromUser.
            // For login we use last_active_tenant_id (if set) or first tenant.
            $tenant = null;
            if ($user->last_active_tenant_id) {
                $tenant = $user->tenants()->with(['payment_type', 'billing_package'])->where('tenant.id', $user->last_active_tenant_id)->first();
            }
            $tenant = $tenant ?? $user->tenants()->with(['payment_type', 'billing_package'])->first();

            // Return all tenants the user belongs to for multi-cluster UX.
            $allTenants = $user->tenants()->with(['billing_package'])->get();

            $response = response()->json([
                'data' => [
                    'user' => $user,
                    'abilities' => $this->getPermissions($user),
                    'access_token' => $tokens['access_token'],
                    'expires_in' => config('sanctum.expiration_access_minutes', 60) * 60,
                    'roles' => $user->getRoleNames(),
                    'tenant' => $this->presentTenant($tenant),
                    'tenants' => $allTenants,
                    'subscription' => $this->getSubscriptionState($user, $tenant),
                ],
            ]);

            return $this->attachRefreshCookie($response, $tokens['refresh_token']);
        } catch (Exception $exception) {
            report($exception);
            throw new Exception($exception->getMessage());
        }
    }

    public function getPermissions($user)
    {
        return $user->getPermissionsViaRoles()->pluck('name')->toArray();
    }

    /**
     * Active-tenant payload plus the cluster logo the sidebar renders.
     *
     * Only the ACTIVE tenant is enriched: the `tenants` list would cost one
     * extra media lookup per row, and nothing renders a logo for the inactive
     * clusters.
     *
     * @return array<string, mixed>|null
     */
    private function presentTenant(?Tenant $tenant): ?array
    {
        if (! $tenant) {
            return null;
        }

        return $tenant->toArray() + [
            'logo_url' => app(CompanyService::class)->logoUrlForTenant($tenant),
        ];
    }

    public function getUser($user)
    {

        return $user;
    }

    /**
     * Get the authenticated User.
     *
     * @return JsonResponse
     */
    public function me()
    {
        $user = $this->getUser(auth()->user());

        // Active tenant: last_active_tenant_id first, then first tenant.
        $tenant = null;
        if ($user->last_active_tenant_id) {
            $tenant = $user->tenants()->with(['payment_type', 'billing_package'])->where('tenant.id', $user->last_active_tenant_id)->first();
        }
        $tenant = $tenant ?? $user->tenants()->with(['payment_type', 'billing_package'])->first();

        // All tenants for multi-cluster UX.
        $allTenants = $user->tenants()->with(['billing_package'])->get();

        return response()->json([
            'data' => [
                'tenant' => $this->presentTenant($tenant),
                'tenants' => $allTenants,
                'user' => $user,
                'abilities' => $this->getPermissions($user),
                'roles' => $user->getRoleNames(),
                'subscription' => $this->getSubscriptionState($user, $tenant),
                'terms_accepted' => $user->hasAcceptedCurrentTerms(),
                'terms_version' => (string) config('legal.terms_version', '1.0'),
            ],
        ]);
    }

    /**
     * Record the authenticated user's acceptance of the current Terms & Conditions
     * version (§18.10, §5). Idempotent for the same version; a new version inserts a
     * fresh row. Does NOT gate any routes — recording + exposure only.
     */
    public function acceptTerms(Request $request): JsonResponse
    {
        $user = $request->user();
        $version = (string) config('legal.terms_version', '1.0');

        $acceptance = TermsAcceptance::record($user->id, $version, $request->ip());

        return response()->json([
            'message' => 'Términos y condiciones aceptados.',
            'data' => [
                'version' => $acceptance->version,
                'accepted_at' => $acceptance->accepted_at,
                'terms_accepted' => true,
            ],
        ]);
    }

    /**
     * Resolve the cluster's access state. Thin delegate to SubscriptionStateService
     * — the SINGLE source of truth shared with the EnsureSubscriptionActive
     * middleware, so what the auth responses report and what the API enforces can
     * never drift. See SubscriptionStateService for the full decision logic.
     *
     * @return array<string, mixed>
     */
    protected function getSubscriptionState($user, $tenant): array
    {
        return app(SubscriptionStateService::class)->narrativeFor($user, $tenant);
    }

    /**
     * Dispatch CompleteRegistration tracking server-side, Facebook ONLY.
     *
     * The browser (useSession.register) already fires BOTH CompleteRegistration
     * and StartTrial to gtag (GA4) + fbq (Pixel) + POST /api/track (Facebook
     * CAPI), all sharing ONE client-generated event_id. Re-dispatching to GA4
     * here would double-count (GA4 Measurement Protocol does not dedup against
     * gtag), and there is no browser-side StartTrial dedup key to reuse — so
     * this server hook covers Facebook ONLY, as an adblocker-resilient backup
     * for the browser's Pixel hit, reusing the SAME event_id so Meta
     * deduplicates the browser + server hit into one event.
     */
    private function dispatchRegistrationTracking(User $user, ?string $completeRegistrationEventId): void
    {
        $userData = array_filter([
            'email' => $user->email,
            'external_id' => (string) $user->id,
        ]);

        app(TrackingDispatcher::class)->dispatch(TrackingEvent::fromArray([
            'name' => 'CompleteRegistration',
            'event_id' => $completeRegistrationEventId,
            // Facebook's action_source enum has NO "system" value — the valid
            // server-originated value is "system_generated".
            'action_source' => 'system_generated',
            'user_data' => $userData,
        ]), ['facebook']);
    }

    /**
     * Log the user out: revokes the access token AND the refresh token
     * (sec-authn-05), then clears the refresh cookie. If the refresh cookie
     * identifies this device's refresh token, only that one is deleted
     * (per-device logout); otherwise ALL of the user's refresh tokens are
     * revoked so none survives an explicit logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $accessToken = $user?->currentAccessToken();
        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        if ($user) {
            $this->revokeRefreshTokenFor($user, $request);
        }

        $response = response()->json(['message' => 'Successfully logged out']);

        return $this->forgetRefreshCookie($response);
    }

    protected function revokeRefreshTokenFor(User $user, Request $request): void
    {
        $cookieName = $this->refreshCookieConfig()['name'] ?? 'refresh_token';
        $refreshPlain = $request->cookie($cookieName);

        $refreshToken = is_string($refreshPlain) ? PersonalAccessToken::findToken($refreshPlain) : null;

        if ($refreshToken && $refreshToken->name === 'refresh_token' && $refreshToken->tokenable()->is($user)) {
            $refreshToken->delete();

            return;
        }

        $user->tokens()->where('name', 'refresh_token')->delete();
    }

    /**
     * Refresh tokens. Accepts refresh token from cookie (preferred) or Bearer. Rotates and returns new access_token; sets new refresh cookie.
     */
    public function refresh(Request $request): JsonResponse
    {
        $config = $this->refreshCookieConfig();
        $cookieName = $config['name'] ?? 'refresh_token';
        $refreshTokenPlain = $request->cookie($cookieName);

        if ($refreshTokenPlain) {
            $accessToken = PersonalAccessToken::findToken($refreshTokenPlain);
            if (! $accessToken || $accessToken->name !== 'refresh_token') {
                return response()->json(['message' => 'Invalid refresh token'], 401);
            }

            $user = $accessToken->tokenable;
            if (! $user instanceof User) {
                return response()->json(['message' => 'Invalid refresh token'], 401);
            }

            // A deactivated user must not mint fresh access tokens. Revoke every
            // token so the session ends now, not when the refresh token expires.
            if (! $user->auth_active) {
                $user->tokens()->delete();

                return response()->json(['message' => 'Cuenta desactivada'], 403);
            }

            $accessToken->delete();
            $tokens = $this->generateTokens($user, $this->getPermissions($user));

            $response = response()->json([
                'data' => [
                    'access_token' => $tokens['access_token'],
                    'expires_in' => config('sanctum.expiration_access_minutes', 60) * 60,
                    'token_type' => 'bearer',
                ],
            ]);

            return $this->attachRefreshCookie($response, $tokens['refresh_token']);
        }

        $bearerToken = $request->bearerToken();
        if (! $bearerToken) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($bearerToken);
        if (! $accessToken || $accessToken->name !== 'refresh_token') {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $user = $accessToken->tokenable;
        if (! $user instanceof User) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        // A deactivated user must not mint fresh access tokens. Revoke every
        // token so the session ends now, not when the refresh token expires.
        if (! $user->auth_active) {
            $user->tokens()->delete();

            return response()->json(['message' => 'Cuenta desactivada'], 403);
        }

        $accessToken->delete();
        $tokens = $this->generateTokens($user, $this->getPermissions($user));

        return response()->json([
            'data' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => config('sanctum.expiration_access_minutes', 60) * 60,
                'token_type' => 'bearer',
            ],
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    protected function generateTokens(User $user, array $abilities): array
    {
        $accessMinutes = config('sanctum.expiration_access_minutes', 60);
        $refreshMinutes = config('sanctum.expiration_refresh_minutes', 10080);

        $accessToken = $user->createToken(
            'auth_token',
            $abilities,
            now()->addMinutes($accessMinutes)
        )->plainTextToken;

        $refreshToken = $user->createToken(
            'refresh_token',
            ['refresh'],
            now()->addMinutes($refreshMinutes)
        )->plainTextToken;

        $this->pruneExcessTokens($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * Device cap (§5.4 r6): a user may hold at most 3 active sessions; the
     * 4th login displaces the oldest. Keeps the newest N access and refresh
     * tokens and deletes the rest — no extra table needed (auth-04).
     */
    protected function pruneExcessTokens(User $user): void
    {
        foreach (['auth_token', 'refresh_token'] as $name) {
            $keepIds = $user->tokens()
                ->where('name', $name)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::MAX_ACTIVE_SESSIONS)
                ->pluck('id');

            $user->tokens()
                ->where('name', $name)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }

    protected function refreshCookieConfig(): array
    {
        return config('sanctum.refresh_cookie', []);
    }

    protected function attachRefreshCookie(JsonResponse $response, string $refreshToken): JsonResponse
    {
        $config = $this->refreshCookieConfig();
        $name = $config['name'] ?? 'refresh_token';
        $path = $config['path'] ?? '/api';
        $domain = $config['domain'] ?? null;
        $secure = (bool) ($config['secure'] ?? false);
        $httpOnly = (bool) ($config['http_only'] ?? true);
        $sameSite = $config['same_site'] ?? 'lax';
        $maxAge = config('sanctum.expiration_refresh_minutes', 10080) * 60;

        $cookie = new SymfonyCookie(
            $name,
            $refreshToken,
            $maxAge > 0 ? time() + $maxAge : 0,
            $path,
            $domain,
            $secure,
            $httpOnly,
            false,
            $sameSite
        );

        return $response->cookie($cookie);
    }

    protected function forgetRefreshCookie(JsonResponse $response): JsonResponse
    {
        $config = $this->refreshCookieConfig();
        $name = $config['name'] ?? 'refresh_token';
        $path = $config['path'] ?? '/api';
        $domain = $config['domain'] ?? null;

        return $response->cookie(Cookie::forget($name, $path, $domain));
    }

    /**
     * Send password reset link. Same response whether email exists or not (no user enumeration).
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))
            ->orWhere('code', $request->input('email'))
            ->first();

        if ($user) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json([
            'message' => 'Si existe una cuenta con ese correo, recibirás un enlace para restablecer tu contraseña.',
        ], 200);
    }

    /**
     * Reset password with token. Rate limited and validated.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 400);
        }

        return response()->json([
            'message' => __('Tu contraseña se ha restablecido correctamente.'),
        ], 200);
    }

    /**
     * Verify invitation token (public). Returns valid + email or valid false + message.
     */
    public function verifyInvitationToken(Request $request, UserInvitationService $invitationService): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $invitation = $invitationService->findValidByToken($request->input('token'));

        if ($invitation === null) {
            return response()->json([
                'valid' => false,
                'message' => __('Token inválido o expirado.'),
            ], 200);
        }

        // account_exists drives the activation screen: a person who already has a
        // password logs in to join instead of setting a new one. We expose only the
        // boolean — never which tenant the existing account belongs to.
        $accountExists = User::withoutGlobalScope(UserTenantScope::class)
            ->where('email', $invitation->email)
            ->whereNotNull('password')
            ->exists();

        $payload = [
            'valid' => true,
            'email' => $invitation->email,
            'account_exists' => $accountExists,
            'expires_at' => optional($invitation->expires_at)->toIso8601String(),
        ];

        // Resident invitations carry richer context so the activation copy can name
        // the cluster, the unit and the relationship the person is joining.
        if ($invitation->inviteable_type === Unit::class) {
            $tenant = Tenant::find($invitation->tenant_id);
            if ($tenant !== null) {
                $payload['cluster_name'] = $tenant->name;
                $tenant->run(function () use ($invitation, &$payload): void {
                    $unit = Unit::find($invitation->inviteable_id);
                    $relationship = $invitation->unit_relationship_id
                        ? UnitRelationship::find($invitation->unit_relationship_id)
                        : null;
                    $inviter = $invitation->invited_by_user_id
                        ? User::withoutGlobalScope(UserTenantScope::class)->find($invitation->invited_by_user_id)
                        : null;

                    $payload['unit_label'] = $unit?->full_identifier;
                    $payload['relationship_name'] = $relationship?->name;
                    $payload['inviter_name'] = $inviter?->name;
                });
            }
        }

        return response()->json($payload, 200);
    }

    /**
     * Accept invitation with password (public). Creates user, links inviteable, deletes invitation.
     */
    public function acceptInvitation(Request $request, UserInvitationService $invitationService): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $invitationService->acceptInvitation(
                $request->input('token'),
                $request->input('password')
            );
        } catch (\InvalidArgumentException $e) {
            // 409 = the email already has an account; the person must log in to join
            // (the authenticated confirm flow), so surface that message specifically.
            if ($e->getCode() === 409) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return response()->json(['message' => __('Token inválido o expirado.')], 400);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => __('Token inválido o expirado.')], 400);
        }

        return response()->json([
            'message' => __('Cuenta completada. Inicia sesión con tu correo y contraseña.'),
        ], 200);
    }

    /**
     * Accept a resident invitation with an EXISTING account (protected). The user
     * must already be authenticated as the invited email; we link them to the unit
     * without creating a new account or asking for a new password.
     */
    public function acceptInvitationConfirm(Request $request, UserInvitationService $invitationService): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $user = auth()->guard('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => __('No autenticado.')], 401);
        }

        try {
            $invitationService->acceptInvitationWithAccount($request->input('token'), $user);
        } catch (\InvalidArgumentException $e) {
            $status = $e->getCode() === 403 ? 403 : 400;

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('Token inválido o expirado.'),
            ], $status);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => __('Token inválido o expirado.')], 400);
        }

        return response()->json([
            'message' => __('Te uniste a la unidad correctamente.'),
        ], 200);
    }

    // verifica si el token es valido o ya caduco

    public function verifyResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
        ]);

        // Buscar el usuario por email
        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'response' => false,
                'message' => 'El token es inválido o ha caducado.',
            ], 200);
        }

        // Broker para validar el token
        if (! Password::tokenExists($user, $request->token)) {
            return response()->json([
                'response' => false,
                'message' => 'El token es inválido o ha caducado.',
            ], 200);
        }

        return response()->json([
            'response' => true,
            'message' => 'El token es válido.',
        ], 200);
    }

    /**
     * Marks the welcome onboarding (§5.3.5 — slides shown once after the
     * first login) as completed for the authenticated user. Idempotent: the
     * first completion timestamp wins.
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->onboarding_completed_at === null) {
            $user->update(['onboarding_completed_at' => now()]);
        }

        return response()->json([
            'message' => 'Onboarding completado.',
            'data' => [
                'onboarding_completed_at' => $user->onboarding_completed_at,
            ],
        ]);
    }
}
