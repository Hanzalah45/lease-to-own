<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationPreferencesController extends Controller
{
    /** Self-registered customers may not have a profile row yet (one is normally created with their first application). */
    public function update(Request $request)
    {
        $data = $request->validate([
            'payment_reminder_emails' => ['required', 'boolean'],
            'status_change_emails' => ['required', 'boolean'],
        ]);

        $profile = $request->user()->customerProfile()->updateOrCreate([], $data);

        return response()->json(['data' => $profile]);
    }
}
