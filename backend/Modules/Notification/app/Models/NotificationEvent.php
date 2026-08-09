<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of all notifiable events in EDI.
 *
 * Rows are seeded once by CatalogSeeder::seedNotificationEvents() and
 * are read-only for tenant/organization roles. Code references events by the
 * stable UUID constants (e.g. NotificationEvent::PAYMENT_DUE_REMINDER).
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property string $audience 'resident'|'board'
 * @property bool $supports_email
 * @property bool $supports_push
 * @property bool $supports_whatsapp
 * @property array<int, string> $default_channels
 * @property int $order
 * @property bool $is_active
 * @property bool $is_mandatory
 * @property bool $is_hidden
 */
#[ObservedBy([GlobalObserver::class])]
class NotificationEvent extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'notification_event';

    // ── Stable UUID constants ─────────────────────────────────────────────────

    /** Reminder sent to resident before a charge becomes overdue. */
    public const PAYMENT_DUE_REMINDER = 'b7e1a2d3-4c5f-4a6b-8c7d-9e0f1a2b3c4d';

    /** Notification to the board when a resident submits a payment for review. */
    public const PAYMENT_REGISTERED = 'c8f2b3e4-5d6a-4b7c-9d8e-0f1a2b3c4d5e';

    /** Confirmation sent to resident when the admin directly registers a payment on their behalf. */
    public const PAYMENT_REGISTERED_DIRECT = 'a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5';

    /** Confirmation sent to resident when the board accepts their payment. */
    public const PAYMENT_ACCEPTED = 'd9a3c4f5-6e7b-4c8d-ae9f-1a2b3c4d5e6f';

    /** Notification sent to resident when the board rejects their payment (with reason). */
    public const PAYMENT_REJECTED = 'ea4bd5a6-7f8c-4d9e-bf0a-2b3c4d5e6f7a';

    /** Alert sent to resident when a charge transitions to overdue. */
    public const CHARGE_OVERDUE = 'fb5ce6b7-8a9d-4e0f-80ab-3c4d5e6f7a8b';

    /** Notification sent to resident when a new charge batch is issued for their unit. */
    public const CHARGE_CREATED = 'ac6df7c8-9b0e-4f1a-912c-4d5e6f7a8b9c';

    /** Alert sent to resident (billing owner) when a visitor arrives at the gate. */
    public const VISITOR_AT_GATE = 'ce8fb9ea-1d2a-4b3c-b34e-6f7a8b9c0d1e';

    /** Notification to the board when a new board period is opened. */
    public const NEW_BOARD = 'df9acafb-2e3b-4c4d-845f-7a8b9c0d1e2f';

    /** Notification sent to the organization administrator(s) when a module trial expires. */
    public const TRIAL_EXPIRED = '2f3a4b5c-6d7e-4f8a-9b0c-1d2e3f4a5b6c';

    /** Reminder to a resident that their unit has a pending contribution on a voluntary project. */
    public const PROJECT_CONTRIBUTION_REMINDER = '0b975946-3488-4c4c-af04-05314acaf8ab';

    // ── Amenity Reservations (S10) ────────────────────────────────────────────

    /** Notification to admin/board when a resident creates a new amenity reservation. */
    public const AMENITY_RESERVATION_CREATED = 'dd670ac6-a9ab-4f71-bb35-c865555fabf0';

    /** Notification to resident when their reservation is approved. */
    public const AMENITY_RESERVATION_APPROVED = 'c7d5496e-c4fe-4858-9a77-fcc7b95081dd';

    /** Notification to resident when their reservation is rejected. */
    public const AMENITY_RESERVATION_REJECTED = '187155f9-a931-4ea3-9061-66aa73579c73';

    /** Notification to resident when a reservation enters pending_payment and needs to be paid. */
    public const AMENITY_RESERVATION_PAYMENT_REQUIRED = 'f3f56787-8868-4a46-b3bf-afaccbd5a1b4';

    /** Notification to resident when a partial (deposit) earmark payment confirms the reservation. */
    public const AMENITY_RESERVATION_DEPOSIT_CONFIRMED = '1b7755eb-2cee-43fc-8494-ce0677c413d7';

    /** Notification to resident when an earmark payment settles the reservation's full balance. */
    public const AMENITY_RESERVATION_PAYMENT_CONFIRMED = '20d7525e-fa8f-4687-8e07-328c5a031cf4';

    /** Notification to resident when the treasurer rejects their amenity earmark payment. */
    public const AMENITY_RESERVATION_PAYMENT_REJECTED = '292eb906-b145-45c1-9b7a-c07fbe8adf46';

    /** Notification to resident (+ board) when a reservation is cancelled. */
    public const AMENITY_RESERVATION_CANCELLED = 'e15610cf-83ab-40f9-92ba-73598e73055b';

    /** Notification to resident when an admin applies an incident/damage surcharge to their reservation. */
    public const AMENITY_RESERVATION_SURCHARGE_ADDED = '2055aa61-bd3f-4408-9785-0fba454f8015';

    /** Notification to resident reminding them of an upcoming reservation payment deadline (S11 cron). */
    public const AMENITY_RESERVATION_PAYMENT_REMINDER = '2c864f2f-7ce9-4075-9108-b474dafa8aaa';

    /** Notification to resident when a reservation expires from unpaid balance (S11 cron). */
    public const AMENITY_RESERVATION_EXPIRED = '2eb57e52-3460-4575-89c5-6d580a2dae9e';

    // ── Complaint (Incidencias) ───────────────────────────────────────────────────

    /** Notification to configured managers (minus unit-linkage exclusion) when a complaint is registered. */
    public const COMPLAINT_CREATED = 'a9002001-0000-4000-8000-000000000001';

    /** Notification to the newly assigned manager when a complaint is assigned. */
    public const COMPLAINT_ASSIGNED = 'a9002001-0000-4000-8000-000000000002';

    /** Notification to the reporter and assignee when a complaint's status changes. */
    public const COMPLAINT_STATUS_CHANGED = 'a9002001-0000-4000-8000-000000000003';

    /** Notification to the other party (reporter <-> assignee) when a thread message is posted. */
    public const COMPLAINT_MESSAGE = 'a9002001-0000-4000-8000-000000000004';

    // ── Model definition ──────────────────────────────────────────────────────

    protected $fillable = [
        'id',
        'key',
        'name',
        'description',
        'category',
        'audience',
        'supports_email',
        'supports_push',
        'supports_whatsapp',
        'default_channels',
        'order',
        'is_active',
        'is_mandatory',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return [
            'supports_email' => 'boolean',
            'supports_push' => 'boolean',
            'supports_whatsapp' => 'boolean',
            'default_channels' => 'array',
            'order' => 'integer',
            'is_active' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_hidden' => 'boolean',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Look up a notification event by its machine-readable key slug.
     *
     * Since NotificationEvent extends CentralCatalogModel (Cachable), repeated
     * calls with the same key are served from cache when caching is enabled.
     */
    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * Per-organization notification settings for this event.
     *
     * WARNING — TenantScope is not applied here because NotificationEvent is a
     * central model (no BelongsToTenant). The HasMany crosses into the tenant-scoped
     * notification_setting table, which DOES carry tenant_id, but without an active
     * tenant context (e.g. queued jobs or super-admin requests without X-Tenant-Id)
     * this relation returns rows from ALL tenants.
     *
     * Always call this relation inside an initialized tenant context, or scope it
     * explicitly: `$event->settings()->where('tenant_id', tenant('id'))->get()`.
     */
    public function settings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'notification_event_id');
    }
}
