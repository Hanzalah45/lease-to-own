"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { listRiskProfiles } from "@/lib/risk-profiles";
import { resolveRiskRedFlag } from "@/lib/applications";
import { ApiError } from "@/lib/api";
import type { RiskProfile } from "@/types/risk-profile";

function pct(value: number, total: number): number {
  return total === 0 ? 0 : Math.round((value / total) * 100);
}

function flagReason(profile: RiskProfile): string {
  if (profile.landlord_contact_required) return profile.landlord_contact_reason ?? "Landlord contact required";
  if (profile.background_check_status === "flagged") return profile.background_check_notes ?? "Background check flagged";
  if (profile.bank_verification_status !== "verified") return "Bank verification (Plaid) pending";
  if (profile.identity_verification_status !== "verified") return "Identity verification pending";
  if (profile.employment_verification_status !== "verified") return "Employment verification pending";
  return "Under review";
}

export function RiskAssessmentPanel() {
  const [profiles, setProfiles] = useState<RiskProfile[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [resolvingFlagId, setResolvingFlagId] = useState<number | null>(null);
  const [resolveError, setResolveError] = useState<string | null>(null);

  function load() {
    return listRiskProfiles()
      .then(setProfiles)
      .catch((err) =>
        setError(
          err instanceof ApiError && err.status === 403
            ? "Your admin account does not include risk assessment."
            : "Could not load risk profiles.",
        ),
      )
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  // Resolving a flag is the one risk_assessment action this panel exists to
  // support — without it here, an admin scoped to risk_assessment only (no
  // application_review) has a queue of flagged customers with no way to
  // clear any of them, since the application detail page's own resolve
  // button sits behind a permission they may not have.
  async function resolveFlag(riskProfileId: number, redFlagId: number) {
    setResolvingFlagId(redFlagId);
    setResolveError(null);
    try {
      await resolveRiskRedFlag(riskProfileId, redFlagId);
      await load();
    } catch (err) {
      setResolveError(err instanceof ApiError ? err.message : "Could not resolve this flag.");
    } finally {
      setResolvingFlagId(null);
    }
  }

  if (loading) return <p className="py-6 text-sm text-neutral-500">Loading risk profiles…</p>;
  if (error) return <p className="py-6 text-sm text-neutral-500">{error}</p>;

  const openRedFlags = profiles.reduce((sum, p) => sum + (p.red_flags?.filter((f) => !f.resolved).length ?? 0), 0);
  const bankPending = profiles.filter((p) => p.bank_verification_status === "pending").length;
  const backgroundPending = profiles.filter((p) => p.background_check_status === "pending").length;
  const clear = profiles.filter((p) => p.background_check_status === "clear").length;
  const total = profiles.length;

  const attention = profiles
    .filter((p) => p.background_check_status === "flagged" || p.landlord_contact_required || (p.red_flags?.some((f) => !f.resolved) ?? false))
    .slice(0, 6);

  const columns: DataTableColumn<RiskProfile>[] = [
    {
      key: "risk",
      header: "Risk",
      render: (r) =>
        r.background_check_status === "flagged" || (r.red_flags?.some((f) => !f.resolved) ?? false) ? (
          <StatusTag color="#DC2626" label="Flagged" />
        ) : (
          <StatusTag color="#D97706" label="Needs attention" />
        ),
    },
    {
      key: "customer",
      header: "Customer",
      render: (r) => (
        <>
          <p className="font-medium text-neutral-900">{r.customer?.name ?? "—"}</p>
          <p className="text-xs text-neutral-400">Risk score: {r.risk_score ?? "—"}/100</p>
        </>
      ),
    },
    { key: "reason", header: "Flag reason", render: (r) => <span className="text-neutral-700">{flagReason(r)}</span> },
    {
      key: "action",
      header: "",
      render: (r) => {
        const openFlag = r.red_flags?.find((f) => !f.resolved);
        return (
          <div className="flex items-center justify-end gap-3">
            {openFlag && (
              <button
                onClick={() => resolveFlag(r.id, openFlag.id)}
                disabled={resolvingFlagId === openFlag.id}
                className="text-sm font-semibold text-green-700 hover:underline disabled:opacity-50"
              >
                {resolvingFlagId === openFlag.id ? "Resolving…" : "Resolve"}
              </button>
            )}
            <Link href={`/admin/customers/${r.customer_id}`} className="text-sm font-semibold text-red-600 hover:underline">
              Review →
            </Link>
          </div>
        );
      },
    },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <MetricCard value={openRedFlags} label="Open red flags" barColor="#D97706" barPercent={pct(openRedFlags, total)} />
        <MetricCard value={bankPending} label="Bank verify pending" barColor="#2563EB" barPercent={pct(bankPending, total)} />
        <MetricCard value={backgroundPending} label="Background pending" barColor="#171717" barPercent={pct(backgroundPending, total)} />
        <MetricCard value={clear} label="Clear / passed" barColor="#16A34A" barPercent={pct(clear, total)} />
      </div>
      {resolveError && <p className="text-sm text-red-600">{resolveError}</p>}
      <DataTable title="Risk queue" columns={columns} rows={attention} emptyLabel="No open risk items." />
    </div>
  );
}
