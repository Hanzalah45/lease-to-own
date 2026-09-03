<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\User;
use App\Notifications\DealerNoteAddedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class DealerNoteController extends Controller
{
    public function store(Request $request, Application $application)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
        ]);

        $note = $application->dealerNotes()->create([
            'author_user_id' => Auth::id(),
            'text' => $data['text'],
        ]);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get()
            ->reject(fn (User $u) => $u->id === Auth::id());
        Notification::send($recipients, new DealerNoteAddedNotification($note));

        return response()->json(['data' => $note->load('author:id,name')], 201);
    }
}
