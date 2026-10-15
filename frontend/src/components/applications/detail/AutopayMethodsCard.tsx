"use client";

import { useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { ApiError } from "@/lib/api";
import { getAutopayMethodsAsStaff, startBankLinkAsStaff, type StaffAutopayMethods, type StaffMethodInfo } from "@/lib/payments";

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
}: {
  leaseId: number;
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
          <div>
            <p className="font-semibold text-neutral-900">
              Bank account{methods.primary === "ach" && methods.bank ? <span className="ml-2 text-xs font-medium text-neutral-500">charged first</span> : null}
            </p>
            <p className="text-neutral-600">{methods.bank ? bankLine(methods.bank) : "Not added."}</p>
          </div>
          <div>
            <p className="font-semibold text-neutral-900">
              Card{methods.primary === "card" && methods.card ? <span className="ml-2 text-xs font-medium text-neutral-500">charged first</span> : null}
              {methods.card && !methods.bank ? <span className="ml-2 text-xs font-medium text-neutral-500">charged (no bank account)</span> : null}
            </p>
            <p className="text-neutral-600">{methods.card ? cardLine(methods.card) : "Not added."}</p>
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
        </div>
      )}
    </div>
  );
}
