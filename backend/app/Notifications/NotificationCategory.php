<?php

namespace App\Notifications;

/**
 * Canonical notification categories (§17.3 preference toggles / §17.4 catalog).
 *
 * Emitters MUST pass one of these as $data['category'] on every
 * notifyUsers()/dispatch() call so per-category user preferences apply.
 * A dispatch without category only honors the user's '_global' toggle.
 */
final class NotificationCategory
{
    public const COLLECTIONS = 'collections';

    public const ANNOUNCEMENTS = 'announcements';

    public const ACCESS = 'access';

    public const CHAT = 'chat';

    public const PROJECTS = 'projects';

    public const REPORTS = 'reports';

    public const SYSTEM = 'system';

    public const AMENITIES = 'amenities';

    public const COMPLAINTS = 'complaints';

    public const FOLLOW_UP = 'follow_up';

    public const APPOINTMENT = 'appointment';
}
