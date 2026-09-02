"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { listLeaseAgreements } from "@/lib/lease-agreements";
import { ApiError } from "@/lib/api";
import type { LeaseAgreement } from "@/types/lease-agreement";

function pct(value: number, total: number): number {
  return total === 0 ? 0 : Math.round((value / total) * 100);
}

function isSignedThisWeek(lease: LeaseAgreement): boolean {
  if (!lease.contract) return false;
  const signedAt = new Date(lease.contract.signed_at).getTime();
  return Date.now() - signedAt < 7 * 24 * 60 * 60 * 1000;
}

export function ContractGenerationPanel() {
  const [leases, setLeases] = useState<LeaseAgreement[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listLeaseAgreements()
      .then(setLeases)
      .catch((err) =>
        setError(
          err instanceof ApiError && err.status === 403
            ? "Your admin account does not include contract generation."
            : "Could not load lease agreements.",
        ),
      )
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="py-6 text-sm text-neutral-500">Loading contracts…</p>;
  if (error) return <p className="py-6 text-sm text-neutral-500">{error}</p>;

  const awaiting = leases.filter((l) => !l.contract);
  const signedThisWeek = leases.filter(isSignedThisWeek);
  const totalSigned = leases.filter((l) => l.contract);
  const total = leases.length;

  const rows = [...awaiting, ...totalSigned]
    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
    .slice(0, 6);

  const columns: DataTableColumn<LeaseAgreement>[] = [
    {
      key: "status",
      header: "Status",
      render: (r) => (r.contract ? <StatusTag color="#16A34A" label="Signed" /> : <StatusTag color="#D97706" label="Awaiting sig." />),
    },
    {
      key: "customer",
      header: "Customer",
      render: (r) => (
        <>
          <p className="font-medium text-neutral-900">{r.customer?.name ?? "—"}</p>
          <p className="text-xs text-neutral-400">{r.equipment_unit?.model ?? "—"}</p>
        </>
      ),
    },
    { key: "term", header: "Term", render: (r) => <span className="text-neutral-500">{r.term_months} mo</span> },
    {
      key: "action",
      header: "",
      render: (r) => (
        <Link href={`/admin/applications/${r.application_id}/contract`} className="text-sm font-semibold text-red-600 hover:underline">
          {r.contract ? "View →" : "Open →"}
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <MetricCard value={awaiting.length} label="Awaiting signature" barColor="#D97706" barPercent={pct(awaiting.length, total)} />
        <MetricCard value={signedThisWeek.length} label="Signed this week" barColor="#16A34A" barPercent={pct(signedThisWeek.length, total)} />
        <MetricCard value={totalSigned.length} label="Total signed on file" barColor="#171717" barPercent={pct(totalSigned.length, total)} />
      </div>
      <DataTable title="Contracts pending generation / signature" columns={columns} rows={rows} emptyLabel="No lease agreements yet." />
    </div>
  );
}
