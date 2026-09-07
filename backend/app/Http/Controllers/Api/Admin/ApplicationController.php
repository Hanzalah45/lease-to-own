<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\ApplicationInfoRequest;
use App\Models\Payment;
use App\Models\RiskProfile;
use App\Models\User;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\ApplicationCreationService;
use App\Services\ApplicationValidationRules;
use App\Services\ContractSigner;
use App\Services\LeaseEngine;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Milestone 2/3 — turns the admin New Application wizard into a real,
 * persisted Application + LeaseAgreement (+ EquipmentUnit, CustomerProfile,
 * RiskProfile). A LeaseAgreement is created alongside the Application at
 * submission time (it carries the agreed terms); the Payment schedule is
 * only generated once the application reaches "funded_paid" — see update().
 */
class ApplicationController extends Controller
{
    public function index()
    {
        $applications = Application::with([
            'customer:id,name,email,phone',
            'createdBy:id,name',
            'reviewedBy:id,name',
            'leaseAgreement.equipmentUnit',
        ])->latest()->get();

        return response()->json(['data' => $applications]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge(
            ['registered_customer_id' => ['required', 'integer', 'exists:users,id']],
            ApplicationValidationRules::customerAndRisk(),
            ApplicationValidationRules::salesPerson(),
            ApplicationValidationRules::equipmentAndLease(),
        ));

        $customer = User::findOrFail($data['registered_customer_id']);

        $application = ApplicationCreationService::create(
            $customer,
            $data,
            $request->file('id_document'),
            $data['sales_person'] ?? null,
            Auth::id(),
            $request->file('utility_bill'),
        );

        return response()->json(['data' => $this->present($application)], 201);
    }

    public function show(Application $application)
    {
        return response()->json(['data' => $this->present($application)]);
    }

    /**
     * Rejoins a guest-originated application (submitted with no equipment or
     * pricing) with the normal lease-creation flow — an admin fills these in
     * once the approval call has happened. 404s (via leaseAgreement() being
     * null) rather than a generic form for any application that already has
     * a lease; attachLease() itself also guards this.
     */
    public function attachLease(Request $request, Application $application)
    {
        $data = $request->validate(ApplicationValidationRules::equipmentAndLease());

        ApplicationCreationService::attachLease($application, $data, Auth::id());

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    public function update(Request $request, Application $application)
    {
        $equipmentUnitId = $application->leaseAgreement?->equipment_unit_id;

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Application::ALL_STATUSES)],
            'status_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'signature_received' => ['sometimes', 'boolean'],
            'deposit_received' => ['sometimes', 'boolean'],
            'lease' => ['sometimes', 'array'],
            // Only 12/24/36 have a defined monthly-payment divisor (see
            // ApplicationValidationRules::equipmentAndLease / the official
            // terms sheet) — the update path must match the creation path.
            'lease.term_months' => ['sometimes', 'integer', Rule::in([12, 24, 36])],
            'lease.monthly_rental_payment' => ['sometimes', 'numeric', 'min:0'],
            'lease.sales_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lease.security_deposit' => ['sometimes', 'numeric', 'min:0'],
            'lease.autopay_enabled' => ['sometimes', 'boolean'],
            'lease.ldw_selected' => ['sometimes', 'boolean'],
            'lease.promo_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'equipment' => ['sometimes', 'array'],
            'equipment.model' => ['sometimes', 'string', 'max:100'],
            'equipment.serial_number' => ['sometimes', 'string', 'max:50', Rule::unique('equipment_units', 'serial_number')->ignore($equipmentUnitId)],
            'equipment.condition_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'customer' => ['sometimes', 'array'],
            'customer.address_line_1' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer.city' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer.state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'customer.zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'customer.residence_type' => ['sometimes', 'nullable', Rule::in(['rent_apartment', 'own_single', 'own_multi', 'rent_house', 'other'])],
            'customer.years_at_residence' => ['sometimes', 'nullable', 'string', 'max:10'],
            'customer.previous_address' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer.landlord_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'customer.landlord_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer.monthly_rent' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'customer.mortgage_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'customer.mortgage_years' => ['sometimes', 'nullable', 'string', 'max:10'],
            'customer.employer_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'customer.employer_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer.employer_position' => ['sometimes', 'nullable', 'string', 'max:80'],
            'customer.alternate_contact_1_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'customer.alternate_contact_1_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer.alternate_contact_2_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'customer.alternate_contact_2_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'risk' => ['sometimes', 'array'],
            'risk.identity_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.employment_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.bank_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.background_check_status' => ['sometimes', Rule::in(['pending', 'clear', 'flagged'])],
            'risk.background_check_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== $application->status) {
            $legalNextStatuses = Application::LEGAL_STATUS_TRANSITIONS[$application->status] ?? [];
            abort_unless(
                in_array($data['status'], $legalNextStatuses, true),
                422,
                "This application cannot move from \"{$application->status}\" to \"{$data['status']}\".",
            );

            // Real gap found in testing (2026-09-04): "Mark Deposit Received"
            // only ever relabeled the status — it never actually recorded
            // that a contract was signed or a deposit collected, and nothing
            // stopped a unit reaching "waiting on delivery" (ready for
            // pickup) with no signed contract at all. The "Ready for pickup"
            // checklist reads signature_received/deposit_received, which
            // stayed false forever unless an admin separately remembered to
            // toggle them by hand — misleading, not just cosmetic.
            if ($data['status'] === Application::STATUS_WAITING_DELIVERY) {
                abort_unless(
                    $application->leaseAgreement?->contract()->exists(),
                    422,
                    'Cannot mark this ready for delivery until the lease contract is signed.',
                );
            }
        }

        if (array_key_exists('status', $data)) {
            $isNeedsInfo = $data['status'] === Application::STATUS_NEEDS_INFO;

            $application->update([
                'status' => $data['status'],
                // needs_info asks live in application_info_requests instead
                // (see below) — status_notes stays reserved for every other
                // status change (decline reasons, etc.).
                'status_notes' => $isNeedsInfo ? $application->status_notes : ($data['status_notes'] ?? $application->status_notes),
                'reviewed_by' => Auth::id(),
                // A manual decline (any reason — this is distinct from the
                // automatic deposits:forfeit-expired-holds job, which never
                // goes through this endpoint) ends the current 30-day hold.
                // Without this, an already-signed application that's declined
                // and later reopened (declined -> waiting_review is legal)
                // would carry a now-in-the-past deposit_hold_expires_at into
                // its next run through the pipeline — since a lease can't be
                // re-signed once signed, that stale timestamp would be caught
                // by the very next day's forfeiture job and wrongly cancel a
                // legitimately reopened application.
                ...($data['status'] === Application::STATUS_DECLINED ? ['deposit_hold_expires_at' => null] : []),
            ]);

            // Guards against a duplicate open request from a race between two
            // admins (or a double-submit) hitting this endpoint at once — the
            // customer's reply only ever closes the newest one, so an older
            // duplicate would otherwise stay open forever.
            if ($isNeedsInfo && ! $application->infoRequests()->whereNull('replied_at')->exists()) {
                $application->infoRequests()->create([
                    'requested_by_user_id' => Auth::id(),
                    'request_text' => $data['status_notes'] ?? '',
                ]);
            }

            // Terms are locked in once verification passes and the
            // application enters "waiting on deposit" — matches the same
            // point ContractController opens up for signing — so the
            // payment schedule is generated here, well before "finished".
            if ($data['status'] === Application::STATUS_WAITING_DEPOSIT) {
                $lease = $application->leaseAgreement;
                if ($lease && ! $lease->payments()->exists()) {
                    LeaseEngine::generatePaymentSchedule($lease);
                }

                // A guest-originated customer has no usable password yet
                // (activated at first payment/pickup, which happens AFTER
                // signing) — Customer\ContractController's authenticated
                // sign endpoint is unreachable for them, so they need the
                // signed link instead. A customer with a real account can
                // already sign from their own portal, so this is guest-only.
                // The status change and payment schedule above are already
                // committed — a mail transport hiccup here must not turn
                // this into an apparent failure to advance the application.
                if ($lease && $application->customer->status === 'pending') {
                    try {
                        $application->customer->notify(
                            new RequestContractSignatureNotification(ContractSigner::urlFor($application->customer, $lease)),
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            // "Mark Deposit Received" (waiting_deposit -> waiting_delivery):
            // the contract's existence was already required above — record
            // both flags now rather than leaving the "Ready for pickup"
            // checklist to show "not yet signed"/"not yet collected" forever.
            if ($data['status'] === Application::STATUS_WAITING_DELIVERY) {
                $application->update(['signature_received' => true, 'deposit_received' => true]);
            }

            // "Mark Delivered & Paid" (waiting_delivery -> finished) is the
            // other place, besides Payments, where a lease's first payment
            // gets recorded — without this it only relabeled the status,
            // leaving the payment "pending" forever and never sending a
            // guest-originated customer their account-activation link.
            if ($data['status'] === Application::STATUS_FINISHED) {
                $lease = $application->leaseAgreement;
                if ($lease) {
                    $payment = LeaseEngine::markFirstPaymentPaid($lease, Auth::id());
                    if ($payment) {
                        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
                            ->orWhere(function ($query) {
                                $query->where('role', User::ROLE_ADMIN)
                                    ->where(function ($inner) {
                                        $inner->whereDoesntHave('adminPermissions')
                                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::PAYMENT_TRACKING));
                                    });
                            })->get();
                        Notification::send($recipients, new PaymentStatusChangedNotification($payment));

                        LeaseEngine::activateGuestAccountIfFirstPayment($lease);
                    }
                }
            }

            // "...emails" is the field name from the customer's own preferences UI, but this
            // app has no email channel wired up yet — the toggle controls the in-app
            // notification instead, since that's the only one that exists.
            if ($application->customer->customerProfile?->status_change_emails ?? true) {
                $application->customer->notify(new ApplicationStatusChangedNotification($application->fresh()));
            }
        }

        if (array_key_exists('signature_received', $data) || array_key_exists('deposit_received', $data)) {
            $application->update(array_filter([
                'signature_received' => $data['signature_received'] ?? null,
                'deposit_received' => $data['deposit_received'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if ($lease = $application->leaseAgreement) {
            if (! empty($data['lease'])) {
                abort_unless($request->user()->hasAdminPermission(AdminPermission::CONTRACT_GENERATION), 403, 'You do not have permission to edit lease terms.');
                abort_if($lease->contract()->exists(), 422, 'This lease agreement has already been signed and its terms can no longer be changed.');

                $termsChanged = isset($data['lease']['term_months']) || isset($data['lease']['monthly_rental_payment']);

                $lease->update(array_merge($data['lease'], ['updated_by' => Auth::id()]));
                if ($termsChanged) {
                    $lease->update([
                        'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice(
                            (float) $lease->monthly_rental_payment,
                            (int) $lease->term_months,
                        ),
                    ]);

                    // A schedule may already exist from the approval step — rebuild it
                    // at the new terms rather than leaving stale rows on the books.
                    if ($lease->payments()->exists()) {
                        abort_if(
                            $lease->payments()->where('status', Payment::STATUS_PAID)->exists(),
                            422,
                            'Cannot change the term or rental amount once a payment has been made against this lease.',
                        );
                        LeaseEngine::regeneratePaymentSchedule($lease);
                    }
                }
            }

            if (! empty($data['equipment']) && $lease->equipmentUnit) {
                abort_unless($request->user()->hasAdminPermission(AdminPermission::EQUIPMENT_TRACKING), 403, 'You do not have permission to edit equipment records.');
                $lease->equipmentUnit->update(array_merge($data['equipment'], ['updated_by' => Auth::id()]));
            }
        }

        if (! empty($data['customer'])) {
            $application->customer->customerProfile()->updateOrCreate(
                ['user_id' => $application->customer_id],
                array_merge($data['customer'], ['updated_by' => Auth::id()]),
            );
        }

        if (! empty($data['risk'])) {
            abort_unless($request->user()->hasAdminPermission(AdminPermission::RISK_ASSESSMENT), 403, 'You do not have permission to edit the risk profile.');

            $riskProfile = RiskProfile::updateOrCreate(
                ['customer_id' => $application->customer_id],
                array_merge($data['risk'], ['updated_by' => Auth::id()]),
            );
            RiskScoringService::recomputeScore($riskProfile);
        }

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    public function destroy(Application $application)
    {
        $application->delete();

        return response()->json(null, 204);
    }

    /** Streams the applicant's current ID document on file — never exposed via a public URL. */
    public function idDocument(Application $application)
    {
        $path = $application->customer->customerProfile?->government_id_document_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function utilityBill(Application $application)
    {
        $path = $application->customer->customerProfile?->utility_bill_document_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    /** Streams the specific document attached to one historical info-request reply — not just whatever's current. */
    public function infoRequestDocument(Application $application, ApplicationInfoRequest $infoRequest)
    {
        abort_unless($infoRequest->application_id === $application->id, 404);
        abort_unless($infoRequest->reply_document_path && Storage::disk('local')->exists($infoRequest->reply_document_path), 404);

        return Storage::disk('local')->download($infoRequest->reply_document_path);
    }

    /** Attaches the LeaseEngine's live EPO figures to the loaded lease agreement, if one exists. */
    private function present(Application $application): array
    {
        $application->load([
            'createdBy:id,name',
            'customer.customerProfile.updatedBy:id,name',
            'customer.riskProfile.redFlags.resolvedBy:id,name',
            'customer.riskProfile.updatedBy:id,name',
            'reviewedBy:id,name',
            'leaseAgreement.updatedBy:id,name',
            'leaseAgreement.equipmentUnit' => fn ($query) => $query->withCount('serviceRecords'),
            'leaseAgreement.equipmentUnit.updatedBy:id,name',
            'leaseAgreement.payments',
            'leaseAgreement.contract',
            'leaseAgreement.contracts.voidedBy:id,name',
            'leaseAgreement.contracts.signer:id,name',
            'dealerNotes.author:id,name',
            'infoRequests.requestedBy:id,name',
        ]);

        $payload = $application->toArray();

        $payload['info_requests'] = $application->infoRequests->map(fn (ApplicationInfoRequest $r) => [
            'id' => $r->id,
            'requested_by' => $r->requestedBy?->name,
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
            $payload['lease_agreement']['epo_schedule'] = LeaseEngine::fullSchedule($lease);
        }

        return $payload;
    }
}
