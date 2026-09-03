"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { LeaseAgreementDocument } from "@/components/contracts/LeaseAgreementDocument";
import { Modal } from "@/components/ui/Modal";
import { getApplication } from "@/lib/applications";
import { downloadContract, voidContract } from "@/lib/contracts";
import { ApiError } from "@/lib/api";
import { NOTES_MAX, validateNotes } from "@/lib/validation";
import type { Application } from "@/types/application";

export default function LeaseContractPage() {
  const params = useParams<{ id: string }>();
  const [application, setApplication] = useState<Application | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const [showVoidConfirm, setShowVoidConfirm] = useState(false);
  const [voidReason, setVoidReason] = useState("");
  const [voidReasonTouched, setVoidReasonTouched] = useState(false);
  const [voiding, setVoiding] = useState(false);
  const [voidError, setVoidError] = useState<string | null>(null);

  // Mirrors the backend's min:2 rule on Admin\ContractController::void() — without
  // this, a 1-character reason passes here and only fails after a round-trip.
  const voidReasonError = !voidReason.trim()
    ? "A reason is required."
    : voidReason.trim().length < 2
      ? "Reason must be at least 2 characters."
      : validateNotes(voidReason);
  const canConfirmVoid = !voidReasonError && !voiding;

  function load() {
    return getApplication(params.id)
      .then(setApplication)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load this application."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id]);

  async function handleDownload(contractId: number) {
    setDownloading(true);
    setDownloadError(null);
    try {
      await downloadContract(contractId);
    } catch (err) {
      setDownloadError(err instanceof ApiError ? err.message : "Could not download the PDF.");
    } finally {
      setDownloading(false);
    }
  }

  function closeVoidConfirm() {
    setShowVoidConfirm(false);
    setVoidReason("");
    setVoidReasonTouched(false);
    setVoidError(null);
  }

  async function confirmVoid(contractId: number) {
    if (voidReasonError) {
      setVoidReasonTouched(true);
      return;
    }
    setVoiding(true);
    setVoidError(null);
    try {
      await voidContract(contractId, voidReason.trim());
      closeVoidConfirm();
      await load();
    } catch (err) {
      setVoidError(err instanceof ApiError ? err.message : "Could not void this signature.");
      setVoiding(false);
    }
  }

  const lease = application?.lease_agreement;
  const customer = application?.customer;
  const profile = customer?.customer_profile;
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
            <p className="text-sm text-neutral-400">
              {customer?.name ?? "…"} · Outdoor Fix{profile?.city ? ` · ${profile.city}` : ""}
            </p>
          </div>
          <div className="flex shrink-0 gap-2">
            <button
              onClick={() => window.print()}
              className="font-heading rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
            >
              Print
            </button>
            {lease?.contract && (
              <>
                <button
                  onClick={() => setShowVoidConfirm(true)}
                  className="font-heading rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-100"
                >
                  Void Signature
                </button>
                <button
                  onClick={() => handleDownload(lease.contract!.id)}
                  disabled={downloading}
                  className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {downloading ? "Preparing…" : "Download PDF"}
                </button>
              </>
            )}
          </div>
        </div>
      </div>

      {loading && <p className="text-sm text-neutral-500">Loading…</p>}
      {error && <p className="text-sm text-red-600">{error}</p>}
      {downloadError && <p className="text-sm text-red-600 print:hidden">{downloadError}</p>}
      {!loading && !error && !lease && (
        <p className="rounded-xl border border-neutral-200 bg-white p-5 text-sm text-neutral-400">
          No lease agreement exists for this application yet.
        </p>
      )}
      {lease && !lease.contract && (
        <p className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-700 print:hidden">
          This lease has not been signed yet — a downloadable PDF becomes available once the customer signs.
        </p>
      )}
      {lease && customer && (
        <LeaseAgreementDocument lease={lease} customerName={customer.name} customerAddress={address} />
      )}

      {lease?.contracts && lease.contracts.length > 0 && (
        <div className="rounded-xl border border-neutral-200 bg-white p-5 print:hidden">
          <p className="font-heading text-xs font-bold uppercase tracking-wide text-neutral-400">Signature history</p>
          <div className="mt-3 space-y-3">
            {lease.contracts.map((c) => (
              <div key={c.id} className="flex items-start justify-between gap-3 rounded-lg border border-neutral-100 bg-neutral-50 p-3 text-sm">
                <div>
                  <p className={c.voided_at ? "text-neutral-400 line-through" : "text-neutral-800"}>
                    v{c.version} — {c.signer_name ?? c.signer?.name ?? "—"} · {new Date(c.signed_at).toLocaleString()}
                  </p>
                  {c.voided_at && (
                    <p className="mt-1 text-xs text-neutral-500">
                      Voided by {c.voided_by?.name ?? "—"} on {new Date(c.voided_at).toLocaleString()} — &ldquo;{c.void_reason}&rdquo;
                    </p>
                  )}
                </div>
                <button
                  onClick={() => handleDownload(c.id)}
                  disabled={downloading}
                  className="shrink-0 text-xs font-semibold text-red-600 hover:underline disabled:opacity-50"
                >
                  Download
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {showVoidConfirm && lease?.contract && (
        <Modal title="Void this signature" onClose={closeVoidConfirm} maxWidthClassName="max-w-sm">
          <div className="space-y-4">
            <p className="text-sm text-neutral-600">
              The customer will be notified and asked to sign again. The old PDF stays on file — this doesn&rsquo;t delete anything.
            </p>
            <div>
              <textarea
                value={voidReason}
                onChange={(e) => setVoidReason(e.target.value)}
                onBlur={() => setVoidReasonTouched(true)}
                rows={3}
                placeholder="Why is this signature being voided?"
                aria-label="Reason for voiding"
                aria-invalid={voidReasonTouched && !!voidReasonError}
                autoFocus
                className={`w-full rounded-md border px-3 py-2 text-sm placeholder:text-neutral-400 focus:outline-none ${
                  voidReasonTouched && voidReasonError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
                }`}
              />
              <div className="mt-1 flex items-start justify-between gap-2">
                {voidReasonTouched && voidReasonError ? <p className="text-xs text-red-600">{voidReasonError}</p> : <span />}
                <p className={`shrink-0 text-xs ${voidReason.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}>
                  {voidReason.length}/{NOTES_MAX}
                </p>
              </div>
            </div>
            {voidError && <p className="text-xs text-red-600">{voidError}</p>}
            <div className="flex justify-end gap-2">
              <button
                onClick={closeVoidConfirm}
                disabled={voiding}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={() => confirmVoid(lease.contract!.id)}
                disabled={!canConfirmVoid}
                className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {voiding ? "Voiding…" : "Void Signature"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
