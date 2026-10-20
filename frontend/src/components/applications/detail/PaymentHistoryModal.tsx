"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { methodLabel, paymentTotal } from "@/lib/application-progress";
import { NOTE_KINDS, addPaymentNote, getPaymentHistory, type NoteKind } from "@/lib/payments";
import type { Payment, PaymentAuditEntry, PaymentNote } from "@/types/lease-agreement";

/** The label staff see for who did something. */
export function actorLabel(entry: Pick<PaymentAuditEntry, "actor_type" | "actor_name">): string {
  if (entry.actor_type === "system") return "System";
  const name = entry.actor_name ?? "Unknown";

  return entry.actor_type === "customer" ? `${name} (customer)` : name;
}

/** Where an action was made from: the address, and the browser or device in the tooltip. */
export function FromCell({ entry }: { entry: Pick<PaymentAuditEntry, "ip_address" | "user_agent"> }) {
  if (!entry.ip_address) return <span className="text-neutral-400">—</span>;

  return (
    <span title={entry.user_agent ?? undefined} className="tabular-nums text-neutral-600">
      {entry.ip_address}
    </span>
  );
}

const TYPE_LABEL: Record<Payment["type"], string> = {
  deposit: "Security deposit",
  pickup_balance: "Tracking fee and first month",
  rental: "Monthly payment",
  late_fee: "Late fee",
  extra_payment: "Extra payment",
};

const KIND_LABEL: Record<PaymentNote["kind"], string> = { general: "Note", failed: "Failed payment", refund: "Refund", chargeback: "Chargeback" };

/**
 * What staff wrote about one payment and everything that was done to it (client, Joel, 2026-10-19 checklist, section 9): the notes
 * with a place to add one, and the audit trail with who, when, the method and where it came from. Notes are never shown to customers.
 */
export function PaymentHistoryModal({
  payment,
  onClose,
  onChanged,
}: {
  payment: Payment;
  onClose: () => void;
  /** A note was added, so the page can load the payments again. */
  onChanged?: () => void;
}) {
  const [notes, setNotes] = useState<PaymentNote[]>([]);
  const [audit, setAudit] = useState<PaymentAuditEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [kind, setKind] = useState<NoteKind>(payment.status === "failed" ? "failed" : "general");
  const [body, setBody] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getPaymentHistory(payment.id)
      .then((history) => {
        if (cancelled) return;
        setNotes(history.notes);
        setAudit(history.audit);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the history.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [payment.id]);

  async function handleAdd() {
    if (!body.trim() || saving) return;
    setSaving(true);
    setError(null);
    try {
      const note = await addPaymentNote(payment.id, { kind, body: body.trim() });
      setNotes((current) => [note, ...current]);
      setBody("");
      // The new line of the trail is read again so the list shows it.
      const history = await getPaymentHistory(payment.id);
      setAudit(history.audit);
      onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the note. Please try again.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title="Payment history" onClose={onClose} maxWidthClassName="max-w-3xl">
      <div className="space-y-6">
        <p className="text-sm text-neutral-600">
          <span className="font-semibold text-neutral-900">{TYPE_LABEL[payment.type]}</span> of {money(paymentTotal(payment))}
          {payment.method ? `, ${methodLabel(payment.method)}` : ""}. Notes are for staff only, a customer never sees them.
        </p>

        <section>
          <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-neutral-500">Notes</h3>
          <div className="space-y-2 rounded-lg border border-neutral-200 p-3">
            <div className="flex flex-wrap items-center gap-2">
              <label htmlFor="note-kind" className="sr-only">
                Kind of note
              </label>
              <select
                id="note-kind"
                value={kind}
                disabled={saving}
                onChange={(e) => setKind(e.target.value as NoteKind)}
                className="rounded-md border border-neutral-300 bg-white px-2 py-1.5 text-sm"
              >
                {NOTE_KINDS.map((k) => (
                  <option key={k.value} value={k.value}>
                    {k.label}
                  </option>
                ))}
              </select>
            </div>
            <label htmlFor="note-body" className="sr-only">
              Note
            </label>
            <textarea
              id="note-body"
              value={body}
              maxLength={1000}
              rows={2}
              disabled={saving}
              onChange={(e) => setBody(e.target.value)}
              placeholder="What should the next person know about this payment?"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
            />
            <div className="flex items-center justify-between gap-3">
              <span className="text-xs text-neutral-400">{body.length} of 1000</span>
              <button
                onClick={handleAdd}
                disabled={saving || !body.trim()}
                className="font-heading rounded-md bg-red-600 px-3.5 py-1.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                {saving ? "Saving…" : "Add note"}
              </button>
            </div>
          </div>
          {error && (
            <p role="alert" className="mt-2 text-sm text-red-600">
              {error}
            </p>
          )}
          {loading ? (
            <p className="mt-3 text-sm text-neutral-500">Loading…</p>
          ) : notes.length === 0 ? (
            <p className="mt-3 text-sm text-neutral-400">No notes on this payment yet.</p>
          ) : (
            <ul className="mt-3 divide-y divide-neutral-100">
              {notes.map((n) => (
                <li key={n.id} className="py-2.5">
                  <p className="text-xs text-neutral-500">
                    <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-neutral-600">{KIND_LABEL[n.kind]}</span>{" "}
                    {n.author?.name ?? "Staff"} · {new Date(n.created_at).toLocaleString()}
                  </p>
                  <p className="mt-1 whitespace-pre-wrap text-sm text-neutral-800">{n.body}</p>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section>
          <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-neutral-500">Audit trail</h3>
          {loading ? null : audit.length === 0 ? (
            <p className="text-sm text-neutral-400">Nothing has been recorded for this payment yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-left text-xs">
                <thead>
                  <tr className="border-b border-neutral-200 text-[10px] font-bold uppercase tracking-wide text-neutral-400">
                    <th className="pb-1.5 font-medium">When</th>
                    <th className="pb-1.5 font-medium">Who</th>
                    <th className="pb-1.5 font-medium">What</th>
                    <th className="pb-1.5 font-medium">Method</th>
                    <th className="pb-1.5 font-medium">From</th>
                  </tr>
                </thead>
                <tbody>
                  {audit.map((a) => (
                    <tr key={a.id} className="border-b border-neutral-100 align-top last:border-0">
                      <td className="py-2 pr-3 whitespace-nowrap text-neutral-500">{new Date(a.created_at).toLocaleString()}</td>
                      <td className="py-2 pr-3 text-neutral-800">{actorLabel(a)}</td>
                      <td className="py-2 pr-3 text-neutral-700">{a.summary}</td>
                      <td className="py-2 pr-3 text-neutral-600">{a.method ? methodLabel(a.method as Payment["method"]) : "—"}</td>
                      <td className="py-2">
                        <FromCell entry={a} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </Modal>
  );
}
