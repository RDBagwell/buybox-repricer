<?php

namespace App\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * Who did it, for the audit log: the user's email, or an anonymised visitor id in demo mode
 * (a hash of the IP, never the IP itself).
 */
final class Actor
{
    public static function of(Request $request): string
    {
        $user = $request->user();
        if ($user !== null) {
            return 'user:'.(string) ($user->getAttribute('email') ?? $user->getAuthIdentifier());
        }

        return 'visitor:'.substr(hash('sha256', (string) $request->ip().config('app.key')), 0, 12);
    }
}
