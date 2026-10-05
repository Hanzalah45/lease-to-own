<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\ApplicationInfoRequest;
use App\Models\Payment;
use App\Models\RiskProfile;
use App\Models\User;
use App\Notifications\ApplicationInfoRequestedNotification;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\ApplicationCreationService;
use App\Services\ApplicationValidationRules;
use App\Services\BillingClock;
use App\Services\BillingSchedule;
use App\Services\ContractSigner;
use App\Services\InfoRequestResponder;
use App\Services\LeaseEngine;
use App\Services\RiskScoringService;
use Carbon\Carbon;
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

        $application = ApplicationCreationService::attachLease($application, $data, Auth::id());
        $lease = $application->leaseAgreement;

        // No payment schedule is built here any more (2026-10-05): the
        // monthly schedule is created when the equipment is picked up, from
        // the real pickup date (see LeaseEngine::startLease), so a late-
        // attached lease needs nothing extra to catch up.

        // Real gap found live 2026-09-16: nothing requires a lease to exist
        // before an application reaches waiting_deposit, so an admin who
        // attaches equipment/pricing out of order (after already advancing
        // the application) skipped the one-time signing-link send in
        // update()'s WAITING_DEPOSIT block entirely — the customer never got
        // a way to sign, and the application silently got stuck there since
        // waiting_delivery requires a signed contract. Covers that case here
        // too, the same guest-only way update() does.
        if ($application->status === Application::STATUS_WAITING_DEPOSIT
            && $application->customer->status === 'pending'
            && ! $lease->contract()->exists()) {
            try {
                $application->customer->notify(
                    new RequestContractSignatureNotification(ContractSigner::urlFor($application->customer, $lease)),
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    /**
     * Swaps the mower on an application that already has equipment and
     * pricing and re-prices the lease (client, Joel, 2026-10-06) — see
     * ApplicationCreationService::changeEquipment() for what it refuses and
     * how a signed contract is handled. It edits both the lease terms and the
     * equipment record, so it needs both permissions the individual edits do.
     */
    public function changeEquipment(Request $request, Application $application)
    {
        abort_unless($request->user()->hasAdminPermission(AdminPermission::CONTRACT_GENERATION), 403, 'You do not have permission to edit lease terms.');
        abort_unless($request->user()->hasAdminPermission(AdminPermission::EQUIPMENT_TRACKING), 403, 'You do not have permission to edit equipment records.');

        $data = $request->validate(ApplicationValidationRules::changeEquipment());

        $result = ApplicationCreationService::changeEquipment($application, $data, Auth::id());

        return response()->json([
            'data' => $this->present($application->fresh()),
            'meta' => ['contract_voided' => $result['contract_voided']],
        ]);
    }

    /**
     * Manually resends the guest signing-link email — a safety net for when
     * the automatic send at the waiting_deposit transition was skipped (e.g.
     * the lease was attached out of order — see attachLease()) or simply
     * never reached the customer's inbox.
     */
    public function resendContractSigningLink(Application $application)
    {
        abort_unless($application->status === Application::STATUS_WAITING_DEPOSIT, 422, 'This application is not waiting on a signature yet.');
        $lease = $application->leaseAgreement;
        abort_unless($lease, 422, 'This application has no lease agreement yet.');
        abort_if($lease->contract()->exists(), 422, 'This lease agreement has already been signed.');
        abort_unless($application->customer->status === 'pending', 422, 'This customer has a portal login and can sign in directly.');

        $application->customer->notify(
            new RequestContractSignatureNotification(ContractSigner::urlFor($application->customer, $lease)),
        );

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    public function update(Request $request, Application $application)
    {
        $equipmentUnitId = $application->leaseAgreement?->equipment_unit_id;
        $pickupDate = null;

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Application::ALL_STATUSES)],
            'status_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'signature_received' => ['sometimes', 'boolean'],
            'deposit_received' => ['sometimes', 'boolean'],
            // "Pay deposit only" admin checklist item (client, Joel,
            // 2026-10-02) — soft, manually-toggleable confirmation, same
            // pattern as deposit_received, not a hard gate on advancing the
            // application.
            'pickup_balance_received' => ['sometimes', 'boolean'],
            // AutoPay payment methods (client, 2026-10-01): lets an admin
            // move a lease to waiting_delivery even though the customer
            // hasn't added both a bank account and a card yet — see the
            // check below. Deliberately narrow: this bypasses only that one
            // check, not the signed-contract requirement above it.
            'override_payment_methods_check' => ['sometimes', 'boolean'],
            // "Mark Delivered" (billing cycles, client 2026-10-05): the day the
            // customer actually took the equipment (default today in Texas;
            // not in the future, at most 7 days back), and the billing cycle
            // only for an older signed lease that never got one.
            'pickup_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'billing_cycle' => ['sometimes', 'nullable', Rule::in(BillingSchedule::CYCLES)],
            'lease' => ['sometimes', 'array'],
            // Only 12/24/36 have a defined monthly-payment divisor (see
            // ApplicationValidationRules::equipmentAndLease / the official
            // terms sheet) — the update path must match the creation path.
            'lease.term_months' => ['sometimes', 'integer', Rule::in([12, 24, 36])],
            'lease.monthly_rental_payment' => ['sometimes', 'numeric', 'min:0'],
            'lease.sales_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lease.security_deposit' => ['sometimes', 'numeric', 'min:0'],
            'lease.autopay_enabled' => ['sometimes', 'boolean'],
            'lease.billing_cycle' => ['sometimes', 'nullable', Rule::in(BillingSchedule::CYCLES)],
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
            // Same bounds as ApplicationValidationRules' own date_of_birth
            // rule at submission time — an admin correction shouldn't be
            // looser than what the applicant themselves was held to.
            'customer.date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(365 * 120)->toDateString()],
            // Real gap found 2026-09-16: this validated against the wizard's
            // raw pre-mapping values, but customer_profiles.residence_type
            // only ever stores RiskScoringService::mapResidenceType()'s
            // coarser output (house/apartment/other — see
            // ApplicationCreationService::upsertCustomerProfile). Every edit
            // through this endpoint sends the mapped value (CUSTOMER_FIELDS'
            // own select options), so the old rule rejected 2 of its 3
            // options and blocked saving ANY customer field whenever
            // residence_type held its default.
            'customer.residence_type' => ['sometimes', 'nullable', Rule::in(['house', 'apartment', 'other'])],
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

                // AutoPay requires both a bank account and a card on file
                // (client, 2026-10-01) — required by default, but unlike the
                // contract check above, an admin can explicitly override it
                // (e.g. a customer who can't complete Stripe setup yet)
                // rather than getting permanently stuck. The override is
                // audited on the lease itself, not just accepted silently.
                if (! ($data['override_payment_methods_check'] ?? false)) {
                    abort_unless(
                        $application->leaseAgreement?->hasBothAutopayMethods(),
                        422,
                        'Cannot mark this ready for delivery until both a bank account and a card are added for AutoPay.',
                    );
                } elseif (! $application->leaseAgreement?->hasBothAutopayMethods()) {
                    $application->leaseAgreement?->update([
                        'payment_methods_override_by' => Auth::id(),
                        'payment_methods_override_at' => now(),
                    ]);
                }
            }

            // "Mark Delivered" starts the lease term (billing cycles, client
            // 2026-10-05): checked BEFORE the status changes below so a
            // missing billing cycle or a bad pickup date can never leave an
            // application "finished" with no payment schedule.
            if ($data['status'] === Application::STATUS_FINISHED && $application->leaseAgreement) {
                abort_unless(
                    $application->leaseAgreement->billing_cycle || ! empty($data['billing_cycle']),
                    422,
                    'Choose a billing cycle (the 1st or the 15th) before marking this lease delivered.',
                );
                $pickupDate = $this->resolvePickupDate($data['pickup_date'] ?? null);
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
                // Remembers where to return once the request is answered —
                // needs_info can now open from more than one stage (real gap
                // found live 2026-09-30). $application->status here is still
                // the pre-update value; cleared once InfoRequestResponder
                // actually uses it.
                'pre_needs_info_status' => $isNeedsInfo ? $application->status : $application->pre_needs_info_status,
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
            $newInfoRequest = null;
            if ($isNeedsInfo && ! $application->infoRequests()->whereNull('replied_at')->exists()) {
                $newInfoRequest = $application->infoRequests()->create([
                    'requested_by_user_id' => Auth::id(),
                    'request_text' => $data['status_notes'] ?? '',
                ]);
            }

            // Terms are locked in once verification passes and the
            // application enters "waiting on deposit" — matches the same
            // point ContractController opens up for signing. (The monthly
            // payment schedule is NOT built here: it starts at pickup, see
            // LeaseEngine::startLease.)
            if ($data['status'] === Application::STATUS_WAITING_DEPOSIT) {
                $lease = $application->leaseAgreement;

                // A guest-originated customer has no usable password yet
                // (activated at first payment/pickup, which happens AFTER
                // signing) — Customer\ContractController's authenticated
                // sign endpoint is unreachable for them, so they need the
                // signed link instead. A customer with a real account can
                // already sign from their own portal, so this is guest-only.
                // The status change above is already committed — a mail
                // transport hiccup here must not turn this into an apparent
                // failure to advance the application.
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

            // "Mark Delivered & Paid" (waiting_delivery -> finished) STARTS the
            // lease (billing cycles, client 2026-10-05): the monthly schedule
            // is built here from the real pickup date and the billing cycle
            // the customer chose, with the first month recorded as paid on
            // the pickup day. See LeaseEngine::startLease(). Without this the
            // status was only relabeled, leaving no payments at all and
            // never sending a guest-originated customer their
            // account-activation link.
            if ($data['status'] === Application::STATUS_FINISHED) {
                $lease = $application->leaseAgreement;
                if ($lease) {
                    $pickupDate ??= $this->resolvePickupDate($data['pickup_date'] ?? null);

                    // An older signed lease that predates the billing-cycle
                    // choice gets it from the admin here (with the customer's
                    // agreement); a lease that already has one keeps it.
                    if (! $lease->billing_cycle && ! empty($data['billing_cycle'])) {
                        $lease->update(['billing_cycle' => $data['billing_cycle']]);
                    }

                    // Real gap found 2026-09-15: the equipment unit's delivery_date
                    // was never set anywhere, but Equipment Tracking's edit form
                    // requires it the moment a unit's status is "leased" — so any
                    // admin edit to an already-leased unit (e.g. adding a GPS serial
                    // later) silently failed validation on a date field nobody had a
                    // reason to fill in yet. Delivery is exactly what's happening
                    // right here, so this is the correct, and only, place to set it.
                    // (The ownership date is set by startLease() to the final
                    // payment's due date.)
                    if ($lease->equipmentUnit && ! $lease->equipmentUnit->delivery_date) {
                        $lease->equipmentUnit->update(['delivery_date' => $pickupDate]);
                    }

                    $payment = LeaseEngine::startLease($lease, $pickupDate, Auth::id());
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
                // needs_info gets its own notification carrying the actual
                // question (and a signed reply link for a guest-originated
                // customer, real gap found 2026-09-16) instead of the generic
                // status-changed message, which has no way to know what was
                // asked.
                if ($newInfoRequest) {
                    $application->customer->notify(new ApplicationInfoRequestedNotification($application->fresh(), $newInfoRequest));
                } else {
                    $application->customer->notify(new ApplicationStatusChangedNotification($application->fresh()));
                }
            }
        }

        if (array_key_exists('signature_received', $data) || array_key_exists('deposit_received', $data) || array_key_exists('pickup_balance_received', $data)) {
            $application->update(array_filter([
                'signature_received' => $data['signature_received'] ?? null,
                'deposit_received' => $data['deposit_received'] ?? null,
                'pickup_balance_received' => $data['pickup_balance_received'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if ($lease = $application->leaseAgreement) {
            if (! empty($data['lease'])) {
                abort_unless($request->user()->hasAdminPermission(AdminPermission::CONTRACT_GENERATION), 403, 'You do not have permission to edit lease terms.');
                abort_if($lease->contract()->exists(), 422, 'This lease agreement has already been signed and its terms can no longer be changed.');

                $termsChanged = isset($data['lease']['term_months']) || isset($data['lease']['monthly_rental_payment']);

                // No schedule exists before pickup any more, so there is
                // nothing to rebuild on a terms edit (a legacy schedule built
                // at creation is replaced at pickup, see
                // LeaseEngine::startLease). But money already collected
                // against the old numbers still blocks the edit, checked
                // before anything is saved.
                abort_if(
                    $termsChanged && $lease->payments()->where('status', Payment::STATUS_PAID)->exists(),
                    422,
                    'Cannot change the term or rental amount once a payment has been made against this lease.',
                );

                $lease->update(array_merge($data['lease'], ['updated_by' => Auth::id()]));
                if ($termsChanged) {
                    $lease->update([
                        'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice(
                            (float) $lease->monthly_rental_payment,
                            (int) $lease->term_months,
                        ),
                    ]);
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

    /**
     * Closes an open "needs info" request on the customer's behalf — real
     * gap found live 2026-09-16: needs_info deliberately has no forward
     * edge through the normal status update (an admin can't advance past a
     * request they themselves opened until the customer replies), but a
     * customer very often answers by phone, text, or email instead of
     * through the portal, and there was no way for an admin to record that
     * and move the application on. Reuses the exact same path a customer's
     * own reply takes (InfoRequestResponder), so behavior stays identical
     * either way — same status transition, same admin notification.
     */
    public function respondToInfoRequestOnBehalf(Request $request, Application $application)
    {
        abort_unless($application->status === Application::STATUS_NEEDS_INFO, 422, 'This application is not awaiting information.');

        $data = $request->validate([
            'reply_text' => ['required_without:id_document', 'nullable', 'string', 'max:1000'],
            'id_document' => ['required_without:reply_text', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $application = InfoRequestResponder::respond($application, $application->customer, $data['reply_text'] ?? null, $data['id_document'] ?? null);

        return response()->json(['data' => $this->present($application)]);
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
    /**
     * The date the customer took the equipment, as a calendar date in the
     * client's (Texas) time zone: today by default, never in the future, and
     * at most a week back (the delivery is often recorded a little afterward).
     */
    private function resolvePickupDate(?string $requested): string
    {
        $today = BillingClock::todayDate();
        if (! $requested) {
            return $today;
        }

        $date = Carbon::parse($requested)->toDateString();
        abort_if($date > $today, 422, 'The pickup date cannot be in the future.');
        abort_if($date < Carbon::parse($today)->subDays(7)->toDateString(), 422, 'The pickup date can be at most 7 days in the past.');

        return $date;
    }

    private function present(Application $application): array
    {
        $application->load([
            'createdBy:id,name',
            'customer.customerProfile.updatedBy:id,name',
            'customer.riskProfile.redFlags.resolvedBy:id,name',
            'customer.riskProfile.updatedBy:id,name',
            'reviewedBy:id,name',
            'leaseAgreement.updatedBy:id,name',
            'leaseAgreement.paymentMethodsOverrideBy:id,name',
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
            $payload['lease_agreement']['pricing'] = $lease->pricingSummary();
            $payload['lease_agreement']['epo_schedule'] = LeaseEngine::fullSchedule($lease);
        }

        return $payload;
    }
}
