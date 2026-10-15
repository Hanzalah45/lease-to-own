"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { listLeaseAgreements } from "@/lib/lease-agreements";
import { downloadContract } from "@/lib/contracts";
import { ApiError } from "@/lib/api";
import type { LeaseAgreement } from "@/types/lease-agreement";

/**
 * The customer's name and mower, with the name linking to their customer account (client, Joel, 2026-10-15):
 * from this page only the contract was reachable, and finding the account meant searching a long list.
 */
function CustomerCell({ lease }: { lease: LeaseAgreement }) {
  return (
    <>
      {lease.customer ? (
        <Link href={`/admin/customers/${lease.customer.id}`} className="font-medium text-neutral-900 hover:text-red-600 hover:underline">
          {lease.customer.name}
        </Link>
      ) : (
        <p className="font-medium text-neutral-900">—</p>
      )}
      <p className="text-xs text-neutral-400">{lease.equipment_unit?.model ?? "—"}</p>
    </>
  );
}

export default function AdminContractsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const canGenerateContracts = isSuperAdmin || restrictions.length === 0 || restrictions.includes("contract_generation");

  const [leases, setLeases] = useState<LeaseAgreement[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  useEffect(() => {
    if (!canGenerateContracts) return;
    listLeaseAgreements()
      .then(setLeases)
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Could not load contracts."))
      .finally(() => setLoading(false));
  }, [canGenerateContracts]);

  if (!canGenerateContracts) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Contracts</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include contract generation. Ask a super admin to add the
          Contract Generation permission to your account.
        </p>
      </div>
    );
  }

  async function handleDownload(contractId: number) {
    setDownloadingId(contractId);
    setDownloadError(null);
    try {
      await downloadContract(contractId);
    } catch (err) {
      setDownloadError(err instanceof ApiError ? err.message : "Could not download the PDF.");
    } finally {
      setDownloadingId(null);
    }
  }

  const signed = leases.filter((l) => l.contract).sort((a, b) => {
    const at = a.contract ? new Date(a.contract.signed_at).getTime() : 0;
    const bt = b.contract ? new Date(b.contract.signed_at).getTime() : 0;
    return bt - at;
  });
  const awaiting = leases.filter((l) => !l.contract);

  // A voided signature leaves the signed list, so these customers would otherwise vanish from this page
  // (client, Joel, 2026-10-14). Newest void first.
  const lastVoid = (l: LeaseAgreement) =>
    (l.contracts ?? [])
      .filter((c) => c.voided_at)
      .sort((a, b) => new Date(b.voided_at!).getTime() - new Date(a.voided_at!).getTime())[0];
  const voided = awaiting
    .filter((l) => lastVoid(l))
    .sort((a, b) => new Date(lastVoid(b)!.voided_at!).getTime() - new Date(lastVoid(a)!.voided_at!).getTime());

  const voidedColumns: DataTableColumn<LeaseAgreement>[] = [
    {
      key: "customer",
      header: "Customer",
      render: (r) => <CustomerCell lease={r} />,
    },
    {
      key: "voided_at",
      header: "Voided",
      render: (r) => <span className="text-neutral-500">{new Date(lastVoid(r)!.voided_at!).toLocaleDateString()}</span>,
    },
    {
      key: "reason",
      header: "Reason",
      render: (r) => <span className="text-neutral-500">{lastVoid(r)?.void_reason ?? "—"}</span>,
    },
    {
      key: "status",
      header: "Status",
      render: () => <StatusTag color="#D97706" label="Waiting for a new signature" />,
    },
    {
      key: "action",
      header: "",
      render: (r) => (
        <Link href={`/admin/applications/${r.application_id}/contract`} className="text-sm font-semibold text-red-600 hover:underline">
          View →
        </Link>
      ),
    },
  ];

  const columns: DataTableColumn<LeaseAgreement>[] = [
    {
      key: "customer",
      header: "Customer",
      render: (r) => <CustomerCell lease={r} />,
    },
    { key: "term", header: "Term", render: (r) => <span className="text-neutral-500">{r.term_months} mo</span> },
    {
      key: "signed_at",
      header: "Signed",
      render: (r) => (
        <span className="text-neutral-500">{r.contract ? new Date(r.contract.signed_at).toLocaleDateString() : "—"}</span>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: () => <StatusTag color="#16A34A" label="Signed" />,
    },
    {
      key: "action",
      header: "",
      render: (r) => (
        <div className="flex items-center gap-3">
          <Link href={`/admin/applications/${r.application_id}/contract`} className="text-sm font-semibold text-red-600 hover:underline">
            View →
          </Link>
          {r.contract && (
            <button
              onClick={() => handleDownload(r.contract!.id)}
              disabled={downloadingId === r.contract.id}
              className="text-sm font-semibold text-neutral-700 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
            >
              {downloadingId === r.contract.id ? "Preparing…" : "Download"}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Contracts"
        subtitle="Every signed lease agreement on file, with the current e-signature and a downloadable PDF, and the customers whose signature was voided."
      />

      {loading && <p className="text-sm text-neutral-500">Loading…</p>}
      {loadError && <p className="text-sm text-red-600">{loadError}</p>}
      {downloadError && <p className="text-sm text-red-600">{downloadError}</p>}

      {!loading && !loadError && (
        <>
          <DataTable title="Signed contracts" columns={columns} rows={signed} emptyLabel="No contracts signed yet." />
          {voided.length > 0 && (
            <DataTable title="Signature voided" columns={voidedColumns} rows={voided} emptyLabel="" />
          )}
          {awaiting.length > 0 && (
            <p className="text-sm text-neutral-400">
              {awaiting.length} lease agreement{awaiting.length === 1 ? "" : "s"} still awaiting the customer&apos;s signature.
            </p>
          )}
        </>
      )}
    </div>
  );
}
