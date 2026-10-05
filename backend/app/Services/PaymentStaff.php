<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PaymentStaff
{
    /**
     * Everyone who should hear about a payment problem: super admins, plus
     * admins who either have no per-area permissions set (full access) or hold
     * payment_tracking. The same audience LeaseEngine and ChargeLateFees use.
     *
     * @return Collection<int, User>
     */
    public static function recipients(): Collection
    {
        return User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::PAYMENT_TRACKING));
                    });
            })->get();
    }
}
