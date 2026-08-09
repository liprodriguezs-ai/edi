<?php

namespace Modules\Tracking\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Http\Requests\TrackEventRequest;
use Modules\Tracking\Services\TrackingDispatcher;
use QCH\Core\Http\Controllers\Controller;

/**
 * POST /api/track — public, unauthenticated, throttled entry point for both
 * the landing (Astro) and the React app. Mirrors the public billing/quote
 * precedent (Modules\SubscriptionPlan\Http\Controllers\BillingQuoteController):
 * NO auth:sanctum, NO initializeTenancy, so it works for anonymous visitors
 * and mid-onboarding users alike.
 *
 * Enriches user_data SERVER-SIDE (client_ip, client_user_agent, _fbp/_fbc
 * cookies) and dispatches to the Facebook channel ONLY — GA4 is sent by gtag
 * in the browser, so dispatching it here too would double-count (GA4
 * Measurement Protocol does not deduplicate against gtag).
 */
class TrackController extends Controller
{
    /**
     * Custom-action controller (single endpoint, NOT an Orion resource) — does
     * NOT call parent::__construct(), mirroring BillingCheckoutController.
     */
    public function __construct(private readonly TrackingDispatcher $dispatcher) {}

    public function track(TrackEventRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userData = $validated['user_data'] ?? [];
        $userData['client_ip'] = $request->ip();
        $userData['client_user_agent'] = $request->userAgent();
        $userData['fbp'] ??= $request->cookie('_fbp');
        $userData['fbc'] ??= $request->cookie('_fbc');

        $event = TrackingEvent::fromArray([
            'name' => $validated['event'],
            'event_id' => $validated['event_id'],
            'event_time' => $validated['event_time'] ?? now()->timestamp,
            'source_url' => $validated['source_url'] ?? $request->headers->get('referer'),
            'action_source' => $validated['action_source'] ?? 'website',
            'params' => $validated['params'] ?? [],
            'user_data' => array_filter($userData, static fn ($value): bool => $value !== null && $value !== ''),
        ]);

        $this->dispatcher->dispatch($event, ['facebook']);

        return response()->json(['status' => 'queued', 'event_id' => $event->eventId], 202);
    }
}
