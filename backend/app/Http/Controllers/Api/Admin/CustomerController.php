<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationInfoRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AccountSecurityUpdatedNotification;
use App\Notifications\ActivateAccountNotification;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\AccountSetupSigner;
use App\Services\CommonValidationRules;
use App\Services\ContractSigner;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Full customer management for admins with application_review access:
 * list, view, create, edit (including email/password), and soft-delete.
 * Deleting a customer never hard-removes the row — it's recoverable via
 * the `deleted_at` timestamp, and disappears from the directory by default.
 */
class CustomerController extends Controller
{
    public function index()
    {
        $customers = User::where('role', User::ROLE_CUSTOMER)
            ->with('customerProfile.updatedBy:id,name')
            ->latest()
            ->get();

        return response()->json(['data' => $customers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => CommonValidationRules::name(),
            'email' => CommonValidationRules::email('unique:users,email'),
            'phone' => CommonValidationRules::phone(),
            'password' => CommonValidationRules::password(),
            'address_line_1' => ['nullable', 'string', 'max:'.CommonValidationRules::STREET_MAX],
            'city' => ['nullable', 'string', 'max:'.CommonValidationRules::CITY_MAX],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date'],
            'internal_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_CUSTOMER,
            'status' => 'active',
        ]);

        $customer->customerProfile()->create([
            'address_line_1' => $data['address_line_1'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ]);

        return response()->json(['data' => $customer->load('customerProfile')], 201);
    }

    /**
     * Real gap found live 2026-09-25 (call with Joel): this only ever
     * returned bare application/lease rows with counts shown on the page —
     * to actually see a customer's ID, bill, lease terms, or contract, an
     * admin had to leave here, find the matching application in the
     * Applications list, and open it there. Now eager-loads and presents
     * each application the same way Admin\ApplicationController::show()
     * does, so the customer page is a real one-stop lookup instead of a
     * detour back through Applications.
     */
    public function show(User $customer)
    {
        $this->assertIsCustomer($customer);

        $customer->load([
            'customerProfile.updatedBy:id,name',
            'applications' => fn ($query) => $query->latest(),
            'applications.createdBy:id,name',
            'applications.reviewedBy:id,name',
            'applications.leaseAgreement.equipmentUnit' => fn ($query) => $query->withCount('serviceRecords'),
            'applications.leaseAgreement.contract',
            'applications.leaseAgreement.paymentMethodsOverrideBy:id,name',
            'applications.leaseAgreement.payments',
            'applications.infoRequests.requestedBy:id,name',
            'riskProfile.redFlags.resolvedBy:id,name',
            'riskProfile.updatedBy:id,name',
        ]);

        $payload = $customer->toArray();
        $payload['applications'] = $customer->applications->map(function (Application $application) {
            $data = $application->toArray();

            $data['info_requests'] = $application->infoRequests->map(fn (ApplicationInfoRequest $r) => [
                'id' => $r->id,
                'requested_by' => $r->requestedBy?->name,
                'request_text' => $r->request_text,
                'requested_at' => $r->created_at,
                'reply_text' => $r->reply_text,
                'reply_has_document' => (bool) $r->reply_document_path,
                'replied_at' => $r->replied_at,
            ])->values();

            if ($lease = $application->leaseAgreement) {
                $data['lease_agreement']['sales_tax_amount'] = $lease->salesTaxAmount();
                $data['lease_agreement']['total_monthly_payment'] = $lease->totalMonthlyPayment();
                $data['lease_agreement']['payments_made'] = $lease->paymentsMadeCount();
                $data['lease_agreement']['epo_today'] = LeaseEngine::epoToday($lease);
            }

            return $data;
        })->values();

        return response()->json(['data' => $payload]);
    }

    public function update(Request $request, User $customer)
    {
        $this->assertIsCustomer($customer);

        $data = $request->validate([
            'name' => CommonValidationRules::name(required: false),
            'email' => CommonValidationRules::email(Rule::unique('users', 'email')->ignore($customer->id), required: false),
            'phone' => array_merge(['sometimes'], CommonValidationRules::phone()),
            'password' => array_merge(['nullable'], CommonValidationRules::password(required: false)),
            'status' => ['sometimes', 'in:active,suspended,pending'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:'.CommonValidationRules::STREET_MAX],
            'city' => ['sometimes', 'nullable', 'string', 'max:'.CommonValidationRules::CITY_MAX],
            'state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $emailChanged = isset($data['email']) && $data['email'] !== $customer->email;
        $passwordChanged = ! empty($data['password']);
        $statusChanged = isset($data['status']) && $data['status'] !== $customer->status;

        $userFields = collect($data)->only(['name', 'email', 'phone', 'status'])->toArray();
        if ($passwordChanged) {
            $userFields['password'] = Hash::make($data['password']);
        }
        if ($userFields) {
            $customer->update($userFields);
        }

        // Sanctum tokens never expire and nothing re-checks status on later
        // requests — without this, a suspended customer keeps full API access
        // until they happen to log out on their own.
        if ($statusChanged && $data['status'] !== 'active') {
            $customer->tokens()->delete();
        }

        $changes = array_filter([
            $emailChanged ? 'email address' : null,
            $passwordChanged ? 'password' : null,
        ]);
        if ($changes) {
            $customer->notify(new AccountSecurityUpdatedNotification('Your '.implode(' and ', $changes).' was changed by an admin.'));
        }

        $profileFields = collect($data)
            ->only(['address_line_1', 'city', 'state', 'zip', 'date_of_birth', 'internal_notes'])
            ->toArray();
        if ($profileFields) {
            $customer->customerProfile()->updateOrCreate(
                ['user_id' => $customer->id],
                array_merge($profileFields, ['updated_by' => Auth::id()]),
            );
        }

        return response()->json(['data' => $customer->fresh()->load(['customerProfile.updatedBy:id,name'])]);
    }

    public function destroy(User $customer)
    {
        $this->assertIsCustomer($customer);

        $customer->delete();

        return response()->json(null, 204);
    }

    /**
     * Resends the "set up your account" link — real gap found 2026-09-21,
     * widened 2026-10-02 when account setup moved earlier in the funnel
     * (client, Joel): a guest who hasn't signed yet now needs the same
     * consolidated preview-and-activate link RequestContractSignatureNotification
     * already sends (see ContractSigner / PublicAccountActivationController),
     * not the old post-payment AccountSetupSigner link, which would 422 for
     * them since they have no paid payment yet. Still falls back to the old
     * path for a true legacy straggler: someone who's signed and paid but
     * never got the chance to activate early (pre-cutover, or the admin
     * override on AutoPay's payment-methods check let them skip ahead).
     */
    public function resendAccountSetup(User $customer)
    {
        $this->assertIsCustomer($customer);

        abort_unless($customer->status === 'pending', 422, 'This customer has already set up their account.');

        $application = $customer->applications()->latest()->first();
        $lease = $application?->leaseAgreement;

        if ($lease && ! $lease->contract()->exists()
            && in_array($application->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED], true)) {
            $customer->notify(new RequestContractSignatureNotification(ContractSigner::urlFor($customer, $lease)));

            return response()->json(['message' => "Signing/activation link sent to {$customer->email}."]);
        }

        abort_unless(
            $customer->leaseAgreements()->whereHas('payments', fn ($q) => $q->where('status', Payment::STATUS_PAID))->exists(),
            422,
            'Account setup isn\'t available for this customer yet.',
        );

        $customer->notify(new ActivateAccountNotification(AccountSetupSigner::urlFor($customer)));

        return response()->json(['message' => "Account setup link sent to {$customer->email}."]);
    }

    /**
     * This endpoint only ever targets Customer accounts — never an admin or
     * super admin, even though route-model binding would resolve any user ID.
     */
    private function assertIsCustomer(User $user): void
    {
        abort_unless($user->isCustomer(), 404);
    }
}
