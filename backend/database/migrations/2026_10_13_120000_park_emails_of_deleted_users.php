<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accounts that were soft-deleted before deleting started to free the email
 * still hold their address, which blocks anyone (including the same person's
 * real account) from using it again (client, Joel, 2026-10-12). Park them the
 * same way a new deletion does. An address already parked is left alone, so
 * running this twice changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = DB::table('users')
            ->whereNotNull('deleted_at')
            ->where('email', 'not like', '%.invalid')
            ->get(['id', 'email']);

        foreach ($deleted as $user) {
            DB::table('users')->where('id', $user->id)->update(['email' => User::parkedEmail($user->id, $user->email)]);
        }
    }

    public function down(): void
    {
        // A parked address is not turned back: the original is readable inside
        // it, and putting it back could collide with a new account using it.
    }
};
