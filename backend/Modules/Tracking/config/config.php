<?php

return [
    'name' => 'Tracking',

    // Retention window (days) for tracking_event before the daily model:prune
    // sweep hard-deletes it (routes/console.php + TrackingEvent::prunable()).
    'retention_days' => (int) env('TRACKING_RETENTION_DAYS', 90),
];
