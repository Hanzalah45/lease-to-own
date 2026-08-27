"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { LeaseAgreementDocument } from "@/components/contracts/LeaseAgreementDocument";
import { getLease, SAMPLE_LEASES } from "@/lib/sample-lease";

export default function LeaseContractPage() {
  const params = useParams<{ id: string }>();
  const lease = getLease(Number(params.id)) ?? SAMPLE_LEASES[0];

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
          href={`/admin/applications/${params.id}`}
          className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline"
        >
          ← Back to application
        </Link>
        <div className="mt-1 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
              Lease Purchase Agreement
            </h1>
            <p className="text-sm text-neutral-400">Robert Kirkland · Outdoor Fix · Willis</p>
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

      <LeaseAgreementDocument lease={lease} />
    </div>
  );
}
