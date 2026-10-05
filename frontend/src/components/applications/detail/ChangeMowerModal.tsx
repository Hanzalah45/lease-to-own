"use client";

import { useEffect, useMemo, useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Field, RadioGroup, SelectInput, TextArea, TextInput } from "@/components/applications/wizard/fields";
import { TERM_MONTH_OPTIONS, money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { changeMower, quotePrice, type PriceQuote } from "@/lib/pricing";
import {
  CASH_PRICE_MAX,
  NOTES_MAX,
  validateConditionNotes,
  validateEquipmentModel,
  validateMoney,
  validatePercent,
  validateSerialNumber,
  validateYear,
} from "@/lib/validation";
import type { Application } from "@/types/application";

/** Waits for the user to stop typing before asking the server for a quote. */
const QUOTE_DELAY_MS = 300;

/** The unit's notes are written as "Condition: used · Year: 2020 · description"; read them back for editing. */
function parseNotes(notes: string | null | undefined) {
  const text = notes ?? "";
  const condition = /Condition:\s*(new|used)/i.exec(text)?.[1]?.toLowerCase() === "used" ? "used" : "new";
  const year = /Year:\s*(\d{4})/.exec(text)?.[1] ?? "";
  const parts = text.split(" · ");
  const description = parts.length > 2 ? parts.slice(2).join(" · ") : "";
  return { condition: condition as "new" | "used", year, description };
}

/**
 * Swaps the mower on an application that already has equipment and pricing
 * and re-prices the lease (client, Joel, 2026-10-06). The server recalculates
 * everything from the cash price with the same code as a new application, and
 * cancels a signed contract (after the admin confirms) so the customer signs
 * the new price.
 */
export function ChangeMowerModal({
  application,
  onClose,
  onSaved,
}: {
  application: Application;
  onClose: () => void;
  onSaved: (application: Application, contractVoided: boolean) => void;
}) {
  const lease = application.lease_agreement;
  const unit = lease?.equipment_unit;
  const signed = !!lease?.contract;
  // Money already taken means the new price would not match what was paid; the
  // server refuses too, this just says so up front.
  const collected =
    application.deposit_received ||
    application.pickup_balance_received ||
    !!lease?.payments?.some((p) => p.status === "paid");

  const initial = useMemo(() => {
    const [make = "", ...rest] = (unit?.model ?? "").split(" ");
    return { make, model: rest.join(" "), ...parseNotes(unit?.condition_notes) };
  }, [unit]);

  const [make, setMake] = useState(initial.make);
  const [model, setModel] = useState(initial.model);
  const [year, setYear] = useState(initial.year);
  const [condition, setCondition] = useState<"new" | "used">(initial.condition);
  const [serial, setSerial] = useState(unit?.serial_number ?? "");
  const [description, setDescription] = useState(initial.description);
  const [cashPrice, setCashPrice] = useState(lease?.cash_price ? String(Number(lease.cash_price)) : "");
  const [taxRate, setTaxRate] = useState(lease ? String(+(Number(lease.sales_tax_rate) * 100).toFixed(4)) : "8.25");
  const [termMonths, setTermMonths] = useState(String(lease?.term_months ?? 36));
  const [ldw, setLdw] = useState<"yes" | "no">(lease?.ldw_selected ? "yes" : "no");
  const [confirmVoid, setConfirmVoid] = useState(false);
  const [showErrors, setShowErrors] = useState(false);
  const [saving, setSaving] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [apiErrors, setApiErrors] = useState<Record<string, string[]>>({});
  const [quoted, setQuoted] = useState<{ key: string; quote?: PriceQuote; error?: string } | null>(null);

  const errors: Record<string, string | undefined> = {
    make: validateEquipmentModel(make, "Make"),
    model: validateEquipmentModel(model),
    year: validateYear(year),
    serial: validateSerialNumber(serial),
    description: validateConditionNotes(description),
    cash_price: validateMoney(cashPrice, "Cash price", { aboveZero: true, max: CASH_PRICE_MAX }),
    tax_rate: validatePercent(taxRate, "Sales tax rate"),
  };
  const shown = (key: string) => (showErrors ? errors[key] : undefined) ?? apiErrors[key]?.[0];
  const priceReady = !errors.cash_price && !errors.tax_rate;

  const quoteKey = JSON.stringify([cashPrice, taxRate, termMonths, ldw]);
  useEffect(() => {
    if (!priceReady) return;
    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const quote = await quotePrice({ cashPrice, taxRate, termMonths, ldw });
        if (!cancelled) setQuoted({ key: quoteKey, quote });
      } catch (err) {
        if (!cancelled) setQuoted({ key: quoteKey, error: err instanceof ApiError ? err.message : "Could not calculate this price." });
      }
    }, QUOTE_DELAY_MS);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [priceReady, cashPrice, taxRate, termMonths, ldw, quoteKey]);

  const current = priceReady && quoted?.key === quoteKey ? quoted : null;
  const quote = current?.quote;
  const oldPricing = lease?.pricing;

  async function save() {
    setShowErrors(true);
    setSubmitError(null);
    if (Object.values(errors).some(Boolean)) return;

    setSaving(true);
    setApiErrors({});
    try {
      const { application: updated, contractVoided } = await changeMower(application.id, {
        make,
        model,
        serial,
        condition,
        year,
        description,
        cashPrice,
        taxRate,
        termMonths,
        ldw,
        voidSignedContract: confirmVoid,
      });
      onSaved(updated, contractVoided);
    } catch (err) {
      if (err instanceof ApiError && err.errors) setApiErrors(err.errors);
      setSubmitError(err instanceof ApiError ? err.message : "Could not change the mower. Please try again.");
    } finally {
      setSaving(false);
    }
  }

  const canSave = !collected && (!signed || confirmVoid) && !saving;

  return (
    <Modal title="Change mower" onClose={onClose} maxWidthClassName="max-w-3xl">
      <div className="space-y-5">
        <p className="text-sm text-neutral-500">
          Enter the mower the customer wants now. The monthly payment, deposit and total are recalculated from the new
          cash price. The customer&rsquo;s billing cycle and AutoPay choice stay as they are.
        </p>

        {collected && (
          <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            A deposit or payment has already been collected on this application, so the mower can&rsquo;t be changed
            here. The new price would no longer match what was paid.
          </div>
        )}

        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field label="Make" required error={shown("make")}>
            <TextInput value={make} onChange={setMake} hasError={!!shown("make")} />
          </Field>
          <Field label="Model" required error={shown("model")}>
            <TextInput value={model} onChange={setModel} hasError={!!shown("model")} />
          </Field>
          <Field label="Condition" required>
            <SelectInput
              value={condition}
              onChange={(v) => setCondition(v as "new" | "used")}
              options={[
                { value: "new", label: "New" },
                { value: "used", label: "Used" },
              ]}
            />
          </Field>
          <Field label="Year" required error={shown("year")}>
            <TextInput value={year} onChange={setYear} placeholder="2025" hasError={!!shown("year")} />
          </Field>
          <Field label="Serial #" required error={shown("serial")}>
            <TextInput value={serial} onChange={setSerial} hasError={!!shown("serial")} />
          </Field>
          <Field label="Description" error={shown("description")} hint={`Up to ${NOTES_MAX} characters.`}>
            <TextArea value={description} onChange={setDescription} hasError={!!shown("description")} />
          </Field>
          <Field label="Cash Price / Retail" required error={shown("cash_price")}>
            <TextInput value={cashPrice} onChange={setCashPrice} type="number" hasError={!!shown("cash_price")} />
          </Field>
          <Field label="Sales Tax %" required error={shown("tax_rate")}>
            <TextInput value={taxRate} onChange={setTaxRate} type="number" hasError={!!shown("tax_rate")} />
          </Field>
          <Field label="Lease Months to Ownership" error={shown("term_months")}>
            <RadioGroup
              value={termMonths}
              onChange={setTermMonths}
              options={TERM_MONTH_OPTIONS.map((m) => ({ value: String(m), label: `${m} mo` }))}
            />
          </Field>
          <Field label="LDW (Loss Damage Waiver)">
            <RadioGroup
              value={ldw}
              onChange={(v) => setLdw(v as "yes" | "no")}
              options={[
                { value: "yes", label: "Yes" },
                { value: "no", label: "No" },
              ]}
            />
          </Field>
        </div>

        <div className="overflow-x-auto rounded-lg border border-neutral-200">
          <table className="w-full min-w-[26rem] text-sm">
            <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-400">
              <tr>
                <th className="px-4 py-2 font-semibold"> </th>
                <th className="px-4 py-2 text-right font-semibold">Current</th>
                <th className="px-4 py-2 text-right font-semibold">New</th>
              </tr>
            </thead>
            <tbody className="text-neutral-700">
              <ComparisonRow
                label="Cash price"
                before={lease ? money(Number(lease.cash_price)) : "—"}
                after={quote ? money(quote.cash_price) : "—"}
              />
              <ComparisonRow
                label="Monthly payment (bank)"
                before={oldPricing ? money(oldPricing.monthly.bank) : "—"}
                after={quote ? money(quote.pricing.monthly.bank) : "—"}
              />
              <ComparisonRow
                label="Monthly payment (card)"
                before={oldPricing ? money(oldPricing.monthly.card) : "—"}
                after={quote ? money(quote.pricing.monthly.card) : "—"}
              />
              <ComparisonRow
                label="Security deposit (bank)"
                before={oldPricing ? money(oldPricing.deposit.bank) : "—"}
                after={quote ? money(quote.pricing.deposit.bank) : "—"}
              />
              <ComparisonRow
                label="Due at pickup (bank)"
                before={oldPricing ? money(oldPricing.full.bank) : "—"}
                after={quote ? money(quote.pricing.full.bank) : "—"}
                strong
              />
            </tbody>
          </table>
        </div>
        {current?.error && <p className="text-sm text-red-600">{current.error}</p>}

        {signed && !collected && (
          <label className="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <input
              type="checkbox"
              checked={confirmVoid}
              onChange={(e) => setConfirmVoid(e.target.checked)}
              className="mt-0.5 h-4 w-4 shrink-0 accent-red-600"
            />
            <span>
              This lease is already signed. Changing the mower changes the price, so the signed contract will be
              cancelled and the customer will be asked to sign the new one.
            </span>
          </label>
        )}

        {submitError && <p className="text-sm text-red-600">{submitError}</p>}

        <div className="flex items-center justify-between">
          <button
            onClick={onClose}
            disabled={saving}
            className="font-heading rounded-md border border-neutral-300 px-4 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={save}
            disabled={!canSave}
            className="font-heading rounded-md bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {saving ? "Saving…" : "Change mower & re-price"}
          </button>
        </div>
      </div>
    </Modal>
  );
}

function ComparisonRow({ label, before, after, strong }: { label: string; before: string; after: string; strong?: boolean }) {
  return (
    <tr className={`border-t border-neutral-100 ${strong ? "font-bold text-neutral-900" : ""}`}>
      <td className="px-4 py-2.5">{label}</td>
      <td className="px-4 py-2.5 text-right tabular-nums">{before}</td>
      <td className="px-4 py-2.5 text-right tabular-nums">{after}</td>
    </tr>
  );
}
