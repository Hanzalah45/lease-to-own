<?php

use App\Http\Controllers\Api\Admin\AdditionalFundsController as AdminAdditionalFundsController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\AutopayController as AdminAutopayController;
use App\Http\Controllers\Api\Admin\AutopayLogController as AdminAutopayLogController;
use App\Http\Controllers\Api\Admin\ContractController as AdminContractController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DealerNoteController;
use App\Http\Controllers\Api\Admin\DepositChargeController as AdminDepositChargeController;
use App\Http\Controllers\Api\Admin\EmailLogController as AdminEmailLogController;
use App\Http\Controllers\Api\Admin\EquipmentServiceRecordController;
use App\Http\Controllers\Api\Admin\EquipmentUnitController as AdminEquipmentUnitController;
use App\Http\Controllers\Api\Admin\ExtraPaymentController as AdminExtraPaymentController;
use App\Http\Controllers\Api\Admin\LeaseAgreementController as AdminLeaseAgreementController;
use App\Http\Controllers\Api\Admin\LeaseCardController as AdminLeaseCardController;
use App\Http\Controllers\Api\Admin\LeaseEndController;
use App\Http\Controllers\Api\Admin\MethodLockController as AdminMethodLockController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\PaymentLinkController as AdminPaymentLinkController;
use App\Http\Controllers\Api\Admin\PaymentMethodController as AdminPaymentMethodController;
use App\Http\Controllers\Api\Admin\PaymentNoteController as AdminPaymentNoteController;
use App\Http\Controllers\Api\Admin\PaymentPartController as AdminPaymentPartController;
use App\Http\Controllers\Api\Admin\PaymentReceiptController as AdminPaymentReceiptController;
use App\Http\Controllers\Api\Admin\PaymentRefundController as AdminPaymentRefundController;
use App\Http\Controllers\Api\Admin\PricingController as AdminPricingController;
use App\Http\Controllers\Api\Admin\QuickbooksConnectionController;
use App\Http\Controllers\Api\Admin\QuickbooksSyncController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\RiskProfileController;
use App\Http\Controllers\Api\Auth\AccountSetupController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\VerifyEmailController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\Customer\ApplicationController;
use App\Http\Controllers\Api\Customer\ContractController;
use App\Http\Controllers\Api\Customer\DepositPaymentController;
use App\Http\Controllers\Api\Customer\EquipmentController as CustomerEquipmentController;
use App\Http\Controllers\Api\Customer\ExtraPaymentController as CustomerExtraPaymentController;
use App\Http\Controllers\Api\Customer\LeaseAgreementController;
use App\Http\Controllers\Api\Customer\NotificationPreferencesController;
use App\Http\Controllers\Api\Customer\PaymentController;
use App\Http\Controllers\Api\Customer\PaymentMethodController;
use App\Http\Controllers\Api\Customer\PlaidController;
use App\Http\Controllers\Api\Customer\ReceiptController as CustomerReceiptController;
use App\Http\Controllers\Api\GuestApplicationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicAccountActivationController;
use App\Http\Controllers\Api\PublicContractController;
use App\Http\Controllers\Api\PublicDepositPaymentController;
use App\Http\Controllers\Api\PublicInfoRequestController;
use App\Http\Controllers\Api\PublicPaymentLinkController;
use App\Http\Controllers\Api\PublicPaymentMethodController;
use App\Http\Controllers\Api\PublicPlaidVerificationController;
use App\Http\Controllers\Api\QuickbooksOAuthCallbackController;
use App\Http\Controllers\Api\SesWebhookController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public auth routes
|--------------------------------------------------------------------------
*/
// throttle:10,1 = 10 attempts/minute per IP — these had no rate limiting at
// all before (the api group never calls throttleApi()), leaving login/register
// open to brute-force and spam.
Route::post('/auth/register', RegisterController::class)->middleware('throttle:10,1');
Route::post('/auth/login', LoginController::class)->middleware('throttle:10,1');
Route::post('/auth/forgot-password', ForgotPasswordController::class)->middleware('throttle:10,1');
Route::post('/auth/reset-password', ResetPasswordController::class)->middleware('throttle:10,1');
Route::post('/auth/email/verify', VerifyEmailController::class)->middleware('throttle:10,1');
Route::post('/auth/email/resend', ResendVerificationEmailController::class)->middleware('throttle:10,1');

// Pickup/first-payment account setup (client, 2026-09-04): a guest-originated
// customer's shadow account has an unusable password until they set a real
// one here — reached from ActivateAccountNotification's signed link. See
// AccountSetupSigner.
Route::post('/auth/account-setup', AccountSetupController::class)->middleware('throttle:10,1');

// No-login application (client, 2026-09-04): one generic shared link, no
// account required until the customer's first payment/pickup — see
// ApplicationCreationService::createGuestApplication().
Route::post('/guest-applications', [GuestApplicationController::class, 'store'])->middleware('throttle:10,1');

// Signed-link bank verification (client, 2026-09-04): reached from the
// "Request bank verification" admin action's emailed link — a
// guest-originated customer has no working login yet, so this can't sit
// behind auth:sanctum like Customer\PlaidController. See BankVerificationSigner.
Route::post('/plaid/verify-link-token', [PublicPlaidVerificationController::class, 'linkToken'])->middleware('throttle:10,1');
Route::post('/plaid/verify-exchange', [PublicPlaidVerificationController::class, 'exchange'])->middleware('throttle:10,1');

// Signed-link contract signing (2026-09-04 fix): a guest-originated customer
// has no working login until first payment, which is supposed to happen
// AFTER the contract is signed — Customer\ContractController can't be
// reached yet, so this exists the same way the Plaid routes above do. See
// ContractSigner / RequestContractSignatureNotification.
Route::post('/contracts/verify-lease', [PublicContractController::class, 'show'])->middleware('throttle:10,1');
Route::post('/contracts/verify-sign', [PublicContractController::class, 'store'])->middleware('throttle:10,1');
Route::post('/contracts/verify-preview', [PublicContractController::class, 'preview'])->middleware('throttle:10,1');

// Consolidated guest onboarding (client, Joel, 2026-10-02): account creation
// as the first of two separate steps reached from the same preview link
// above — reuses ContractSigner's signature rather than a new Signer. See
// PublicAccountActivationController.
Route::post('/contracts/verify-activate-account', [PublicAccountActivationController::class, 'activate'])->middleware('throttle:10,1');

// Signed-link AutoPay payment-method setup (client, 2026-10-01): same
// guest-no-login gap as the contract-signing routes above. See
// PaymentMethodSigner / PaymentMethodsRequestedNotification.
Route::post('/pay-links/show', [PublicPaymentLinkController::class, 'show'])->middleware('throttle:30,1');
Route::post('/pay-links/pay', [PublicPaymentLinkController::class, 'pay'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-show', [PublicPaymentMethodController::class, 'show'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-setup-intent', [PublicPaymentMethodController::class, 'setupIntent'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-confirm', [PublicPaymentMethodController::class, 'confirm'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-primary', [PublicPaymentMethodController::class, 'setPrimary'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-bank-pending', [PublicPaymentMethodController::class, 'bankPending'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-bank-pending-check', [PublicPaymentMethodController::class, 'bankPendingCheck'])->middleware('throttle:20,1');
Route::post('/payment-methods/verify-bank-pending-cancel', [PublicPaymentMethodController::class, 'bankPendingCancel'])->middleware('throttle:10,1');
Route::post('/payment-methods/verify-autopay', [PublicPaymentMethodController::class, 'agreeToAutopay'])->middleware('throttle:10,1');

// Signed-link deposit charging (client, Joel, 2026-10-01) — reuses the same
// AutoPay signed link above rather than minting a new Signer: this is a
// second step on the same page/link, not a new notification journey. See
// StripeDepositPaymentService.
Route::post('/deposit-payments/verify-show', [PublicDepositPaymentController::class, 'show'])->middleware('throttle:10,1');
Route::post('/deposit-payments/verify-charge', [PublicDepositPaymentController::class, 'charge'])->middleware('throttle:10,1');
// "Pay deposit only" defers this to a separate, later charge (client, Joel,
// 2026-10-02) — see StripeDepositPaymentService::chargePickupBalance().
Route::post('/deposit-payments/verify-charge-balance', [PublicDepositPaymentController::class, 'chargeBalance'])->middleware('throttle:10,1');

// Signed-link info-request responses (2026-09-16 fix): a guest-originated
// customer has no working login yet, so Customer\ApplicationController's
// authenticated respondToInfoRequest() is unreachable for them — this exists
// the same way the contract-signing routes above do. See InfoRequestSigner /
// ApplicationInfoRequestedNotification.
Route::post('/info-requests/verify', [PublicInfoRequestController::class, 'show'])->middleware('throttle:10,1');
Route::post('/info-requests/verify-respond', [PublicInfoRequestController::class, 'store'])->middleware('throttle:10,1');

// Stripe's own server calls this directly — never a logged-in user, so it
// can't sit behind auth:sanctum. Signature verification (against
// STRIPE_WEBHOOK_SECRET) inside the controller is what proves a request
// actually came from Stripe. See StripeWebhookController.
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])->middleware('throttle:60,1');
// Amazon SNS, carrying what Amazon SES reports about each email (delivered, bounced, marked as spam).
Route::post('/webhooks/ses', [SesWebhookController::class, 'handle'])->middleware('throttle:120,1');

// Intuit redirects the admin's browser here after the consent screen — see
// QuickbooksOAuthCallbackController for why this can't be auth:sanctum.
Route::get('/quickbooks/callback', QuickbooksOAuthCallbackController::class)->middleware('throttle:20,1');

/*
|--------------------------------------------------------------------------
| Authenticated routes, split by role
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', LogoutController::class);
    Route::get('/auth/me', MeController::class);

    // Shared — works for whichever role is signed in (admin or customer).
    Route::put('/me', [ProfileController::class, 'update']);
    Route::post('/me/avatar', [AvatarController::class, 'update']);
    Route::delete('/me/avatar', [AvatarController::class, 'destroy']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Customer portal
    Route::middleware('role:customer')->prefix('customer')->name('customer.')->group(function () {
        Route::apiResource('applications', ApplicationController::class)->only(['index', 'store', 'show']);
        Route::post('/applications/{application}/respond', [ApplicationController::class, 'respondToInfoRequest']);
        Route::get('/applications/{application}/info-requests/{infoRequest}/document', [ApplicationController::class, 'infoRequestDocument']);
        Route::apiResource('lease-agreements', LeaseAgreementController::class)
            ->only(['index', 'show'])
            ->parameters(['lease-agreements' => 'leaseAgreement']);
        Route::get('/lease-agreements/{leaseAgreement}/payment-methods', [PaymentMethodController::class, 'show']);
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/setup-intent', [PaymentMethodController::class, 'setupIntent']);
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/confirm', [PaymentMethodController::class, 'confirm']);
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/primary', [PaymentMethodController::class, 'setPrimary']);
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/remove', [PaymentMethodController::class, 'remove'])->middleware('throttle:10,1');
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/bank-pending', [PaymentMethodController::class, 'bankPending'])->middleware('throttle:10,1');
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/bank-pending/check', [PaymentMethodController::class, 'bankPendingCheck'])->middleware('throttle:20,1');
        Route::post('/lease-agreements/{leaseAgreement}/payment-methods/bank-pending/cancel', [PaymentMethodController::class, 'bankPendingCancel'])->middleware('throttle:10,1');
        Route::post('/lease-agreements/{leaseAgreement}/autopay-agreement', [PaymentMethodController::class, 'agreeToAutopay']);
        Route::post('/lease-agreements/{leaseAgreement}/autopay/revoke', [PaymentMethodController::class, 'revokeAutopay'])->middleware('throttle:10,1');
        Route::post('/lease-agreements/{leaseAgreement}/autopay/keep', [PaymentMethodController::class, 'keepAutopay'])->middleware('throttle:10,1');
        Route::get('/lease-agreements/{leaseAgreement}/extra-payment', [CustomerExtraPaymentController::class, 'show']);
        Route::post('/lease-agreements/{leaseAgreement}/extra-payment', [CustomerExtraPaymentController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/lease-agreements/{leaseAgreement}/deposit-payment', [DepositPaymentController::class, 'show']);
        Route::post('/lease-agreements/{leaseAgreement}/deposit-payment/charge', [DepositPaymentController::class, 'charge']);
        Route::post('/lease-agreements/{leaseAgreement}/deposit-payment/charge-balance', [DepositPaymentController::class, 'chargeBalance']);
        Route::get('/lease-agreements/{leaseAgreement}/contract-preview', [ContractController::class, 'preview']);
        Route::apiResource('contracts', ContractController::class)->only(['index', 'show', 'store']);
        Route::get('/contracts/{contract}/download', [ContractController::class, 'download']);
        Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
        Route::post('/payments/{payment}/pay', [PaymentController::class, 'pay'])->middleware('throttle:10,1');
        Route::get('/receipts', [CustomerReceiptController::class, 'index']);
        Route::get('/receipts/{receipt}/download', [CustomerReceiptController::class, 'download']);

        // Read-only: the equipment on this customer's own leases.
        Route::get('/equipment', [CustomerEquipmentController::class, 'index']);

        Route::post('/plaid/link-token', [PlaidController::class, 'linkToken']);
        Route::post('/plaid/exchange', [PlaidController::class, 'exchange']);
        Route::get('/plaid/status', [PlaidController::class, 'status']);

        Route::put('/notification-preferences', [NotificationPreferencesController::class, 'update']);
    });

    // Admin dashboard — role gate first, then per-module permission gate.
    // Both admin and super_admin land here; managing admin accounts is
    // super_admin only (see the nested group below).
    Route::middleware('role:admin,super_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::middleware('role:super_admin')->group(function () {
            Route::apiResource('admin-users', AdminUserController::class)
                ->parameters(['admin-users' => 'adminUser']);

            Route::get('/quickbooks/status', [QuickbooksConnectionController::class, 'status']);
            Route::post('/quickbooks/connect', [QuickbooksConnectionController::class, 'connect']);
            Route::delete('/quickbooks/disconnect', [QuickbooksConnectionController::class, 'disconnect']);
            Route::get('/quickbooks/sync', [QuickbooksSyncController::class, 'show']);
            Route::put('/quickbooks/sync', [QuickbooksSyncController::class, 'update']);
            Route::get('/quickbooks/accounts', [QuickbooksSyncController::class, 'accounts']);
            Route::post('/quickbooks/accounts/create-missing', [QuickbooksSyncController::class, 'createAccounts']);
            Route::post('/quickbooks/sync/run', [QuickbooksSyncController::class, 'run']);
            Route::post('/quickbooks/sync/retry', [QuickbooksSyncController::class, 'retry']);
        });

        Route::middleware('permission:application_review')->group(function () {
            Route::apiResource('applications', AdminApplicationController::class);
            Route::post('/applications/{application}/lease', [AdminApplicationController::class, 'attachLease']);
            Route::post('/applications/{application}/change-equipment', [AdminApplicationController::class, 'changeEquipment']);
            Route::post('/pricing/quote', [AdminPricingController::class, 'quote']);
            Route::post('/applications/{application}/resend-signing-link', [AdminApplicationController::class, 'resendContractSigningLink']);
            Route::post('/lease-agreements/{leaseAgreement}/payment-methods/clear', [AdminPaymentMethodController::class, 'clear']);
            Route::get('/applications/{application}/id-document', [AdminApplicationController::class, 'idDocument']);
            Route::get('/applications/{application}/utility-bill', [AdminApplicationController::class, 'utilityBill']);
            Route::get('/applications/{application}/info-requests/{infoRequest}/document', [AdminApplicationController::class, 'infoRequestDocument']);
            Route::post('/applications/{application}/info-requests/respond', [AdminApplicationController::class, 'respondToInfoRequestOnBehalf']);
            Route::post('/applications/{application}/dealer-notes', [DealerNoteController::class, 'store']);
            Route::post('/customers/{customer}/resend-account-setup', [AdminCustomerController::class, 'resendAccountSetup']);
            Route::get('/customers/{customer}/emails', [AdminEmailLogController::class, 'forCustomer']);
            Route::get('/email-logs', [AdminEmailLogController::class, 'index']);
            Route::apiResource('customers', AdminCustomerController::class);
        });

        Route::middleware('permission:risk_assessment')->group(function () {
            Route::apiResource('risk-profiles', RiskProfileController::class)
                ->parameters(['risk-profiles' => 'riskProfile']);
            Route::patch('/risk-profiles/{riskProfile}/red-flags/{redFlag}/resolve', [RiskProfileController::class, 'resolveRedFlag']);
            Route::post('/applications/{application}/run-background-check', [RiskProfileController::class, 'runBackgroundCheck']);
            Route::post('/applications/{application}/request-bank-verification', [RiskProfileController::class, 'requestBankVerification']);
        });

        Route::middleware('permission:contract_generation')->group(function () {
            Route::get('/contracts/{contract}/download', [AdminContractController::class, 'download']);
            Route::post('/contracts/{contract}/void', [AdminContractController::class, 'void']);
            Route::apiResource('lease-agreements', AdminLeaseAgreementController::class)
                ->parameters(['lease-agreements' => 'leaseAgreement']);
        });

        Route::middleware('permission:equipment_tracking')->group(function () {
            // Declared before the resource routes so "assignable-leases" is not
            // swallowed by the {equipmentUnit} wildcard.
            Route::get('/equipment-units/assignable-leases', [AdminEquipmentUnitController::class, 'assignableLeases']);

            Route::post('/equipment-units/{equipmentUnit}/assign', [AdminEquipmentUnitController::class, 'assign']);
            Route::post('/equipment-units/{equipmentUnit}/release', [AdminEquipmentUnitController::class, 'release']);

            Route::get('/equipment-units/{equipmentUnit}/service-records', [EquipmentServiceRecordController::class, 'index']);
            Route::post('/equipment-units/{equipmentUnit}/service-records', [EquipmentServiceRecordController::class, 'store']);
            Route::delete('/equipment-units/{equipmentUnit}/service-records/{serviceRecord}', [EquipmentServiceRecordController::class, 'destroy']);

            Route::apiResource('equipment-units', AdminEquipmentUnitController::class)
                ->parameters(['equipment-units' => 'equipmentUnit']);
        });

        Route::middleware('permission:payment_tracking')->group(function () {
            Route::post('/lease-agreements/{leaseAgreement}/autopay/pause', [AdminAutopayController::class, 'pause']);
            Route::post('/lease-agreements/{leaseAgreement}/autopay/resume', [AdminAutopayController::class, 'resume']);
            Route::post('/lease-agreements/{leaseAgreement}/autopay/revocation', [AdminAutopayController::class, 'recordRevocation'])->middleware('throttle:20,1');
            Route::post('/payments/{payment}/retry-autopay', [AdminAutopayController::class, 'retry']);
            Route::post('/applications/{application}/charge-deposit', [AdminDepositChargeController::class, 'store']);
            Route::post('/applications/{application}/charge-pickup-balance', [AdminDepositChargeController::class, 'storePickupBalance']);
            Route::get('/lease-agreements/{leaseAgreement}/autopay-methods', [AdminLeaseCardController::class, 'show']);
            Route::post('/lease-agreements/{leaseAgreement}/method-lock', [AdminMethodLockController::class, 'store'])->middleware('throttle:30,1');
            Route::post('/lease-agreements/{leaseAgreement}/card/setup-intent', [AdminLeaseCardController::class, 'setupIntent']);
            Route::post('/lease-agreements/{leaseAgreement}/card', [AdminLeaseCardController::class, 'store']);
            Route::post('/lease-agreements/{leaseAgreement}/bank-link', [AdminLeaseCardController::class, 'bankLink']);
            Route::post('/lease-agreements/{leaseAgreement}/extra-payment', [AdminExtraPaymentController::class, 'store']);
            Route::get('/lease-agreements/{leaseAgreement}/additional-funds', [AdminAdditionalFundsController::class, 'show']);
            Route::post('/lease-agreements/{leaseAgreement}/additional-funds/repairs', [AdminAdditionalFundsController::class, 'applyToRepairs'])->middleware('throttle:20,1');
            Route::post('/lease-agreements/{leaseAgreement}/additional-funds/refund', [AdminAdditionalFundsController::class, 'refundBalance'])->middleware('throttle:10,1');
            Route::post('/lease-agreements/{leaseAgreement}/end', [LeaseEndController::class, 'store']);
            Route::get('/reports/sales-tax', [ReportController::class, 'salesTax']);
            Route::get('/reports/payments.csv', [ReportController::class, 'paymentsCsv']);
            Route::get('/payments/{payment}/history', [AdminPaymentNoteController::class, 'history']);
            Route::post('/payments/{payment}/notes', [AdminPaymentNoteController::class, 'store'])->middleware('throttle:60,1');
            Route::get('/payment-audit-log', [AdminAuditLogController::class, 'index']);
            Route::get('/autopay-log', [AdminAutopayLogController::class, 'index']);
            Route::post('/payments/{payment}/parts', [AdminPaymentPartController::class, 'store'])->middleware('throttle:30,1');
            Route::get('/lease-agreements/{leaseAgreement}/receipts', [AdminPaymentReceiptController::class, 'index']);
            Route::get('/payment-receipts/{receipt}/download', [AdminPaymentReceiptController::class, 'download']);
            Route::post('/payment-receipts/{receipt}/send', [AdminPaymentReceiptController::class, 'send'])->middleware('throttle:30,1');
            Route::get('/lease-agreements/{leaseAgreement}/payment-links', [AdminPaymentLinkController::class, 'index']);
            Route::post('/payments/{payment}/links', [AdminPaymentLinkController::class, 'store'])->middleware('throttle:30,1');
            Route::post('/lease-agreements/{leaseAgreement}/payment-links/extra', [AdminPaymentLinkController::class, 'storeExtra'])->middleware('throttle:30,1');
            Route::post('/payment-links/{paymentLink}/resend', [AdminPaymentLinkController::class, 'resend'])->middleware('throttle:30,1');
            Route::post('/payment-links/{paymentLink}/cancel', [AdminPaymentLinkController::class, 'cancel']);
            Route::post('/applications/{application}/payment-parts', [AdminPaymentPartController::class, 'storeForApplication'])->middleware('throttle:30,1');
            Route::post('/payment-parts/{paymentPart}/cancel', [AdminPaymentPartController::class, 'cancel']);
            Route::post('/payments/{payment}/refunds', [AdminPaymentRefundController::class, 'store'])->middleware('throttle:20,1');
            Route::post('/payment-refunds/{paymentRefund}/cancel', [AdminPaymentRefundController::class, 'cancel']);
            Route::apiResource('payments', AdminPaymentController::class);
        });
    });
});
