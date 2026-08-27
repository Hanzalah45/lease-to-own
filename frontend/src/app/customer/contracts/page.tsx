"use client";

import Link from "next/link";
import { SAMPLE_LEASES } from "@/lib/sample-lease";

export default function CustomerContractsPage() {
  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">Your Contract</h1>
        <p className="text-sm text-neutral-400">Signed lease agreements, documents, and payoff terms.</p>
      </div>

      <div className="space-y-4">
        {SAMPLE_LEASES.map((lease) => (
          <div
            key={lease.id}
            className="flex flex-col gap-4 rounded-xl border border-neutral-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <div className="mb-1 flex items-center gap-2">
                <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
                <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
                  Lease Purchase Agreement V1 — {lease.equipment}
                </h2>
              </div>
              <p className="text-sm text-neutral-500">
                {lease.signed ? (
                  <>
                    Signed {lease.signedAt} · <span className="font-semibold text-green-600">Signed &amp; legally valid</span>
                  </>
                ) : (
                  <span className="font-semibold text-amber-600">Awaiting your signature</span>
                )}
              </p>
            </div>
            <div className="flex shrink-0 gap-2">
              {lease.signed ? (
                <>
                  <Link
                    href={`/customer/contracts/${lease.id}/sign`}
                    className="font-heading rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
                  >
                    Preview e-sign flow
                  </Link>
                  <Link
                    href={`/customer/contracts/${lease.id}/document`}
                    className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
                  >
                    View / Download PDF →
                  </Link>
                </>
              ) : (
                <Link
                  href={`/customer/contracts/${lease.id}/sign`}
                  className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
                >
                  Sign Now →
                </Link>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
