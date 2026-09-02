"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { LeaseAgreementDocument } from "@/components/contracts/LeaseAgreementDocument";
import { getMyLeaseAgreement } from "@/lib/lease-agreements";
import { ApiError } from "@/lib/api";
import type { LeaseAgreement } from "@/types/lease-agreement";

export default function CustomerLeaseDocumentPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
  const [lease, setLease] = useState<LeaseAgreement | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getMyLeaseAgreement(params.id)
      .then(setLease)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load this contract."))
      .finally(() => setLoading(false));
  }, [params.id]);

  const profile = user?.customer_profile;
  const address = [profile?.address_line_1, profile?.city, profile?.state, profile?.zip].filter(Boolean).join(", ");

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 print:hidden sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <Link
          href="/customer/contracts"
          className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline"
        >
          ← Back to contracts
        </Link>
        <div className="mt-1 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
              Lease Purchase Agreement
            </h1>
            <p className="text-sm text-neutral-400">
              {user?.name} · {lease?.equipment_unit?.model ?? "…"}
            </p>
          </div>
          <div className="flex shrink-0 gap-2">
            <button
              onClick={() => window.print()}
              className="font-heading rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
            >
              Print
            </button>
            <button
              onClick={() => window.print()}
              title="Opens the print dialog — choose “Save as PDF” as the destination"
              className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
            >
              Download PDF
            </button>
          </div>
        </div>
      </div>

      {loading && <p className="text-sm text-neutral-500">Loading…</p>}
      {error && <p className="text-sm text-red-600">{error}</p>}
      {lease && user && <LeaseAgreementDocument lease={lease} customerName={user.name} customerAddress={address} />}
    </div>
  );
}
