"use client";

import { useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import {
  getAutopayMethodsAsStaff,
  recordAutopayRevocation,
  setMethodLock,
  startBankLinkAsStaff,
  type MethodLock,
  type StaffAutopayMethods,
  type StaffMethodInfo,
} from "@/lib/payments";

function cap(text: string | null | undefined): string {
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : "";
}

function cardLine(card: StaffMethodInfo): string {
  if (card.unavailable) return "Could not be read from Stripe right now.";
  const expiry = card.exp_month && card.exp_year ? `, expires ${String(card.exp_month).padStart(2, "0")}/${card.exp_year}` : "";

  return `${cap(card.brand) || "Card"} ending ${card.last4 ?? "????"}${expiry}`;
}

function bankLine(bank: StaffMethodInfo): string {
  if (bank.unavailable) return "Could not be read from Stripe right now.";

  return `${bank.bank_name ?? "Bank account"}${bank.account_type ? ` (${bank.account_type})` : ""} ending ${bank.last4 ?? "????"}`;
}

/**
 * Lock or unlock one saved method (client, Joel, 2026-10-19 checklist, section 4). A locked method stays on file but is never charged by
 * AutoPay, "Pay now" or staff, and the customer is only told to contact us. A reason is required to lock.
 */
function LockControl({ leaseId, type, lock, onChanged }: { leaseId: number; type: "bank" | "card"; lock: MethodLock | null; onChanged: () => void }) {
  const [asking, setAsking] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function change(locked: boolean) {
    setBusy(true);
    setError(null);
    try {
      await setMethodLock(leaseId, { type, locked, reason: locked ? reason.trim() : undefined });
      setAsking(false);
      setReason("");
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not change the lock. Please try again.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mt-1">
      {lock ? (
        <p className="text-xs">
          <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-700">Locked</span>{" "}
          <span className="text-neutral-600">
            {lock.reason ?? "No reason kept."} Since {new Date(lock.locked_at).toLocaleDateString()}. It stays on file but is never charged.
          </span>{" "}
          <button onClick={() => change(false)} disabled={busy} className="font-semibold text-neutral-700 underline disabled:opacity-50">
            {busy ? "Unlocking…" : "Unlock"}
          </button>
        </p>
      ) : asking ? (
        <div className="space-y-1.5">
          <label htmlFor={`lock-reason-${type}`} className="sr-only">
            Why is it being locked
          </label>
          <input
            id={`lock-reason-${type}`}
            type="text"
            value={reason}
            maxLength={200}
            disabled={busy}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Why? For example: customer reported the card stolen"
            className="w-full rounded-md border border-neutral-300 px-3 py-1.5 text-sm"
          />
          <div className="flex gap-3 text-xs">
            <button onClick={() => change(true)} disabled={busy || reason.trim().length < 3} className="font-semibold text-red-600 underline disabled:opacity-50">
              {busy ? "Locking…" : "Lock it"}
            </button>
            <button onClick={() => setAsking(false)} disabled={busy} className="font-semibold text-neutral-500 underline">
              Cancel
            </button>
          </div>
        </div>
      ) : (
        <button onClick={() => setAsking(true)} className="text-xs font-semibold text-neutral-500 underline hover:text-neutral-800">
          Lock this {type === "bank" ? "bank account" : "card"}
        </button>
      )}
      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}

/**
 * Staff enter a customer's written request to turn AutoPay off (a letter, an email). The authorization asks for three business days'
 * notice, so the same days run from today and the system then switches AutoPay off by itself.
 */
function RecordRevocation({ leaseId, onDone }: { leaseId: number; onDone: () => void }) {
  const [open, setOpen] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await recordAutopayRevocation(leaseId);
      setOpen(false);
      setConfirmed(false);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not record the request. Please try again.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="border-t border-neutral-100 pt-3">
      <p className="text-neutral-600">The customer asked to stop automatic payments?</p>
      <button
        onClick={() => setOpen(true)}
        className="font-heading mt-2 rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
      >
        Record a request to turn AutoPay off
      </button>
      <p className="mt-2 text-xs text-neutral-500">
        Their notice is three business days. AutoPay keeps charging until then and is switched off by the system on that day. To stop it
        at once, use Pause AutoPay instead.
      </p>
      {open && (
        <Modal title="Turn AutoPay off for the customer" onClose={() => !busy && setOpen(false)} maxWidthClassName="max-w-md">
          <div className="space-y-4">
            <p className="text-sm text-neutral-700">
              This starts the three business day notice from today. The customer is emailed a copy, and a note is added to the application.
            </p>
            <label className="flex cursor-pointer items-start gap-3 text-sm text-neutral-800">
              <input type="checkbox" className="mt-1" checked={confirmed} disabled={busy} onChange={(e) => setConfirmed(e.target.checked)} />
              <span>The customer asked for this in writing.</span>
            </label>
            {error && (
              <p role="alert" className="text-sm text-red-600">
                {error}
              </p>
            )}
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setOpen(false)}
                disabled={busy}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={submit}
                disabled={busy || !confirmed}
                className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                {busy ? "Saving…" : "Record the request"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

/**
 * The bank account and card AutoPay would charge for this lease, as Stripe
 * describes them (brand, last four digits and expiry only), and a way for staff
 * to add or change them for a customer who cannot (client, Joel, 2026-10-15:
 * "I should be able to see the auto pay cards that the customer uses and
 * possibly add them when I need to. If a customer changes his card. Not
 * everybody has smart phones"). Staff type the card into Stripe's form
 * themselves; a bank account needs the customer's own bank login, so staff open
 * a page on this computer and the customer links it themselves (2026-10-16).
 */
export function AutopayMethodsCard({
  leaseId,
  refreshKey,
  onChangeCard,
  agreement,
}: {
  leaseId: number;
  /** When the customer turned AutoPay on with the tick box, if they did. */
  agreement?: { agreed_at: string; via: "portal" | "signed_link" } | null;
  /** Bump to read the methods again, for example after a card was saved. */
  refreshKey: number;
  onChangeCard: () => void;
}) {
  const [loaded, setLoaded] = useState<{ leaseId: number; methods: StaffAutopayMethods | null; error: string | null } | null>(null);
  // Counts the times staff came back to this tab after opening the bank page, so the account shows without a reload.
  const [returnCount, setReturnCount] = useState(0);
  const [openingBank, setOpeningBank] = useState(false);
  const [bankError, setBankError] = useState<string | null>(null);
  const [bankPage, setBankPage] = useState<{ url: string; minutes: number; blocked: boolean } | null>(null);

  useEffect(() => {
    let cancelled = false;
    getAutopayMethodsAsStaff(leaseId)
      .then((methods) => {
        if (!cancelled) setLoaded({ leaseId, methods, error: null });
      })
      .catch((err) => {
        if (!cancelled) setLoaded({ leaseId, methods: null, error: err instanceof ApiError ? err.message : "Could not load the AutoPay methods." });
      });
    return () => {
      cancelled = true;
    };
  }, [leaseId, refreshKey, returnCount]);

  useEffect(() => {
    if (!bankPage) return;
    const onFocus = () => setReturnCount((n) => n + 1);
    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, [bankPage]);

  // The tab is opened first, inside the click, so the browser does not block it as a pop-up while the link is made.
  async function openBankPage() {
    setBankError(null);
    setOpeningBank(true);
    const tab = window.open("", "_blank");
    try {
      const { url, expires_in_minutes } = await startBankLinkAsStaff(leaseId);
      if (tab) tab.location.href = url;
      setBankPage({ url, minutes: expires_in_minutes, blocked: !tab });
    } catch (err) {
      tab?.close();
      setBankError(err instanceof ApiError ? err.message : "Could not open the bank account page.");
    } finally {
      setOpeningBank(false);
    }
  }

  const current = loaded && loaded.leaseId === leaseId ? loaded : null;
  const methods = current?.methods ?? null;
  const actionClass =
    "font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50";

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading
        title="AutoPay payment methods"
        subtitle={`${
          methods && !methods.autopay_enabled
            ? "AutoPay is off for this lease: these are what the customer pays with herself."
            : "What the automatic monthly charge uses."
        } Staff see the brand, last four digits and expiry only.`}
      />
      {current?.error ? (
        <p className="mt-3 text-sm text-red-600">{current.error}</p>
      ) : !methods ? (
        <p className="mt-3 text-sm text-neutral-400">Loading…</p>
      ) : (
        <div className="mt-3 space-y-3 text-sm">
          {methods.chargeback_frozen && (
            <p className="rounded-md bg-red-50 px-3 py-2 text-red-800">
              A chargeback is open on this lease, so AutoPay is paused and the method the disputed payment was made with is locked until the
              bank decides. Winning it lifts the freeze by itself; a lost chargeback stays frozen for you to decide.
            </p>
          )}
          {methods.autopay_revocation && (
            <p className="rounded-md bg-amber-50 px-3 py-2 text-amber-800">
              The customer asked to turn AutoPay off
              {methods.autopay_revocation.via === "staff" ? " (recorded by staff from a written request)" : ""}. AutoPay keeps charging until{" "}
              {formatDateOnly(methods.autopay_revocation.effective_on)} and is then switched off by the system.
            </p>
          )}
          {agreement && (
            <p className="rounded-md bg-green-50 px-3 py-2 text-green-800">
              The customer turned AutoPay on with the tick box on {new Date(agreement.agreed_at).toLocaleString()}
              {agreement.via === "signed_link" ? " (from the emailed link)" : ""}. The words they agreed to are kept with the lease.
            </p>
          )}
          <div>
            <p className="font-semibold text-neutral-900">
              Bank account{methods.primary === "ach" && methods.bank ? <span className="ml-2 text-xs font-medium text-neutral-500">charged first</span> : null}
            </p>
            <p className="text-neutral-600">{methods.bank ? bankLine(methods.bank) : "Not added."}</p>
            {methods.bank_pending && (
              <p className="mt-0.5 text-xs font-medium text-amber-700">
                A new bank account is waiting for its two small deposits to be confirmed (since {new Date(methods.bank_pending.since).toLocaleDateString()}
                {methods.bank_pending.arrives_by ? `, deposits due by ${formatDateOnly(methods.bank_pending.arrives_by)}` : ""}). It cannot be charged until the customer confirms
                the amounts.
              </p>
            )}
            {methods.bank && <LockControl leaseId={leaseId} type="bank" lock={methods.bank_lock} onChanged={() => setReturnCount((n) => n + 1)} />}
          </div>
          <div>
            <p className="font-semibold text-neutral-900">
              Card{methods.primary === "card" && methods.card ? <span className="ml-2 text-xs font-medium text-neutral-500">charged first</span> : null}
              {methods.card && !methods.bank ? <span className="ml-2 text-xs font-medium text-neutral-500">charged (no bank account)</span> : null}
            </p>
            <p className="text-neutral-600">{methods.card ? cardLine(methods.card) : "Not added."}</p>
            {methods.card && <LockControl leaseId={leaseId} type="card" lock={methods.card_lock} onChanged={() => setReturnCount((n) => n + 1)} />}
          </div>

          <div className="border-t border-neutral-100 pt-3">
            <p className="text-neutral-600">
              Can&rsquo;t send the customer a payment link? Upload the customer&rsquo;s card or bank account information here:
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
              <button onClick={onChangeCard} className={actionClass}>
                {methods.card ? "Change card" : "Add a card for the customer"}
              </button>
              {!methods.bank && (
                <button onClick={openBankPage} disabled={openingBank} className={actionClass}>
                  {openingBank ? "Opening…" : "Link a bank account with the customer"}
                </button>
              )}
            </div>
            <p className="mt-2 text-xs text-neutral-500">
              A card is typed into Stripe&rsquo;s form on this screen. For a bank account, a page opens in a new tab and the customer
              logs in to their own bank on this computer, so you never see their login.
              {methods.bank && " To use a different bank account, clear this one on the customer’s page first, then link the new one."}
            </p>
            {bankError && <p className="mt-2 text-xs text-red-600">{bankError}</p>}
            {bankPage && (
              <p className="mt-2 text-xs text-green-700">
                {bankPage.blocked ? (
                  <>
                    Your browser blocked the new tab.{" "}
                    <a href={bankPage.url} target="_blank" rel="noreferrer" className="font-semibold underline">
                      Open the bank account page
                    </a>
                    .{" "}
                  </>
                ) : (
                  "The bank account page is open in a new tab. "
                )}
                It stops working in {bankPage.minutes} minutes. When the customer is done, come back to this tab and the account shows above.
              </p>
            )}
          </div>

          {methods.autopay_enabled && !methods.autopay_revocation && <RecordRevocation leaseId={leaseId} onDone={() => setReturnCount((n) => n + 1)} />}
        </div>
      )}
    </div>
  );
}
