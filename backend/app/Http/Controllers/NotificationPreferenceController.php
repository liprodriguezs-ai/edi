<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationPreferenceUpdateRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service notification preferences for the authenticated user (§17.3).
 *
 * Central per-user resource (no tenant required): returns the user's preference row,
 * or a sensible everything-on default when none exists yet.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $preference = NotificationPreference::firstOrNew(['user_id' => $request->user()->id]);

        return response()->json([
            'data' => $this->present($preference),
        ]);
    }

    public function update(NotificationPreferenceUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $preference = NotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'channels' => $validated['channels'] ?? null,
                'quiet_hours_start' => $validated['quiet_hours_start'] ?? null,
                'quiet_hours_end' => $validated['quiet_hours_end'] ?? null,
            ],
        );

        return response()->json([
            'message' => 'Preferencias de notificación actualizadas.',
            'data' => $this->present($preference),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationPreference $preference): array
    {
        return [
            'channels' => $preference->channels ?? [],
            'quiet_hours_start' => $preference->quiet_hours_start,
            'quiet_hours_end' => $preference->quiet_hours_end,
        ];
    }
}
