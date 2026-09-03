"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { StatusPipeline } from "@/components/applications/detail/StatusPipeline";
import { DetailCard } from "@/components/applications/detail/DetailCard";
import { FileInput } from "@/components/applications/wizard/fields";
import { downloadMyInfoRequestDocument, getMyApplication, respondToInfoRequest } from "@/lib/applications";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { NOTES_MAX, validateNotes } from "@/lib/validation";
import type { Application } from "@/types/application";
import type { AppStatus } from "@/components/applications/detail/types";

const BADGE_STYLE: Record<AppStatus, { label: string; color: string }> = {
  submitted: { label: "Submitted", color: "bg-neutral-700 text-white" },
  under_review: { label: "Under Review", color: "bg-amber-500 text-white" },
  needs_info: { label: "Needs Info", color: "bg-amber-500 text-white" },
  approved: { label: "Approved", color: "bg-blue-600 text-white" },
  completed: { label: "Completed", color: "bg-teal-500 text-white" },
  processed: { label: "Processed", color: "bg-purple-600 text-white" },
  funded_paid: { label: "Funded", color: "bg-green-600 text-white" },
  declined: { label: "Application Declined", color: "bg-neutral-100 text-red-700 border border-red-200" },
  withdrawn: { label: "Withdrawn", color: "bg-neutral-200 text-neutral-700" },
};

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function CustomerApplicationDetailPage() {
  const params = useParams<{ id: string }>();
  const [application, setApplication] = useState<Application | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [replyText, setReplyText] = useState("");
  const [newDocument, setNewDocument] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [responded, setResponded] = useState(false);

  const replyTextError = replyText.trim() ? validateNotes(replyText) : undefined;
  const canRespond = (!!replyText.trim() || !!newDocument) && !replyTextError && !sending;

  useEffect(() => {
    getMyApplication(params.id)
      .then(setApplication)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load this application."))
      .finally(() => setLoading(false));
  }, [params.id]);

  async function handleRespond() {
    if (!application || !canRespond) return;
    setSending(true);
    setSendError(null);
    try {
      setApplication(await respondToInfoRequest(application.id, { replyText: replyText.trim() || undefined, file: newDocument }));
      setReplyText("");
      setNewDocument(null);
      setResponded(true);
    } catch (err) {
      setSendError(err instanceof ApiError ? err.message : "Could not send your response. Please try again.");
    } finally {
      setSending(false);
    }
  }

  if (loading) return <p className="py-12 text-center text-sm text-neutral-400">Loading…</p>;
  if (error || !application) return <p className="py-12 text-center text-sm text-red-600">{error ?? "Application not found."}</p>;

  const lease = application.lease_agreement;
  const equipment = lease?.equipment_unit;
  const badge = BADGE_STYLE[application.status];
  const openInfoRequest = application.info_requests?.find((r) => !r.replied_at);
  const answeredInfoRequests = application.info_requests?.filter((r) => r.replied_at) ?? [];

  return (
    <div className="space-y-6">
      <div>
        <Link href="/customer/applications" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
          ← My Applications
        </Link>
        <div className="mt-1 flex items-center gap-3">
          <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">Application #{application.id}</h1>
          <span className={`font-heading rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ${badge.color}`}>{badge.label}</span>
        </div>
        <p className="text-sm text-neutral-400">Submitted {new Date(application.created_at).toLocaleDateString()}</p>
      </div>

      {application.status !== "declined" && application.status !== "withdrawn" && <StatusPipeline status={application.status} />}

      {application.status === "declined" && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
          <p className="text-sm font-bold text-red-700">Application Declined</p>
          <p className="text-xs text-neutral-500">{application.status_notes ?? "Contact Outdoor Fix for details."}</p>
        </div>
      )}

      {responded && application.status !== "needs_info" && (
        <div className="rounded-xl border border-green-200 bg-green-50 px-5 py-4">
          <p className="text-sm font-bold text-green-700">Response sent</p>
          <p className="text-xs text-neutral-600">Your response was sent back to Outdoor Fix for review.</p>
        </div>
      )}

      {application.status === "needs_info" && openInfoRequest && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
          <p className="text-sm font-bold text-amber-800">Action required</p>
          <p className="text-xs text-neutral-600">{openInfoRequest.request_text}</p>
          <div className="mt-3 space-y-3">
            <div>
              <textarea
                value={replyText}
                onChange={(e) => setReplyText(e.target.value)}
                rows={3}
                placeholder="Type a reply (optional if you're attaching a document below)..."
                aria-label="Reply to Outdoor Fix"
                className={`w-full rounded-md border bg-white px-3 py-2 text-sm placeholder:text-neutral-400 focus:outline-none ${
                  replyTextError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-amber-300"
                }`}
              />
              <div className="mt-1 flex items-start justify-between gap-2">
                {replyTextError ? <p className="text-xs text-red-600">{replyTextError}</p> : <span />}
                <p className={`shrink-0 text-xs ${replyText.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}>
                  {replyText.length}/{NOTES_MAX}
                </p>
              </div>
            </div>
            <FileInput value={newDocument} onChange={setNewDocument} />
            {sendError && <p className="text-xs text-red-600">{sendError}</p>}
            <button
              onClick={handleRespond}
              disabled={!canRespond}
              className="font-heading rounded-md bg-amber-600 px-4 py-2 text-xs font-bold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {sending ? "Sending…" : "Send Response"}
            </button>
          </div>
        </div>
      )}

      {answeredInfoRequests.length > 0 && (
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <p className="font-heading text-xs font-bold uppercase tracking-wide text-neutral-400">Previous requests</p>
          <div className="mt-3 space-y-3">
            {answeredInfoRequests.map((r) => (
              <div key={r.id} className="rounded-lg border border-neutral-100 bg-neutral-50 p-3">
                <p className="text-sm text-neutral-700">
                  <span className="font-semibold text-neutral-500">Outdoor Fix asked:</span> {r.request_text}
                </p>
                {r.reply_text && (
                  <p className="mt-1.5 text-sm text-neutral-700">
                    <span className="font-semibold text-neutral-500">You replied:</span> {r.reply_text}
                  </p>
                )}
                {r.reply_has_document && (
                  <button
                    onClick={() => downloadMyInfoRequestDocument(application.id, r.id, `application-${application.id}-id-r${r.id}`)}
                    className="mt-1.5 text-sm font-semibold text-red-600 hover:underline"
                  >
                    {r.reply_text ? "Also attached: " : "You attached: "}your uploaded document — Download →
                  </button>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {lease && (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <DetailCard
            title="Equipment"
            rows={[
              { label: "Make / model", value: equipment?.model ?? "—" },
              { label: "Cash price", value: money(num(lease.cash_price)) },
              { label: "Condition", value: equipment?.condition_notes ?? "—" },
            ]}
          />
          <DetailCard
            title="Lease terms"
            rows={[
              { label: "Term", value: `${lease.term_months} months` },
              { label: "Monthly rental", value: money(num(lease.monthly_rental_payment)) },
              { label: "Total monthly", value: money(lease.total_monthly_payment) },
              { label: "Security deposit", value: money(num(lease.security_deposit)) },
            ]}
          />
        </div>
      )}

      <p className="text-sm text-neutral-400">
        An Outdoor Fix representative will reach out as your application moves through review. Once it&rsquo;s funded, it will appear on your{" "}
        <Link href="/customer/dashboard" className="font-semibold text-red-600 hover:underline">My Lease</Link> page.
      </p>
    </div>
  );
}
