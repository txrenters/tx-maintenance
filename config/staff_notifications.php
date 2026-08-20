<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | Off, staff activity is still logged and still reaches the notification
    | bell — only the broadcast to the desktop client stops. Useful as a kill
    | switch if Reverb is down and the queue is backing up.
    |
    */

    'enabled' => (bool) env('STAFF_DESKTOP_NOTIFICATIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Notify The Person Who Caused It
    |--------------------------------------------------------------------------
    |
    | A coordinator who sends a message does not need a toast telling them a
    | message was sent. The causer is dropped from the recipient list unless
    | this is switched on.
    |
    */

    'notify_causer' => (bool) env('STAFF_DESKTOP_NOTIFY_CAUSER', false),

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Only the events listed here notify anybody; every other activity-log row
    | reaches the bell exactly as it always has. Remove an entry to silence it.
    |
    | audience:
    |   assigned – the work order's coordinator (WorkOrder::woc). Falls back to
    |              every WOC when the work order has no one assigned yet.
    |   woc      – every user holding the woc role.
    |   admin    – every user holding the admin role.
    |   staff    – woc and admin together.
    |
    | priority: low | normal | high | urgent. "urgent" breaks through the
    | desktop client's "pause notifications" setting, so it is reserved for a
    | genuine emergency.
    |
    | vendors: whether the vendor the event concerns is notified too, for those
    | vendors who have a login (vendors.user_id). Default false — most of these
    | events are staff-internal and a vendor must never see them. Turning it on
    | does NOT hand the vendor the whole work order: StaffActivityNotifier
    | applies the same boundary NotificationController::fetchNotification does,
    | so a vendor is only ever notified about their own vendor thread on a work
    | order assigned to them.
    |
    */

    'events' => [
        'work_order_emergency' => ['audience' => 'assigned', 'priority' => 'urgent'],

        'message_undelivered' => ['audience' => 'assigned', 'priority' => 'high'],
        'hoa_violation_overdue' => ['audience' => 'assigned', 'priority' => 'high'],
        'hoa_tenant_link_missing' => ['audience' => 'staff', 'priority' => 'high'],
        'jobber_not_sent' => ['audience' => 'staff', 'priority' => 'high'],
        'jobber_reconnect_required' => ['audience' => 'admin', 'priority' => 'high'],

        'work_order_message_received' => ['audience' => 'assigned', 'priority' => 'normal', 'vendors' => true],
        'vendor_auto_assigned' => ['audience' => 'assigned', 'priority' => 'normal', 'vendors' => true],
        'job_message_received' => ['audience' => 'staff', 'priority' => 'normal'],
        'work_order_description_updated' => ['audience' => 'assigned', 'priority' => 'normal'],
        'owner_portal_approval' => ['audience' => 'assigned', 'priority' => 'normal'],
        'invoice_uploaded' => ['audience' => 'assigned', 'priority' => 'normal'],
        'hoa_violation_photos_by_text' => ['audience' => 'assigned', 'priority' => 'normal'],
        'owner_data_audit' => ['audience' => 'admin', 'priority' => 'normal'],
    ],

];
