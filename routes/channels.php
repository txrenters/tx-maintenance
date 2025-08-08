<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('jobs', function ($user) {
    return true; // allow all authenticated users
});
Broadcast::channel('workOrders', function ($user) {
    return true; // allow all authenticated users
});
