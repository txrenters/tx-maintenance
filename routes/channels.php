<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Work Order channels - public channels for real-time updates
Broadcast::channel('workOrders', function () {
    return true; // Public channel
});

// Job channels for Jobber integration
Broadcast::channel('jobs', function () {
    return true; // Public channel
});

// Visit channels for Jobber visits
Broadcast::channel('visits', function () {
    return true; // Public channel
});
