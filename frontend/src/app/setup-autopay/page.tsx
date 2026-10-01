"use client";

import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { AutopaySetupCard } from "@/components/payment-methods/AutopaySetupCard";
import { DepositPaymentCard } from "@/components/payment-methods/DepositPaymentCard";
import { ApiError } from "@/lib/api";
import {
  confirmSignedPaymentMethod,
  createSignedSetupIntent,
  getSignedPaymentMethodsStatus,
  setSignedPrimaryMethod,
  type PaymentMethodsStatus,
  type SignedPaymentMethodLinkParams,
} from "@/lib/payment-methods";
import {
  chargeSignedDepositPayment,
  getSignedDepositPaymentStatus,
  type DepositPaymentStatus,
} from "@/lib/deposit-payment";

/**
 * Public counterpart to the customer portal's AutoPay setup step — reached
 * from the signed link PaymentMethodsRequestedNotification emails once an
 * application reaches "waiting on deposit" (see PaymentMethodSigner on the
 * backend). Exists for the same reason /sign-contract does: a
 * guest-originated customer's account has no usable password yet (activated
 * at first-payment/pickup, which happens AFTER this step), so they can't log
 * in to reach /customer/lease-agreements/[id].
 */
export default function SetupAutopayPage() {
  return (
    <Suspense fallback={null}>
      <SetupAutopayFlow />
    </Suspense>
  );
}

function SetupAutopayFlow() {
  const searchParams = useSearchParams();
  const id = searchParams.get("id");
  const lease = searchParams.get("lease");
  const hash = searchParams.get("hash");
  const expires = searchParams.get("expires");
  const signature = searchParams.get("signature");
  const hasAllParams = Boolean(id && lease && hash && expires && signature);
  const params: SignedPaymentMethodLinkParams | null = hasAllParams
    ? { id: id!, lease: lease!, hash: hash!, expires: expires!, signature: signature! }
    : null;

  const [status, setStatus] = useState<PaymentMethodsStatus | null>(null);
  const [depositStatus, setDepositStatus] = useState<DepositPaymentStatus | null>(null);
  // Held separately from `status` — it never changes after the initial load,
  // while `status` gets replaced wholesale by each confirm()/setPrimary()
  // response (which doesn't carry these two fields back).
  const [customerName, setCustomerName] = useState("");
  const [customerEmail, setCustomerEmail] = useState("");
  const [loading, setLoading] = useState(hasAllParams);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    if (!params) return;
    Promise.all([getSignedPaymentMethodsStatus(params), getSignedDepositPaymentStatus(params)])
      .then(([methodsData, deposit]) => {
        setStatus(methodsData);
        setCustomerName(methodsData.customer_name);
        setCustomerEmail(methodsData.customer_email);
        setDepositStatus(deposit);
      })
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "This link is invalid or has expired."))
      .finally(() => setLoading(false));
    // Runs once on mount with whatever the URL carried.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const bothAdded = status?.bank_account_added && status?.card_added;
  const atLeastOneMethodAdded = status?.bank_account_added || status?.card_added;

  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-xl py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Set up AutoPay</h1>
          <p className="mt-1 text-sm text-neutral-500">
            Add a bank account and a backup card so your monthly payments happen automatically.
          </p>
        </div>

        {!hasAllParams ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-red-600">This link is incomplete.</p>
          </div>
        ) : loading ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-neutral-400">Checking link…</p>
          </div>
        ) : loadError || !status || !params ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-red-600">{loadError ?? "Lease not found."}</p>
          </div>
        ) : (
          <div className="space-y-5">
            <AutopaySetupCard
              status={status}
              onStatusChange={setStatus}
              onCreateSetupIntent={(type) => createSignedSetupIntent(params, type)}
              onConfirm={(type, paymentMethodId) => confirmSignedPaymentMethod(params, type, paymentMethodId)}
              onSetPrimary={(type) => setSignedPrimaryMethod(params, type)}
              billingName={customerName}
              billingEmail={customerEmail}
            />

            {bothAdded && (
              <p className="text-center text-sm font-semibold text-green-700">
                You&rsquo;re all set — AutoPay is ready to go.
              </p>
            )}

            {atLeastOneMethodAdded && depositStatus && (
              <DepositPaymentCard
                status={depositStatus}
                onStatusChange={setDepositStatus}
                onCharge={() => chargeSignedDepositPayment(params)}
                onRefreshStatus={() => getSignedDepositPaymentStatus(params)}
              />
            )}
          </div>
        )}

        <div className="mt-6 text-center text-sm text-neutral-500">
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        </div>
      </div>
    </main>
  );
}
