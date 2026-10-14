"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { EmailStatusBadge } from "@/components/emails/EmailStatusBadge";
import { ApiError } from "@/lib/api";
import { listCustomerEmails, type EmailLogEntry } from "@/lib/email-logs";

/** How many emails to show before folding the rest behind a button. */
const SHOWN = 8;

/**
 * Every email the system sent to one customer (client, Joel, 2026-10-13), so
 * staff can prove what went out and see at once when an address was mistyped:
 * the address is shown exactly as it was used.
 */
export function EmailsCard({ customerId }: { customerId: number | string }) {
  // The answer is kept with the customer it belongs to, so moving to another customer never shows the last one's emails or error.
  const [loaded, setLoaded] = useState<{ customerId: number | string; emails: EmailLogEntry[] | null; error: string | null } | null>(null);
  const [showAll, setShowAll] = useState(false);

  useEffect(() => {
    let cancelled = false;
    listCustomerEmails(customerId)
      .then((rows) => {
        if (!cancelled) setLoaded({ customerId, emails: rows, error: null });
      })
      .catch((err) => {
        if (!cancelled)
          setLoaded({ customerId, emails: null, error: err instanceof ApiError ? err.message : "Could not load the email history." });
      });
    return () => {
      cancelled = true;
    };
  }, [customerId]);

  const current = loaded && loaded.customerId === customerId ? loaded : null;
  const emails = current?.emails ?? null;
  const error = current?.error ?? null;
  const rows = emails ? (showAll ? emails : emails.slice(0, SHOWN)) : [];

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Emails sent" subtitle="Sent means our email service accepted the message." />
      {error ? (
        <p className="mt-3 text-sm text-red-600">{error}</p>
      ) : !emails ? (
        <p className="mt-3 text-sm text-neutral-400">Loading…</p>
      ) : emails.length === 0 ? (
        <p className="mt-3 text-sm text-neutral-500">No emails have been recorded for this customer yet.</p>
      ) : (
        <ul className="mt-2 divide-y divide-neutral-100">
          {rows.map((e) => (
            <li key={e.id} className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3">
              <div className="min-w-0">
                <p className="text-sm font-semibold text-neutral-900">{e.kind_label}</p>
                <p className="break-all text-xs text-neutral-500">To {e.to_address}</p>
                {e.subject && <p className="text-xs text-neutral-400">{e.subject}</p>}
                {e.error && <p className="mt-0.5 text-xs font-medium text-red-600">{e.error}</p>}
              </div>
              <div className="flex items-center gap-3">
                <span className="text-xs text-neutral-400">{new Date(e.created_at).toLocaleString()}</span>
                <EmailStatusBadge status={e.status} />
              </div>
            </li>
          ))}
        </ul>
      )}
      <div className="mt-2 flex items-center justify-between text-xs">
        {emails && emails.length > SHOWN ? (
          <button onClick={() => setShowAll((v) => !v)} className="font-semibold text-red-600 hover:underline">
            {showAll ? "Show fewer" : `Show all ${emails.length}`}
          </button>
        ) : (
          <span />
        )}
        <Link href="/admin/email-log" className="font-semibold text-neutral-500 hover:underline">
          Open the full email log →
        </Link>
      </div>
    </div>
  );
}
