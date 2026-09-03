<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    /** Any authenticated user (customer, admin, or super admin) updating their own photo. */
    public function update(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('avatars')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('', 'avatars');
        // The "avatars" disk has 'throw' => false (production has symlink()/
        // exec() disabled), so a write failure returns false instead of
        // throwing — without this check it would silently save avatar_path
        // as false and report success with no photo actually stored.
        abort_if($path === false, 500, 'Could not save your photo. Please try again.');

        $user->update(['avatar_path' => $path]);

        return response()->json(['user' => $user->fresh()->load(['customerProfile', 'adminPermissions'])]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('avatars')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json(['user' => $user->fresh()->load(['customerProfile', 'adminPermissions'])]);
    }
}
