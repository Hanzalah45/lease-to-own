"use client";

import { useCallback, useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { DeliveryNote } from "@/components/emails/EmailStatusBadge";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { LinkAddress, PaymentLinkModal } from "@/components/applications/detail/PaymentLinkModal";
import { ApiError } from "@/lib/api";
import {
  cancelPaymentLink,
  LINK_STATUS_CLASS,
  LINK_STATUS_LABEL,
  listPaymentLinks,
  resendPaymentLink,
  type PaymentLink,
  type PaymentLinkMade,
} from "@/lib/payment-links";

const when = (value: string | null) => (value ? new Date(value).toLocaleString() : "");

/** Sending a link again: to the same address or another, then the new address of the link. */
function ResendModal({ link, onClose, onDone }: { link: PaymentLink; onClose: () => void; onDone: (made: PaymentLinkMade) => void }) {
  const [email, setEmail] = useState(link.sent_to ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());

  async function submit() {
    if (!valid || busy) return;
    setBusy(true);
    setError(null);
    try {
      onDone(await resendPaymentLink(link.id, email.trim()));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That did not work. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title="Send the link again" onClose={() => !busy && onClose()} maxWidthClassName="max-w-md">
      <div className="space-y-4">
        <p className="text-sm text-neutral-600">
          This makes a new address for the link and emails it. The address sent before stops working. It works for another 14 days.
        </p>
        <div>
          <label htmlFor="resend-email" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Send it to
          </label>
          <input
            id="resend-email"
            type="email"
            value={email}
            maxLength={254}
            disabled={busy}
            onChange={(e) => setEmail(e.target.value)}
            className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
          />
        </div>
        {error && (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <button
            onClick={onClose}
            disabled={busy}
            className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            disabled={busy || !valid}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Sending…" : "Send the link"}
          </button>
        </div>
      </div>
    </Modal>
  );
}

function LinkRow({ link, busyId, onResend, onCancel }: { link: PaymentLink; busyId: number | null; onResend: () => void; onCancel: () => void }) {
  const status = link.effective_status;
  // A link can be sent again until it is paid or cancelled, or a payment from it is on its way.
  const mayResend = !["paid", "cancelled", "processing"].includes(status);
  const mayCancel = !["paid", "cancelled", "processing", "expired"].includes(status);

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-neutral-900">
            {link.description} <span className="font-normal text-neutral-500">· {money(Number(link.amount))}</span>
          </p>
          <p className="text-xs text-neutral-500">
            Made {when(link.created_at)}
            {link.created_by ? ` by ${link.created_by.name}` : ""}
            {link.opened_at ? ` · opened ${when(link.opened_at)}` : ""}
            {link.paid_at ? ` · paid ${when(link.paid_at)}` : ""}
            {link.cancelled_at ? ` · cancelled ${when(link.cancelled_at)}` : ""}
            {!link.paid_at && !link.cancelled_at ? ` · works until ${new Date(link.expires_at).toLocaleDateString()}` : ""}
          </p>
          {link.failure_message && status === "failed" && <p className="mt-0.5 text-xs font-medium text-red-600">{link.failure_message}</p>}
        </div>
        <div className="flex items-center gap-3">
          <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${LINK_STATUS_CLASS[status]}`}>{LINK_STATUS_LABEL[status]}</span>
          {mayResend && (
            <button onClick={onResend} disabled={busyId === link.id} className="text-xs font-semibold text-green-700 hover:underline disabled:opacity-50">
              {link.send_count > 0 ? "Send again" : "Send"}
            </button>
          )}
          {mayCancel && (
            <button onClick={onCancel} disabled={busyId === link.id} className="text-xs font-semibold text-red-600 hover:underline disabled:opacity-50">
              {busyId === link.id ? "Cancelling…" : "Cancel"}
            </button>
          )}
        </div>
      </div>
      {(link.sends?.length ?? 0) > 0 && (
        <ul className="mt-1 space-y-0.5 border-l-2 border-neutral-200 pl-2">
          {link.sends!.map((s) => (
            <li key={s.id} className={`text-xs ${s.outcome === "failed" ? "text-red-600" : "text-neutral-600"}`}>
              {s.outcome === "failed" ? "Could not be emailed to" : "Emailed to"} {s.email} on {when(s.sent_at)}
              {s.sent_by ? ` · ${s.sent_by.name}` : ""}
              {s.outcome === "failed" && s.error ? `: ${s.error}` : ""}
              <DeliveryNote log={s.email_log} />
            </li>
          ))}
        </ul>
      )}
    </li>
  );
}

/**
 * Every payment link a customer has had: what it is for, whether it was sent, opened, paid or cancelled, and each time it was emailed
 * with the address and the time (client, Joel, 2026-10-19 checklist, section 6). Staff send a link again or cancel it from here. Links
 * for a payment that is due are made from that payment in the Payments card; a link for an extra payment is made here.
 *
 * `refreshKey` asks the list to load again when something outside it changed (a payment was taken, or a link was made elsewhere).
 */
export function PaymentLinksCard({
  leaseId,
  customerEmail,
  refreshKey,
}: {
  leaseId: number | string;
  customerEmail?: string | null;
  refreshKey?: unknown;
}) {
  const [links, setLinks] = useState<PaymentLink[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [resending, setResending] = useState<PaymentLink | null>(null);
  const [making, setMaking] = useState(false);
  const [shown, setShown] = useState<PaymentLinkMade | null>(null);

  const load = useCallback(() => {
    return listPaymentLinks(leaseId)
      .then((rows) => {
        setLinks(rows);
        setError(null);
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load the payment links."));
  }, [leaseId]);

  useEffect(() => {
    let cancelled = false;
    listPaymentLinks(leaseId)
      .then((rows) => {
        if (!cancelled) setLinks(rows);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the payment links.");
      });

    return () => {
      cancelled = true;
    };
  }, [leaseId, refreshKey]);

  async function cancel(link: PaymentLink) {
    setBusyId(link.id);
    setNotice(null);
    setError(null);
    try {
      await cancelPaymentLink(link.id);
      setNotice("The link was cancelled. It no longer opens.");
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not cancel the link. Please try again.");
    } finally {
      setBusyId(null);
    }
  }

  if (!links && !error) return null;

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <SectionHeading title="Payment links" />
        <button
          onClick={() => {
            setNotice(null);
            setMaking(true);
          }}
          className="font-heading rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-bold text-neutral-700 hover:bg-neutral-50"
        >
          Link for an extra payment
        </button>
      </div>
      <p className="mt-1 text-xs text-neutral-500">
        A link lets the customer pay without logging in, by bank account or card. To send one for a payment that is due, use &ldquo;Send a link&rdquo;
        on that payment above.
      </p>
      {notice && (
        <p role="status" className="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">
          {notice}
        </p>
      )}
      {error && (
        <p role="alert" className="mt-3 text-sm text-red-600">
          {error}
        </p>
      )}
      {links && links.length === 0 ? (
        <p className="mt-3 text-sm text-neutral-500">No links yet.</p>
      ) : (
        <ul className="mt-2 divide-y divide-neutral-100">
          {links?.map((link) => (
            <LinkRow key={link.id} link={link} busyId={busyId} onResend={() => setResending(link)} onCancel={() => void cancel(link)} />
          ))}
        </ul>
      )}

      {making && (
        <PaymentLinkModal
          target={{ kind: "extra", leaseId }}
          customerEmail={customerEmail}
          onClose={() => setMaking(false)}
          onDone={(message) => {
            setMaking(false);
            setNotice(message);
            void load();
          }}
        />
      )}

      {resending && (
        <ResendModal
          link={resending}
          onClose={() => setResending(null)}
          onDone={(made) => {
            setResending(null);
            setShown(made);
            void load();
          }}
        />
      )}

      {shown && (
        <Modal title="Payment link" onClose={() => setShown(null)} maxWidthClassName="max-w-lg">
          <div className="space-y-4">
            {shown.mailed === false ? (
              <p role="alert" className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                The email could not be sent. Copy the link below and send it yourself.
              </p>
            ) : (
              <p className="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">Emailed to {shown.data.sent_to}.</p>
            )}
            <LinkAddress url={shown.url} />
            <div className="flex justify-end">
              <button onClick={() => setShown(null)} className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700">
                Done
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
