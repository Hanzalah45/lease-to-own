"use client";

import { useCallback, useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import {
  applyFundsToRepairs,
  getAdditionalFunds,
  refundAdditionalFunds,
  type AdditionalFundsMovement,
  type AdditionalFundsView,
} from "@/lib/payments";

export const FUNDS_KIND_LABEL: Record<AdditionalFundsMovement["kind"], string> = {
  added: "Extra payment received",
  reversed: "Extra payment no longer counted",
  refunded: "Refunded to the customer",
  repairs: "Applied to repairs",
  adjusted: "Adjusted",
};

/** An amount with its sign, so money in and money out read at a glance. */
function signed(value: string): { text: string; className: string } {
  const n = Number(value);

  return n < 0 ? { text: `−${money(Math.abs(n))}`, className: "text-red-600" } : { text: `+${money(n)}`, className: "text-green-700" };
}

/**
 * The Additional Funds of a lease (contract Section 19): what the customer paid on top of the monthly payments toward the early
 * purchase price, with every movement of it, and what staff can do once the equipment has come back: apply funds to the cost of repairs
 * first, then refund what is left (client, Joel, 2026-10-19 checklist, section 3).
 */
export function AdditionalFundsCard({ leaseId, onChanged }: { leaseId: number | string; onChanged?: () => void }) {
  const [view, setView] = useState<AdditionalFundsView | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [action, setAction] = useState<"repairs" | "refund" | null>(null);

  const load = useCallback(() => {
    return getAdditionalFunds(leaseId)
      .then(setView)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load the Additional Funds."));
  }, [leaseId]);

  useEffect(() => {
    let cancelled = false;
    getAdditionalFunds(leaseId)
      .then((data) => {
        if (!cancelled) setView(data);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the Additional Funds.");
      });

    return () => {
      cancelled = true;
    };
  }, [leaseId]);

  if (error) return <p className="text-sm text-red-600">{error}</p>;
  // Nothing to show for a lease that never held any Additional Funds.
  if (!view || view.entries.length === 0) return null;

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Additional Funds" />
      <p className="mt-1 text-xs text-neutral-500">
        What the customer paid on top of the monthly payments toward the early purchase price (contract Section 19). It lowers the early
        purchase price. When the equipment comes back, funds go first to the cost of repairs and only what is left is refunded.
      </p>

      <dl className="mt-3 flex flex-wrap gap-x-8 gap-y-2 text-sm">
        <div>
          <dt className="text-xs font-bold uppercase tracking-wide text-neutral-400">Balance</dt>
          <dd className="font-heading text-2xl font-black text-neutral-900">{money(view.balance)}</dd>
        </div>
        <div>
          <dt className="text-xs font-bold uppercase tracking-wide text-neutral-400">Early purchase price today</dt>
          <dd className="font-heading text-2xl font-black text-neutral-900">{money(view.epo_today)}</dd>
        </div>
      </dl>
      {view.pending_refunds > 0 && (
        <p className="mt-2 text-xs font-medium text-amber-700">
          {view.pending_refunds} refund{view.pending_refunds === 1 ? " is" : "s are"} on its way through the bank. The balance drops when the bank confirms it.
        </p>
      )}

      {notice && (
        <p role="status" className="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">
          {notice}
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-start gap-3">
        <div>
          <button
            onClick={() => {
              setNotice(null);
              setAction("repairs");
            }}
            disabled={view.repairs_blocker !== null}
            className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Apply to repairs
          </button>
        </div>
        <div>
          <button
            onClick={() => {
              setNotice(null);
              setAction("refund");
            }}
            disabled={view.refund_blocker !== null}
            className="font-heading rounded-md border border-red-600 px-3.5 py-2 text-sm font-bold text-red-600 hover:bg-red-50 disabled:border-neutral-300 disabled:text-neutral-400 disabled:hover:bg-transparent"
          >
            Refund what is left
          </button>
        </div>
      </div>
      {(view.repairs_blocker || view.refund_blocker) && (
        <p className="mt-2 text-xs text-neutral-500">{view.repairs_blocker ?? view.refund_blocker}</p>
      )}

      <div className="mt-4 overflow-x-auto">
        <table className="w-full min-w-[480px] text-left text-sm">
          <thead>
            <tr className="border-b border-neutral-200 text-[10px] font-bold uppercase tracking-wide text-neutral-400">
              <th className="pb-1.5 font-medium">When</th>
              <th className="pb-1.5 font-medium">What happened</th>
              <th className="pb-1.5 text-right font-medium">Amount</th>
              <th className="pb-1.5 text-right font-medium">Balance after</th>
            </tr>
          </thead>
          <tbody>
            {view.entries.map((e) => {
              const amount = signed(e.amount);

              return (
                <tr key={e.id} className="border-b border-neutral-100 align-top last:border-0">
                  <td className="py-2 pr-3 whitespace-nowrap text-xs text-neutral-500">{new Date(e.created_at).toLocaleDateString()}</td>
                  <td className="py-2 pr-3 text-neutral-800">
                    {FUNDS_KIND_LABEL[e.kind]}
                    {e.description && e.kind !== "added" && (
                      <span className="block text-xs text-neutral-500">{e.description.replace(/^Applied to repairs: /, "")}</span>
                    )}
                    {e.created_by && <span className="block text-xs text-neutral-400">by {e.created_by.name}</span>}
                  </td>
                  <td className={`py-2 pr-3 text-right font-semibold tabular-nums ${amount.className}`}>{amount.text}</td>
                  <td className="py-2 text-right tabular-nums text-neutral-700">{money(Number(e.balance_after))}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {action && (
        <FundsActionModal
          mode={action}
          leaseId={leaseId}
          available={view.available}
          onClose={() => setAction(null)}
          onDone={(message) => {
            setAction(null);
            setNotice(message);
            void load();
            onChanged?.();
          }}
        />
      )}
    </div>
  );
}

/** One window for both actions: how much, and for repairs what the repair was. */
function FundsActionModal({
  mode,
  leaseId,
  available,
  onClose,
  onDone,
}: {
  mode: "repairs" | "refund";
  leaseId: number | string;
  available: number;
  onClose: () => void;
  onDone: (notice: string) => void;
}) {
  const [everything, setEverything] = useState(mode === "refund");
  const [amountText, setAmountText] = useState("");
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const typed = Number(amountText);
  const amount = everything ? available : typed;
  const amountOk = everything || (/^\d+(\.\d{1,2})?$/.test(amountText.trim()) && typed > 0 && Math.round(typed * 100) <= Math.round(available * 100));
  const textOk = mode === "refund" || text.trim().length >= 3;
  const ready = amountOk && textOk && amount > 0;

  const close = () => {
    if (!busy) onClose();
  };

  async function submit() {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    try {
      if (mode === "repairs") {
        await applyFundsToRepairs(leaseId, { amount, description: text.trim() });
        onDone(`${money(amount)} of Additional Funds was applied to repairs.`);
      } else {
        await refundAdditionalFunds(leaseId, { amount: everything ? undefined : amount, note: text.trim() });
        onDone(`${money(amount)} of Additional Funds was refunded. Each part goes back the way it was paid.`);
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That did not work. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title={mode === "repairs" ? "Apply funds to repairs" : "Refund Additional Funds"} onClose={close} maxWidthClassName="max-w-lg">
      <div className="space-y-5">
        <p className="text-sm text-neutral-600">
          {mode === "repairs"
            ? `Up to ${money(available)} can be applied to what the repairs cost. The customer sees what it was for. This cannot be undone.`
            : `Up to ${money(available)} can be refunded, newest extra payment first. The money goes back through Stripe the way it was paid, and the card fee that came with it goes too.`}
        </p>

        {mode === "refund" && (
          <div className="space-y-2">
            <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${everything ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
              <input type="radio" name="funds-amount" className="mt-1" checked={everything} disabled={busy} onChange={() => setEverything(true)} />
              <span className="text-sm font-semibold text-neutral-900">Refund everything that is left ({money(available)})</span>
            </label>
            <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${!everything ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
              <input type="radio" name="funds-amount" className="mt-1" checked={!everything} disabled={busy} onChange={() => setEverything(false)} />
              <span className="text-sm font-semibold text-neutral-900">Refund part of it</span>
            </label>
          </div>
        )}

        {(mode === "repairs" || !everything) && (
          <div>
            <label htmlFor="funds-amount" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
              Amount
            </label>
            <div className="flex items-center gap-1.5">
              <span className="text-sm text-neutral-500">$</span>
              <input
                id="funds-amount"
                type="text"
                inputMode="decimal"
                value={amountText}
                maxLength={10}
                disabled={busy}
                onChange={(e) => setAmountText(e.target.value)}
                placeholder="0.00"
                className="w-32 rounded-md border border-neutral-300 px-3 py-1.5 text-sm tabular-nums"
              />
            </div>
            {amountText !== "" && !amountOk && <p className="mt-1 text-xs text-red-600">Enter dollars and cents, no more than {money(available)}.</p>}
          </div>
        )}

        <div>
          <label htmlFor="funds-text" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            {mode === "repairs" ? "What was repaired (required)" : "Note (optional)"}
          </label>
          <textarea
            id="funds-text"
            value={text}
            maxLength={mode === "repairs" ? 300 : 500}
            rows={2}
            disabled={busy}
            onChange={(e) => setText(e.target.value)}
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
            onClick={close}
            disabled={busy}
            className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            disabled={busy || !ready}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Working…" : mode === "repairs" ? `Apply ${ready ? money(amount) : ""} to repairs`.replace("  ", " ") : `Refund ${ready ? money(amount) : ""}`.trim()}
          </button>
        </div>
      </div>
    </Modal>
  );
}
