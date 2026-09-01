<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Real, database-backed notifications for the authenticated user — admin or
 * customer, whichever is signed in. Notifications are created by the actual
 * events that trigger them (customer signup, admin account creation, Plaid
 * bank verification, etc.) rather than shown as static sample data.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->limit(50)->get();

        return response()->json([
            'data' => $notifications,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['data' => $notification]);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked read.']);
    }
}
