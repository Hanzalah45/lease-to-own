"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { money } from "@/components/applications/wizard/types";
import { CUSTOMER_NAME, getLease, SAMPLE_LEASES } from "@/lib/sample-lease";

export default function SignLeaseAgreementPage() {
  const params = useParams<{ id: string }>();
  const lease = getLease(Number(params.id)) ?? SAMPLE_LEASES[0];

  const [agreed, setAgreed] = useState(false);
  const [typedName, setTypedName] = useState("");
  const [signed, setSigned] = useState(lease.signed);

  const canSign = agreed && typedName.trim().length > 2;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/customer/contracts" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
          ← Back
        </Link>
        <h1 className="mt-1 text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
          Sign Your Lease Agreement
        </h1>
        <p className="text-sm text-neutral-400">Review the terms below, then sign to complete your lease.</p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Agreement summary</h2>
        </div>
        <div className="space-y-2.5 text-sm">
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Customer</span>
            <span className="font-semibold text-neutral-900">{CUSTOMER_NAME}</span>
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Equipment</span>
            <span className="font-semibold text-neutral-900">{lease.equipment}</span>
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Term</span>
            <span className="font-semibold text-neutral-900">{lease.term} months</span>
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Total monthly payment</span>
            <span className="font-semibold text-neutral-900">{money(lease.totalMonthly)}</span>
          </div>
          <div className="flex items-center justify-between py-1">
            <span className="text-neutral-500">Total due today</span>
            <span className="font-semibold text-neutral-900">{money(lease.totalDue)}</span>
          </div>
        </div>
      </div>

      {signed ? (
        <div className="rounded-xl border border-green-200 bg-green-50 p-5">
          <p className="text-sm font-bold text-green-700">Signed &amp; legally valid</p>
          <p className="mt-1 text-sm text-neutral-600">
            Signed by {CUSTOMER_NAME} on {lease.signedAt || "just now"}.
          </p>
          <Link
            href={`/customer/contracts/${lease.id}/document`}
            className="font-heading mt-4 inline-block rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            View signed document →
          </Link>
        </div>
      ) : (
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <div className="mb-4 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Signature</h2>
          </div>

          <label className="mb-4 flex items-start gap-2.5 text-sm text-neutral-700">
            <input
              type="checkbox"
              checked={agreed}
              onChange={(e) => setAgreed(e.target.checked)}
              className="mt-0.5 h-4 w-4 accent-red-600"
            />
            I have read and agree to the Lease Purchase Agreement, Early Purchase Option terms, and AutoPay Payment
            Authorization.
          </label>

          <div className="rounded-md border border-dashed border-neutral-300 p-6 text-center">
            <input
              value={typedName}
              onChange={(e) => setTypedName(e.target.value)}
              placeholder="Type your full legal name"
              className="w-full border-b border-neutral-900 bg-transparent pb-2 text-center font-serif text-2xl italic text-neutral-700 placeholder:text-neutral-300 focus:outline-none"
            />
            <p className="mt-3 text-xs text-neutral-400">
              Typing your name above and clicking Sign constitutes your legal electronic signature.
            </p>
          </div>

          <button
            onClick={() => setSigned(true)}
            disabled={!canSign}
            className="font-heading mt-4 w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            Sign &amp; Complete →
          </button>
        </div>
      )}
    </div>
  );
}
