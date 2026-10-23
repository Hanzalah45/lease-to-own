"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import { money } from "@/components/applications/wizard/types";
import {
  createMissingQuickbooksAccounts,
  getQuickbooksAccounts,
  getQuickbooksSync,
  runQuickbooksSync,
  saveQuickbooksSync,
  type QuickbooksAccount,
  type QuickbooksAccountSlot,
  type QuickbooksSync,
  type QuickbooksSyncRow,
} from "@/lib/quickbooks";
import { Modal } from "@/components/ui/Modal";
import { AlertCircleIcon, CheckCircleIcon, RefreshCwIcon } from "@/components/icons";

const TYPE_LABEL: Record<string, string> = {
  rental: "Monthly payment",
  deposit: "Security deposit",
  late_fee: "Late fee",
  pickup_balance: "Tracking fee and first month",
  extra_payment: "Extra payment",
};

function isoToday(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** The account types that suit a kind of money, listed first in its picker. */
function suitableTypes(slot: QuickbooksAccountSlot): string[] {
  if (slot.type === "Income") return ["Income", "Other Income"];
  if (slot.type === "Other Current Asset") return ["Other Current Asset", "Bank"];

  return [slot.type];
}

function draftOf(sync: QuickbooksSync) {
  return {
    accounts: Object.fromEntries(sync.accounts.map((slot) => [slot.key, slot.selected?.id ?? ""])) as Record<string, string>,
    from: sync.sync_from ?? "",
    enabled: sync.enabled,
  };
}

/**
 * Settings, "Send payments to QuickBooks" (signed plan, milestone 7): which QuickBooks account each kind of money is booked to,
 * the day to start from, the on/off switch, and what has been sent. Every payment that is collected becomes one journal entry
 * in the company's QuickBooks within about five minutes.
 */
export function QuickbooksSyncCard() {
  const [sync, setSync] = useState<QuickbooksSync | null>(null);
  const [accounts, setAccounts] = useState<QuickbooksAccount[] | null>(null);
  const [accountsError, setAccountsError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [draftAccounts, setDraftAccounts] = useState<Record<string, string>>({});
  const [draftFrom, setDraftFrom] = useState("");
  const [draftEnabled, setDraftEnabled] = useState(false);

  const [saving, setSaving] = useState(false);
  const [running, setRunning] = useState(false);
  const [creating, setCreating] = useState(false);
  const [confirmingCreate, setConfirmingCreate] = useState(false);
  const [notice, setNotice] = useState<{ tone: "success" | "error"; text: string } | null>(null);

  const adopt = useCallback((next: QuickbooksSync) => {
    const draft = draftOf(next);
    setSync(next);
    setDraftAccounts(draft.accounts);
    setDraftFrom(draft.from);
    setDraftEnabled(draft.enabled);
  }, []);

  const loadAccounts = useCallback(async () => {
    setAccountsError(null);
    try {
      setAccounts(await getQuickbooksAccounts());
    } catch (err) {
      setAccounts(null);
      setAccountsError(err instanceof ApiError ? err.message : "Could not load the QuickBooks accounts.");
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    getQuickbooksSync()
      .then((next) => {
        if (!cancelled) adopt(next);
      })
      .catch((err) => {
        if (!cancelled) setLoadError(err instanceof ApiError ? err.message : "Could not load the QuickBooks sync.");
      });
    getQuickbooksAccounts()
      .then((list) => {
        if (!cancelled) setAccounts(list);
      })
      .catch((err) => {
        if (!cancelled) setAccountsError(err instanceof ApiError ? err.message : "Could not load the QuickBooks accounts.");
      });

    return () => {
      cancelled = true;
    };
  }, [adopt]);

  const dirty = useMemo(() => {
    if (!sync) return false;
    const saved = draftOf(sync);

    return (
      draftFrom !== saved.from ||
      draftEnabled !== saved.enabled ||
      sync.accounts.some((slot) => (draftAccounts[slot.key] ?? "") !== saved.accounts[slot.key])
    );
  }, [sync, draftAccounts, draftFrom, draftEnabled]);

  if (loadError) {
    return <p className="mt-4 text-sm text-red-600">{loadError}</p>;
  }
  if (!sync) {
    return <p className="mt-4 text-sm text-neutral-400">Loading…</p>;
  }

  const allChosen = sync.accounts.every((slot) => (draftAccounts[slot.key] ?? "") !== "");
  const canSwitchOn = allChosen && draftFrom !== "";
  // A choice the connected company does not have counts as missing too (the connection was moved to another company, or the account
  // was deleted in QuickBooks), so "Create the missing accounts" offers to replace it instead of leaving a broken choice behind.
  const missingSlots = sync.accounts.filter((slot) => !slot.selected || (accounts !== null && !accounts.some((a) => a.id === slot.selected?.id)));

  async function handleSave() {
    setNotice(null);
    setSaving(true);
    try {
      const chosen = Object.fromEntries(Object.entries(draftAccounts).filter(([, id]) => id !== ""));
      const next = await saveQuickbooksSync({ sync_enabled: draftEnabled, sync_from: draftFrom || null, accounts: chosen });
      adopt(next);
      setNotice({ tone: "success", text: next.enabled ? "Saved. Payments are now sent to QuickBooks." : "Saved." });
    } catch (err) {
      setNotice({ tone: "error", text: err instanceof ApiError ? err.message : "Could not save the settings." });
    } finally {
      setSaving(false);
    }
  }

  async function handleCreateMissing() {
    setNotice(null);
    setCreating(true);
    try {
      const next = await createMissingQuickbooksAccounts();
      adopt(next);
      setConfirmingCreate(false);
      await loadAccounts();
      setNotice({ tone: "success", text: "The missing accounts were created in QuickBooks and chosen below." });
    } catch (err) {
      setConfirmingCreate(false);
      setNotice({ tone: "error", text: err instanceof ApiError ? err.message : "Could not create the accounts." });
      try {
        adopt(await getQuickbooksSync());
      } catch {
        /* the page keeps what it has */
      }
    } finally {
      setCreating(false);
    }
  }

  async function handleRun(retryFailed: boolean) {
    setNotice(null);
    setRunning(true);
    try {
      const { data, result } = await runQuickbooksSync(retryFailed);
      adopt(data);
      if (result.stopped) {
        setNotice({ tone: "error", text: result.stopped });
      } else {
        const parts = [`Sent ${result.sent}`];
        if (result.reversed) parts.push(`${result.reversed} reversed`);
        if (result.skipped) parts.push(`${result.skipped} counted inside another charge`);
        if (result.failed) parts.push(`${result.failed} refused by QuickBooks`);
        setNotice({ tone: result.failed ? "error" : "success", text: `${parts.join(", ")}.` });
      }
    } catch (err) {
      setNotice({ tone: "error", text: err instanceof ApiError ? err.message : "Could not send to QuickBooks." });
    } finally {
      setRunning(false);
    }
  }

  const status = sync.enabled && sync.ready ? "sending" : sync.missing.length > 0 || !sync.sync_from ? "setup" : "off";

  return (
    <div className="mt-6 max-w-4xl rounded-xl border border-neutral-200 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-heading text-base font-bold">Send payments to QuickBooks</h2>
          <p className="mt-0.5 max-w-2xl text-sm text-neutral-500">
            Every payment that is collected becomes one entry in QuickBooks within about five minutes, split across the accounts
            below: the money received on one side, and rent, sales tax, fees and deposits on the other.
          </p>
        </div>
        <span
          className={`font-heading inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${
            status === "sending"
              ? "bg-green-50 text-green-700"
              : status === "setup"
                ? "bg-amber-50 text-amber-700"
                : "bg-neutral-100 text-neutral-500"
          }`}
        >
          {status === "sending" ? <CheckCircleIcon className="h-3.5 w-3.5" /> : null}
          {status === "sending" ? "Sending" : status === "setup" ? "Needs setup" : "Switched off"}
        </span>
      </div>

      {notice && (
        <div
          role="status"
          className={`mt-4 flex items-start gap-2 rounded-md border px-3.5 py-2.5 text-sm font-medium ${
            notice.tone === "success" ? "border-green-200 bg-green-50 text-green-700" : "border-red-200 bg-red-50 text-red-700"
          }`}
        >
          {notice.tone === "success" ? <CheckCircleIcon className="mt-0.5 h-4 w-4 shrink-0" /> : <AlertCircleIcon className="mt-0.5 h-4 w-4 shrink-0" />}
          <span>{notice.text}</span>
        </div>
      )}

      <div className="mt-5">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-700">1. Accounts</h3>
          {missingSlots.length > 0 && (
            <button
              onClick={() => setConfirmingCreate(true)}
              disabled={creating}
              className="font-heading rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
            >
              Create the {missingSlots.length === sync.accounts.length ? "accounts" : "missing accounts"} for me
            </button>
          )}
        </div>
        <p className="mt-1 text-xs text-neutral-500">
          Choose the QuickBooks account for each kind of money. Your accountant can tell you which ones to use.
        </p>
        {accountsError && (
          <p className="mt-2 text-sm text-red-600">
            {accountsError}{" "}
            <button onClick={loadAccounts} className="font-semibold underline">
              Try again
            </button>
          </p>
        )}

        <div className="mt-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          {sync.accounts.map((slot) => {
            const value = draftAccounts[slot.key] ?? "";
            const suitable = suitableTypes(slot);
            const list = accounts ?? [];
            const known = list.some((a) => a.id === value);
            return (
              <div key={slot.key} className="grid gap-2 px-3.5 py-3 sm:grid-cols-[1fr_1.2fr] sm:items-center">
                <div>
                  <label htmlFor={`qb-${slot.key}`} className="text-sm font-semibold text-neutral-900">
                    {slot.label}
                  </label>
                  <p className="text-xs text-neutral-500">{slot.help}</p>
                </div>
                <select
                  id={`qb-${slot.key}`}
                  value={value}
                  onChange={(e) => setDraftAccounts((d) => ({ ...d, [slot.key]: e.target.value }))}
                  disabled={accounts === null}
                  className="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm disabled:bg-neutral-50"
                >
                  <option value="">Choose an account</option>
                  {value !== "" && !known && <option value={value}>{slot.selected?.name ?? value} (not found in QuickBooks)</option>}
                  <optgroup label="Suggested">
                    {list
                      .filter((a) => suitable.includes(a.type))
                      .map((a) => (
                        <option key={a.id} value={a.id}>
                          {a.name}
                        </option>
                      ))}
                  </optgroup>
                  <optgroup label="All other accounts">
                    {list
                      .filter((a) => !suitable.includes(a.type))
                      .map((a) => (
                        <option key={a.id} value={a.id}>
                          {a.name} ({a.type})
                        </option>
                      ))}
                  </optgroup>
                </select>
              </div>
            );
          })}
        </div>
      </div>

      <div className="mt-6">
        <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-700">2. Start day</h3>
        <p className="mt-1 text-xs text-neutral-500">
          Only payments received on or after this day are sent. Earlier ones are never sent.
        </p>
        <div className="mt-2 flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="qb-from" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
              Start day
            </label>
            <input
              id="qb-from"
              type="date"
              value={draftFrom}
              max={isoToday()}
              onChange={(e) => setDraftFrom(e.target.value)}
              className="rounded-md border border-neutral-300 px-3 py-2 text-sm"
            />
          </div>
          <button
            onClick={() => setDraftFrom(isoToday())}
            className="font-heading rounded-md border border-neutral-300 px-3 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50"
          >
            From today
          </button>
          <button
            onClick={() => setDraftFrom(sync.earliest_paid_date ?? isoToday())}
            className="font-heading rounded-md border border-neutral-300 px-3 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50"
          >
            Include the payments so far
          </button>
        </div>
      </div>

      <div className="mt-6">
        <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-700">3. Switch on</h3>
        <label className="mt-2 flex items-start gap-2 text-sm text-neutral-800">
          <input
            type="checkbox"
            checked={draftEnabled}
            onChange={(e) => setDraftEnabled(e.target.checked)}
            className="mt-0.5 h-4 w-4 accent-red-600"
          />
          <span>
            Send payments to QuickBooks automatically
            {!canSwitchOn && <span className="block text-xs text-amber-700">Choose an account for every kind of money and a start day first.</span>}
          </span>
        </label>
        <div className="mt-3 flex flex-wrap items-center gap-3">
          <button
            onClick={handleSave}
            disabled={saving || !dirty || (draftEnabled && !canSwitchOn)}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {saving ? "Saving…" : "Save"}
          </button>
          {dirty && <span className="text-xs text-neutral-500">You have changes that are not saved yet.</span>}
        </div>
      </div>

      <div className="mt-7 border-t border-neutral-200 pt-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-700">What has been sent</h3>
          <div className="flex flex-wrap gap-2">
            {sync.counts.failed > 0 && (
              <button
                onClick={() => handleRun(true)}
                disabled={running || dirty || !sync.ready}
                className="font-heading rounded-md border border-red-600 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50 disabled:opacity-50"
              >
                Try the refused ones again
              </button>
            )}
            <button
              onClick={() => handleRun(false)}
              disabled={running || dirty || !sync.ready}
              title={dirty ? "Save your changes first" : !sync.ready ? "Switch sending on first" : undefined}
              className="font-heading flex items-center gap-1.5 rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
            >
              <RefreshCwIcon className={`h-3.5 w-3.5 ${running ? "animate-spin" : ""}`} />
              {running ? "Sending…" : "Send now"}
            </button>
          </div>
        </div>

        <dl className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            { label: "Sent", value: sync.counts.synced },
            { label: "Waiting", value: sync.counts.waiting },
            { label: "Refused", value: sync.counts.failed, alert: sync.counts.failed > 0 },
            { label: "Counted inside another charge", value: sync.counts.skipped },
          ].map((item) => (
            <div key={item.label} className="rounded-lg bg-neutral-50 px-3 py-2.5">
              <dt className="text-[11px] font-bold uppercase tracking-wide text-neutral-500">{item.label}</dt>
              <dd className={`font-heading mt-0.5 text-2xl font-black tabular-nums ${item.alert ? "text-red-600" : "text-neutral-900"}`}>{item.value}</dd>
            </div>
          ))}
        </dl>

        <p className="mt-2 text-xs text-neutral-500">
          {sync.last_synced_at ? `Last checked ${new Date(sync.last_synced_at).toLocaleString()}.` : "Nothing has been sent yet."}
        </p>
        {sync.last_error && (
          <p className="mt-2 flex items-start gap-1.5 text-sm font-medium text-amber-700">
            <AlertCircleIcon className="mt-0.5 h-4 w-4 shrink-0" />
            {sync.last_error}
          </p>
        )}

        {sync.failed.length > 0 && <SyncTable title="Refused by QuickBooks" rows={sync.failed} showError />}
        {sync.recent.length > 0 && <SyncTable title="Most recently sent" rows={sync.recent} />}
      </div>

      {confirmingCreate && (
        <Modal title="Create the accounts in QuickBooks" onClose={() => setConfirmingCreate(false)} maxWidthClassName="max-w-lg">
          <div className="space-y-4">
            <p className="text-sm text-neutral-600">
              These accounts will be created in your QuickBooks and chosen above. If an account with the same name already exists, that one is
              used instead and nothing is duplicated. You can rename them in QuickBooks later.
            </p>
            <ul className="divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm">
              {missingSlots.map((slot) => (
                <li key={slot.key} className="flex items-center justify-between gap-3 px-3 py-2">
                  <span className="font-semibold text-neutral-900">{slot.default_name}</span>
                  <span className="text-xs text-neutral-500">{slot.type}</span>
                </li>
              ))}
            </ul>
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setConfirmingCreate(false)}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
              >
                Cancel
              </button>
              <button
                onClick={handleCreateMissing}
                disabled={creating}
                className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                {creating ? "Creating…" : "Create the accounts"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

function SyncTable({ title, rows, showError = false }: { title: string; rows: QuickbooksSyncRow[]; showError?: boolean }) {
  return (
    <div className="mt-5">
      <p className="text-xs font-bold uppercase tracking-wide text-neutral-500">{title}</p>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[560px] text-left text-sm">
          <thead>
            <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
              <th className="pb-2 font-medium">Paid</th>
              <th className="pb-2 font-medium">Customer</th>
              <th className="pb-2 font-medium">What</th>
              <th className="pb-2 text-right font-medium">Amount</th>
              <th className="pb-2 pl-4 font-medium">{showError ? "Why" : "QuickBooks entry"}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.payment_id} className="border-b border-neutral-100 align-top">
                <td className="py-2 text-neutral-600">{formatDateOnly(row.paid_date)}</td>
                <td className="py-2 text-neutral-900">{row.customer ?? "Unknown"}</td>
                <td className="py-2 text-neutral-600">{TYPE_LABEL[row.type] ?? row.type}</td>
                <td className="py-2 text-right tabular-nums text-neutral-900">{money(row.amount)}</td>
                <td className={`py-2 pl-4 ${showError ? "text-red-600" : "tabular-nums text-neutral-600"}`}>
                  {showError ? row.error : row.entry_id ? `PL-${row.payment_id} (QuickBooks #${row.entry_id})` : "None"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
