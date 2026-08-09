<?php

namespace Modules\User\Http\Middleware;

use App\Models\TermsAcceptance;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side Terms & Conditions enforcement (§18.10 — global-08).
 *
 * Lightweight gate for WRITE endpoints: when the legal document version is bumped
 * (config legal.terms_version), users that accepted an OLDER version get 426
 * (Upgrade Required) with code TERMS_OUTDATED until they re-accept via
 * POST /api/auth/accept-terms. Read methods (GET/HEAD/OPTIONS) always pass so the
 * user can keep consulting their data while the frontend shows the re-acceptance
 * gate (terms_accepted=false in /api/auth/me).
 *
 * Deliberate leniency: a user with ZERO recorded acceptances is NOT blocked —
 * first-time acceptance is funneled by registration/onboarding and the /me flag,
 * and blocking would lock out pre-feature accounts. Enforcement targets version
 * bumps, where the user demonstrably consented to a now-stale document.
 *
 * Adoption pattern for other modules: the alias `termsAccepted` is registered by
 * UserServiceProvider::boot(). Append it to any write route or Orion resource:
 *
 *     ->middleware(['auth:sanctum', 'initializeTenancy', 'termsAccepted'])
 *
 * It is safe on full resources (reads are skipped) and must run AFTER
 * auth:sanctum so the user is resolved.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($request->is('api/auth/accept-terms')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $this->acceptanceIsOutdated($user)) {
            return response()->json([
                'code' => 'TERMS_OUTDATED',
                'message' => 'Debes aceptar la versión vigente de los Términos y Condiciones para continuar.',
                'current_version' => (string) config('legal.terms_version', '1.0'),
            ], 426);
        }

        return $next($request);
    }

    /**
     * Outdated = the user accepted SOME version, but not the current one.
     */
    private function acceptanceIsOutdated(User $user): bool
    {
        if ($user->hasAcceptedCurrentTerms()) {
            return false;
        }

        return TermsAcceptance::query()->where('user_id', $user->id)->exists();
    }
}
