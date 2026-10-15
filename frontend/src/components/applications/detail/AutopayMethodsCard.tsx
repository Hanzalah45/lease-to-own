"use client";

import { useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { ApiError } from "@/lib/api";
import { getAutopayMethodsAsStaff, type StaffAutopayMethods, type StaffMethodInfo } from "@/lib/payments";

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
 * The bank account and card AutoPay would charge for this lease, as Stripe
 * describes them (brand, last four digits and expiry only), and a way for staff
 * to add or change the card for a customer who cannot (client, Joel, 2026-10-15:
 * "I should be able to see the auto pay cards that the customer uses and
 * possibly add them when I need to. If a customer changes his card. Not
 * everybody has smart phones"). A bank account needs the customer's own bank
 * login, so only the customer can change that.
 */
export function AutopayMethodsCard({
  leaseId,
  refreshKey,
  onChangeCard,
}: {
  leaseId: number;
  /** Bump to read the methods again, for example after a card was saved. */
  refreshKey: number;
  onChangeCard: () => void;
}) {
  const [loaded, setLoaded] = useState<{ key: string; methods: StaffAutopayMethods | null; error: string | null } | null>(null);
  const key = `${leaseId}:${refreshKey}`;

  useEffect(() => {
    let cancelled = false;
    getAutopayMethodsAsStaff(leaseId)
      .then((methods) => {
        if (!cancelled) setLoaded({ key, methods, error: null });
      })
      .catch((err) => {
        if (!cancelled) setLoaded({ key, methods: null, error: err instanceof ApiError ? err.message : "Could not load the AutoPay methods." });
      });
    return () => {
      cancelled = true;
    };
  }, [leaseId, key]);

  const current = loaded && loaded.key === key ? loaded : null;
  const methods = current?.methods ?? null;

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
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-semibold text-neutral-900">
                Bank account{methods.primary === "ach" && methods.bank ? <span className="ml-2 text-xs font-medium text-neutral-500">charged first</span> : null}
              </p>
              <p className="text-neutral-600">{methods.bank ? bankLine(methods.bank) : "Not added. Only the customer can add a bank account, with their own bank login."}</p>
            </div>
          </div>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-semibold text-neutral-900">
                Card{methods.primary === "card" && methods.card ? <span className="ml-2 text-xs font-medium text-neutral-500">charged first</span> : null}
                {methods.card && !methods.bank ? <span className="ml-2 text-xs font-medium text-neutral-500">charged (no bank account)</span> : null}
              </p>
              <p className="text-neutral-600">{methods.card ? cardLine(methods.card) : "Not added."}</p>
            </div>
            <button
              onClick={onChangeCard}
              className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
            >
              {methods.card ? "Change card" : "Add a card for the customer"}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
