"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import { AutopaySetupCard } from "@/components/payment-methods/AutopaySetupCard";
import { DepositPaymentCard } from "@/components/payment-methods/DepositPaymentCard";
import { getMyLeaseAgreement } from "@/lib/lease-agreements";
import {
  confirmPaymentMethod,
  createSetupIntent,
  getPaymentMethodsStatus,
  setPrimaryMethod,
  type PaymentMethodsStatus,
} from "@/lib/payment-methods";
import {
  chargeBalancePayment,
  chargeDepositPayment,
  getDepositPaymentStatus,
  type DepositPaymentStatus,
} from "@/lib/deposit-payment";
import { ApiError } from "@/lib/api";

/**
 * Authenticated counterpart to /setup-autopay — reached from the customer's
 * own portal once they already have a working login. A guest-originated
 * customer without one yet uses the signed-link page instead (see
 * PaymentMethodSigner on the backend for why the fork exists).
 */
export default function AutopaySetupPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();

  const [status, setStatus] = useState<PaymentMethodsStatus | null>(null);
  const [depositStatus, setDepositStatus] = useState<DepositPaymentStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    const leaseId = Number(params.id);
    getMyLeaseAgreement(params.id)
      .then(() => Promise.all([getPaymentMethodsStatus(leaseId), getDepositPaymentStatus(leaseId)]))
      .then(([methodsData, deposit]) => {
        setStatus(methodsData);
        setDepositStatus(deposit);
      })
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Could not load this lease."))
      .finally(() => setLoading(false));
  }, [params.id]);

  if (loading) return <p className="text-sm text-neutral-500">Loading…</p>;
  if (loadError || !status) return <p className="text-sm text-red-600">{loadError ?? "Lease not found."}</p>;

  const leaseId = Number(params.id);
  const bothAdded = status.bank_account_added && status.card_added;
  const atLeastOneMethodAdded = status.bank_account_added || status.card_added;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/customer/payments"
          className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline"
        >
          ← Back
        </Link>
        <h1 className="mt-1 text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">Set up AutoPay</h1>
        <p className="text-sm text-neutral-400">
          Add a bank account and a backup card so your monthly payments happen automatically.
        </p>
      </div>

      <AutopaySetupCard
        status={status}
        onStatusChange={setStatus}
        onCreateSetupIntent={(type) => createSetupIntent(leaseId, type)}
        onConfirm={(type, paymentMethodId) => confirmPaymentMethod(leaseId, type, paymentMethodId)}
        onSetPrimary={(type) => setPrimaryMethod(leaseId, type)}
        billingName={user?.name ?? ""}
        billingEmail={user?.email ?? ""}
      />

      {bothAdded && (
        <p className="text-sm font-semibold text-green-700">You&rsquo;re all set — AutoPay is ready to go.</p>
      )}

      {atLeastOneMethodAdded && depositStatus && (
        <DepositPaymentCard
          status={depositStatus}
          onStatusChange={setDepositStatus}
          onChargeDeposit={() => chargeDepositPayment(leaseId)}
          onChargeBalance={() => chargeBalancePayment(leaseId)}
          onRefreshStatus={() => getDepositPaymentStatus(leaseId)}
        />
      )}
    </div>
  );
}
