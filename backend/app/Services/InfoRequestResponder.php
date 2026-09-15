<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationInfoProvidedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/**
 * Shared by the authenticated (Customer\ApplicationController) and
 * signed-link (PublicInfoRequestController) reply endpoints so a guest
 * customer answering before they have a working login behaves identically to
 * one answering from their portal — reply text, an attached document, or
 * both moves the application back to waiting_review and notifies reviewers.
 */
class InfoRequestResponder
{
    public static function respond(Application $application, User $customer, ?string $replyText, ?UploadedFile $document): Application
    {
        $infoRequest = $application->infoRequests()->whereNull('replied_at')->latest()->first();
        abort_unless($infoRequest, 422, 'There is no open request to respond to.');

        $replyDocumentPath = null;
        if ($document) {
            // Intentionally not deleting the customer's previous ID document
            // here — every version submitted stays on file, tied to the
            // request it answered, instead of being overwritten.
            $replyDocumentPath = $document->store('id-documents', 'local');
            $customer->customerProfile()->updateOrCreate(
                ['user_id' => $customer->id],
                ['government_id_document_path' => $replyDocumentPath, 'updated_by' => $customer->id],
            );
        }

        $infoRequest->update([
            'replied_at' => now(),
            'reply_text' => $replyText,
            'reply_document_path' => $replyDocumentPath,
        ]);

        $application->update(['status' => Application::STATUS_WAITING_REVIEW]);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();
        Notification::send($recipients, new ApplicationInfoProvidedNotification($infoRequest->fresh()));

        return $application->fresh();
    }
}
