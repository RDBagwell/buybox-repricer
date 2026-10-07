<?php

use App\Repricer\Dashboard\DashboardChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Operator mode: the dashboard channel is private and only logged-in users may subscribe.
// (In demo mode DashboardChannel broadcasts on a public channel with presenter-only payloads.)
Broadcast::channel(DashboardChannel::NAME, fn ($user) => Gate::forUser($user)->allows('view-dashboard'));
