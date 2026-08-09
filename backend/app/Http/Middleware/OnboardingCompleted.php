<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnboardingCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $value = $this->readOnboardingFromEnv();
        $isEnabled = in_array(strtolower((string) $value), ['true', '1'], true);

        if (! $isEnabled) {
            return response()->json([
                'message' => 'El onboarding ya fue completado.',
            ], 403);
        }

        return $next($request);
    }

    private function readOnboardingFromEnv(): ?string
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            return 'false';
        }
        $content = file_get_contents($envPath);
        preg_match('/^ONBOARDING=(.*)$/m', $content, $matches);

        return isset($matches[1]) ? trim($matches[1]) : 'false';
    }
}
