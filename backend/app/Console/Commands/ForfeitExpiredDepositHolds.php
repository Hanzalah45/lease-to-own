<?php

namespace App\Console\Commands;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Notifications\DepositForfeitedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Client, 2026-09-05: the security deposit holds the unit for 30 days from
 * the moment the customer signs the lease contract. If they haven't picked
 * up (i.e. the application never reaches "finished") within that window,
 * the deposit is forfeited and the reservation is cancelled. Runs daily —
 * deposit_forfeited_at guards against re-forfeiting the same application on
 * a later run.
 */
class ForfeitExpiredDepositHolds extends Command
{
    protected $signature = 'deposits:forfeit-expired-holds';

    protected $description = 'Decline applications whose 30-day deposit hold expired without the unit being picked up';

    public function handle(): int
    {
        $applications = Application::whereNotNull('deposit_hold_expires_at')
            ->whereNull('deposit_forfeited_at')
            ->where('deposit_hold_expires_at', '<=', now())
            ->whereIn('status', [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY])
            ->with('customer.customerProfile')
            ->get();

        $reviewers = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();

        foreach ($applications as $application) {
            $application->update([
                'status' => Application::STATUS_DECLINED,
                'status_notes' => 'Deposit forfeited — the unit was not picked up within 30 days of signing the lease agreement.',
                'deposit_forfeited_at' => now(),
            ]);

            if ($application->customer->customerProfile?->status_change_emails ?? true) {
                $application->customer->notify(new ApplicationStatusChangedNotification($application->fresh()));
            }

            Notification::send($reviewers, new DepositForfeitedNotification($application));
        }

        $this->info("Forfeited {$applications->count()} expired deposit hold(s).");

        return self::SUCCESS;
    }
}
