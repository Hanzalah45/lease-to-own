<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\ApplicationInfoRequest;
use App\Models\User;
use App\Notifications\ApplicationInfoProvidedNotification;
use App\Services\ApplicationCreationService;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function index(Request $request)
    {
        // Matches everything present() touches per row, so its loadMissing()
        // call below finds it all already here instead of re-querying per row.
        $applications = $request->user()->applications()
            ->with(['leaseAgreement.equipmentUnit', 'leaseAgreement.payments', 'infoRequests'])
            ->latest()
            ->get();

        return response()->json(['data' => $applications->map(fn ($app) => $this->present($app))]);
    }

    /** Self-service application — the customer applies for themselves, same engine as the admin wizard. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cell_phone' => ['nullable', 'string', 'max:30'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(365 * 120)->toDateString()],
            'drivers_license' => ['nullable', 'string', 'max:60'],
            'id_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],

            'residence_type' => ['nullable', Rule::in(['rent_apartment', 'own_single', 'own_multi', 'rent_house', 'other'])],
            'years_at_residence' => ['nullable', 'string', 'max:10'],
            'income_source' => ['nullable', 'string', 'max:30'],
            'gross_monthly_income' => ['nullable', 'numeric', 'min:0'],
            'move_notification_agreed' => ['required', 'accepted'],

            'condition' => ['nullable', 'in:new,used'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'ldw' => ['nullable', 'in:yes,no'],
            'cash_price' => ['required', 'numeric', 'min:0'],
            'year' => ['nullable', 'string', 'max:10'],
            'promo_code' => ['nullable', 'string', 'max:60'],

            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'monthly_rental' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_due_day' => ['nullable', 'integer', 'between:1,31'],
            'autopay' => ['nullable', 'in:yes,no'],
        ]);

        $application = ApplicationCreationService::create($request->user(), $data, $request->file('id_document'), actorUserId: $request->user()->id);

        return response()->json(['data' => $this->present($application)], 201);
    }

    public function show(Request $request, Application $application)
    {
        abort_unless($application->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $this->present($application)]);
    }

    /**
     * The customer's response to a "needs info" request — the only path back
     * to review once an admin has asked for something. A request might be
     * about a document, or might not be (income, address, anything else), so
     * this accepts a text reply, a replacement ID document, or both — at
     * least one is required. Moves the application straight to under_review
     * so it lands back in the admin's active queue instead of waiting for a
     * manual status change.
     */
    public function respondToInfoRequest(Request $request, Application $application)
    {
        abort_unless($application->customer_id === $request->user()->id, 404);
        abort_unless($application->status === Application::STATUS_NEEDS_INFO, 422, 'This application is not awaiting information.');

        $infoRequest = $application->infoRequests()->whereNull('replied_at')->latest()->first();
        abort_unless($infoRequest, 422, 'There is no open request to respond to.');

        $data = $request->validate([
            'reply_text' => ['required_without:id_document', 'nullable', 'string', 'max:1000'],
            'id_document' => ['required_without:reply_text', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $replyDocumentPath = null;
        if (isset($data['id_document'])) {
            // Intentionally not deleting the customer's previous ID document
            // here — every version submitted stays on file, tied to the
            // request it answered, instead of being overwritten.
            $replyDocumentPath = $data['id_document']->store('id-documents', 'local');
            $request->user()->customerProfile()->updateOrCreate(
                ['user_id' => $request->user()->id],
                ['government_id_document_path' => $replyDocumentPath, 'updated_by' => $request->user()->id],
            );
        }

        $infoRequest->update([
            'replied_at' => now(),
            'reply_text' => $data['reply_text'] ?? null,
            'reply_document_path' => $replyDocumentPath,
        ]);

        $application->update(['status' => Application::STATUS_UNDER_REVIEW]);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();
        Notification::send($recipients, new ApplicationInfoProvidedNotification($infoRequest->fresh()));

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    /** Lets the customer download exactly what they themselves attached to one of their own replies. */
    public function infoRequestDocument(Request $request, Application $application, ApplicationInfoRequest $infoRequest)
    {
        abort_unless($application->customer_id === $request->user()->id, 404);
        abort_unless($infoRequest->application_id === $application->id, 404);
        abort_unless($infoRequest->reply_document_path && Storage::disk('local')->exists($infoRequest->reply_document_path), 404);

        return Storage::disk('local')->download($infoRequest->reply_document_path);
    }

    private function present(Application $application): array
    {
        // loadMissing (not load): index() already eager-loads all of this, so
        // this only re-queries for show(), which calls present() on a single,
        // not-yet-loaded application — no per-row N+1 in the list endpoint.
        $application->loadMissing(['leaseAgreement.equipmentUnit', 'leaseAgreement.payments', 'infoRequests']);

        $payload = $application->toArray();

        $payload['info_requests'] = $application->infoRequests->map(fn (ApplicationInfoRequest $r) => [
            'id' => $r->id,
            'request_text' => $r->request_text,
            'requested_at' => $r->created_at,
            'reply_text' => $r->reply_text,
            'reply_has_document' => (bool) $r->reply_document_path,
            'replied_at' => $r->replied_at,
        ])->values();

        if ($lease = $application->leaseAgreement) {
            $payload['lease_agreement']['sales_tax_amount'] = $lease->salesTaxAmount();
            $payload['lease_agreement']['total_monthly_payment'] = $lease->totalMonthlyPayment();
            $payload['lease_agreement']['payments_made'] = $lease->paymentsMadeCount();
            $payload['lease_agreement']['epo_today'] = LeaseEngine::epoToday($lease);
        }

        return $payload;
    }
}
