<?php

namespace App\Notifications;

use App\Models\RiskRedFlag;
use Illuminate\Notifications\Notification;

/** Sent to other risk_assessment staff (not the resolver) when a red flag is marked resolved. */
class RedFlagResolvedNotification extends Notification
{
    public function __construct(private readonly RiskRedFlag $redFlag) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->redFlag->loadMissing(['riskProfile.customer', 'resolvedBy']);
        $customerName = $this->redFlag->riskProfile?->customer?->name ?? 'a customer';
        $flagType = ucfirst(str_replace('_', ' ', $this->redFlag->type));

        return [
            'type' => 'risk_flag',
            'title' => "Red flag resolved — {$customerName}",
            'body' => "{$flagType} resolved by {$this->redFlag->resolvedBy?->name}.",
            'action_url' => "/admin/customers/{$this->redFlag->riskProfile?->customer_id}",
        ];
    }
}
