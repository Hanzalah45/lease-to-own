<?php

namespace App\Notifications;

use App\Models\RiskRedFlag;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to risk_assessment staff the moment a new red flag is raised — the "resolved" side already had this, "detected" did not. */
class RedFlagDetectedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly RiskRedFlag $redFlag) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->redFlag->loadMissing('riskProfile.customer');
        $customerName = $this->redFlag->riskProfile?->customer?->name ?? 'a customer';
        $flagType = ucfirst(str_replace('_', ' ', $this->redFlag->type));

        return [
            'type' => 'risk_flag',
            'title' => "Red flag: {$customerName}",
            'body' => $this->redFlag->description ?? "{$flagType} detected.",
            'action_url' => "/admin/customers/{$this->redFlag->riskProfile?->customer_id}",
        ];
    }
}
