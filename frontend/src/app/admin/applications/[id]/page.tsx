"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { StatusPipeline } from "@/components/applications/detail/StatusPipeline";
import { TakeActionBanner } from "@/components/applications/detail/TakeActionBanner";
import { InfoCallout } from "@/components/applications/detail/InfoCallout";
import { DetailCard } from "@/components/applications/detail/DetailCard";
import { DealerNotes } from "@/components/applications/detail/DealerNotes";
import { ChecklistCard } from "@/components/applications/detail/ChecklistCard";
import { AssignmentCard } from "@/components/applications/detail/AssignmentCard";
import { EditDetailModal, type EditField } from "@/components/applications/detail/EditDetailModal";
import { EpoChart } from "@/components/applications/wizard/EpoChart";
import type { AppStatus, DealerNote } from "@/components/applications/detail/types";
import type { AdminPermissionKey } from "@/types/auth";
import {
  AlertCircleIcon,
  CheckCircleIcon,
  ClockIcon,
  CreditCardIcon,
  DocumentIcon,
  SettingsIcon,
} from "@/components/icons";

const RISK_OPTIONS = ["Pending", "Passed", "Clear", "Needs Review", "Flagged"];

const BADGE_STYLE: Record<AppStatus, { label: string; color: string; icon?: "clock" | "check" | "x" | "dollar" | "thumb" }> = {
  submitted: { label: "Submitted", color: "bg-neutral-700 text-white" },
  under_review: { label: "Under Review", color: "bg-amber-500 text-white" },
  needs_info: { label: "Needs Info", color: "bg-amber-500 text-white" },
  approved: { label: "Approved", color: "bg-blue-600 text-white" },
  completed: { label: "Completed", color: "bg-teal-500 text-white" },
  processed: { label: "Processed", color: "bg-purple-600 text-white" },
  funded: { label: "Funded", color: "bg-green-600 text-white" },
  declined: { label: "Application Declined", color: "bg-neutral-100 text-red-700 border border-red-200" },
  withdrawn: { label: "Withdrawn", color: "bg-neutral-200 text-neutral-700" },
};

const EPO_SCHEDULE = [
  { month: 1, value: 7025.31 }, { month: 3, value: 6277.93 }, { month: 6, value: 17488.63 },
  { month: 9, value: 15807.03 }, { month: 12, value: 14125.42 }, { month: 15, value: 12443.81 },
  { month: 18, value: 10762.21 }, { month: 21, value: 9080.6 }, { month: 24, value: 7399.0 },
  { month: 27, value: 5717.4 }, { month: 30, value: 4035.79 }, { month: 33, value: 2354.18 },
  { month: 36, value: 0 },
];

const INITIAL_NOTES: DealerNote[] = [
  { id: 1, author: "Dealer", time: "Aug 3, 3:33 PM", isDealer: true, text: "Customer paid, but checklist still shows pending payment — please confirm." },
  { id: 2, author: "Renee · ProStart", time: "", text: "Got it, thank you!" },
  { id: 3, author: "Renee · ProStart", time: "", text: "We need signed documents and invoice uploaded to finish this file." },
];

const RISK_BY_STATUS: Record<AppStatus, { identity: string; employment: string; bank: string; background: string; note: string }> = {
  submitted: { identity: "Pending", employment: "Pending", bank: "Pending", background: "Pending", note: "Just submitted by the customer — no review has started yet." },
  under_review: { identity: "Pending", employment: "Pending", bank: "Pending", background: "Pending", note: "All checks submitted — awaiting results from identity, Plaid, and background services." },
  needs_info: { identity: "Needs Review", employment: "Passed", bank: "Pending", background: "Pending", note: "Flag: physical address could not be verified against ID. Landlord contact triggered — awaiting response." },
  approved: { identity: "Passed", employment: "Passed", bank: "Pending", background: "Clear", note: "Flag: physical address could not be verified against ID. Landlord contact triggered — awaiting response." },
  completed: { identity: "Passed", employment: "Passed", bank: "Passed", background: "Clear", note: "Red flags: none logged. Landlord contact not triggered — homeowner, no address discrepancy." },
  processed: { identity: "Passed", employment: "Passed", bank: "Passed", background: "Clear", note: "Red flags: none logged. Landlord contact not triggered — homeowner, no address discrepancy." },
  funded: { identity: "Passed", employment: "Passed", bank: "Passed", background: "Clear", note: "Red flags: none logged. Landlord contact not triggered — homeowner, no address discrepancy." },
  declined: { identity: "Passed", employment: "Passed", bank: "Passed", background: "Flagged", note: "High risk: prior lease-to-own default found on background check." },
  withdrawn: { identity: "Passed", employment: "Passed", bank: "Passed", background: "Clear", note: "Red flags: none logged. Landlord contact not triggered — homeowner, no address discrepancy." },
};

const RISK_COLOR: Record<string, string> = {
  Pending: "text-amber-600",
  Passed: "text-green-600",
  Clear: "text-green-600",
  "Needs Review": "text-amber-600",
  Flagged: "text-red-600",
};

const FLOW: Partial<Record<AppStatus, AppStatus>> = {
  submitted: "under_review",
  under_review: "approved",
  needs_info: "approved",
  approved: "completed",
  completed: "processed",
  processed: "funded",
};

const PRIMARY_LABEL: Partial<Record<AppStatus, string>> = {
  submitted: "Mark Under Review",
  under_review: "Approved",
  needs_info: "Approved",
  approved: "Mark Completed",
  completed: "Mark Processed",
  processed: "Mark Funded",
};

const INITIAL_STATUS_BY_ID: Record<string, AppStatus> = {
  "1": "submitted",
  "3": "funded",
  "4": "under_review",
  "5": "completed",
  "6": "needs_info",
  "7": "approved",
  "8": "declined",
  "9": "processed",
  "10": "withdrawn",
};

const CUSTOMER_FIELDS: EditField[] = [
  { key: "name", label: "Name", value: "Robert Kirkland" },
  { key: "dlMatches", label: "DL matches address", value: "Yes" },
  { key: "mailingAddress", label: "Mailing address", value: "10320 Walnut Dr" },
  { key: "physicalAddress", label: "Physical address", value: "Same as mailing" },
  { key: "county", label: "County", value: "Montgomery" },
  { key: "yearsAtResidence", label: "Years at residence", value: "Over 5 years" },
  { key: "ownRent", label: "Own / rent", value: "Own" },
];

const LEASE_FIELDS: EditField[] = [
  { key: "term", label: "Term", value: "36 months" },
  { key: "monthlyRental", label: "Monthly rental", value: "$373.69" },
  { key: "salesTax", label: "Sales tax @ 8.25%", value: "$30.83" },
  { key: "totalMonthly", label: "Total monthly", value: "$404.52" },
  { key: "securityDeposit", label: "Security deposit", value: "$443.94" },
  { key: "totalDue", label: "Total due", value: "$848.46" },
  { key: "autopay", label: "AutoPay", value: "Yes · Checking" },
  { key: "ldw", label: "LDW selected", value: "Yes" },
  { key: "promo", label: "Promo applied", value: "—" },
];

const EQUIPMENT_FIELDS: EditField[] = [
  { key: "makeModel", label: "Make / model", value: 'Worldlawn Diamondback 60"' },
  { key: "cashPrice", label: "Cash price", value: "$7,399.00" },
  { key: "condition", label: "Condition", value: "New / 2026" },
  { key: "serial", label: "Serial # / VIN", value: "AGZ3WA18973" },
  { key: "deliveryDate", label: "Delivery date", value: "7/22/2026" },
  { key: "expectedOwnership", label: "Expected ownership", value: "7/22/2029" },
  { key: "liveEpo", label: "Live EPO price", value: "$3,475.26" },
];

export default function ApplicationDetailPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const hasFullAccess = isSuperAdmin || restrictions.length === 0;
  const can = (perm: AdminPermissionKey) => hasFullAccess || restrictions.includes(perm);

  const [status, setStatus] = useState<AppStatus>(INITIAL_STATUS_BY_ID[params.id] ?? "submitted");
  const [notes, setNotes] = useState<DealerNote[]>(INITIAL_NOTES);
  const [checklist, setChecklist] = useState([
    { label: "Payment collected", done: false },
    { label: "Invoice received", done: false },
    { label: "Signed documents", done: false },
  ]);

  const [customer, setCustomer] = useState(() => Object.fromEntries(CUSTOMER_FIELDS.map((f) => [f.key, f.value])));
  const [lease, setLease] = useState(() => Object.fromEntries(LEASE_FIELDS.map((f) => [f.key, f.value])));
  const [equipment, setEquipment] = useState(() => Object.fromEntries(EQUIPMENT_FIELDS.map((f) => [f.key, f.value])));
  const [risk, setRisk] = useState(() => RISK_BY_STATUS[INITIAL_STATUS_BY_ID[params.id] ?? "submitted"]);
  const [editingCard, setEditingCard] = useState<"customer" | "lease" | "equipment" | "risk" | null>(null);

  const signed = status === "completed" || status === "processed" || status === "funded";
  const checklistDone = signed;

  function advance() {
    const next = FLOW[status];
    if (next) {
      setStatus(next);
      setRisk(RISK_BY_STATUS[next]);
      setChecklist((c) => c.map((i) => (next === "completed" || next === "processed" || next === "funded" ? { ...i, done: true } : i)));
    }
  }

  function decline() {
    setStatus("declined");
    setRisk(RISK_BY_STATUS.declined);
  }

  function addNote(text: string) {
    setNotes((n) => [...n, { id: n.length + 1, author: "You", time: "Just now", text }]);
  }

  function toggleChecklist(index: number) {
    setChecklist((c) => c.map((item, i) => (i === index ? { ...item, done: !item.done } : item)));
  }

  const badge = BADGE_STYLE[status];

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <Link
          href="/admin/applications"
          className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline"
        >
          ← All Applications
        </Link>
        <div className="mt-1 flex items-center gap-3">
          <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">{customer.name}</h1>
          <span className={`font-heading rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ${badge.color}`}>
            {badge.label}
          </span>
        </div>
        <p className="text-sm text-neutral-400">Outdoor Fix · Willis · Submitted by Outdoor Fix</p>
      </div>

      {status !== "declined" && status !== "withdrawn" && <StatusPipeline status={status} />}

      {status === "submitted" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.submitted!} onPrimary={advance} onDecline={decline} disabled={!can("application_review")} />
          <InfoCallout
            tone="blue"
            icon={DocumentIcon}
            title="New submission"
            description="Just came in — no review or automated checks have started yet."
            items={["Just submitted by the customer — no review has started yet."]}
          />
        </>
      )}

      {status === "under_review" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.under_review!} onPrimary={advance} onDecline={decline} disabled={!can("application_review")} />
          <InfoCallout
            tone="green"
            icon={ClockIcon}
            title="Verification in progress"
            description="Automated checks are running — no human review needed yet."
            items={["Identity check — Pending", "Bank verification (Plaid) — Pending", "Background check — Pending"]}
          />
        </>
      )}

      {status === "needs_info" && (
        <>
          <TakeActionBanner
            title="Action required"
            description="This application is missing or has incorrect information."
            primaryLabel={PRIMARY_LABEL.needs_info!}
            onPrimary={advance}
            onDecline={decline}
            disabled={!can("application_review")}
          />
          <InfoCallout
            tone="amber"
            icon={AlertCircleIcon}
            title="Action required"
            description="This application is missing or has incorrect information."
            items={["Flag: physical address could not be verified against ID. Landlord contact triggered — awaiting response."]}
          />
        </>
      )}

      {status === "approved" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.approved!} onPrimary={advance} onDecline={decline} disabled={!can("application_review")} />
          <InfoCallout
            tone="blue"
            icon={CheckCircleIcon}
            title="Approved — next step"
            description="Application passed underwriting. Contract not yet sent."
            items={["All risk checks passed", "Contract generation pending — send for signature"]}
          />
        </>
      )}

      {status === "completed" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.completed!} onPrimary={advance} onDecline={decline} disabled={!can("application_review")} />
          <InfoCallout
            tone="teal"
            icon={SettingsIcon}
            title="Equipment delivered"
            description="Unit is with the customer. Final funding step remaining."
            items={["Contract signed", "Delivered 8/12/2026", "Awaiting first payment to move to Funded"]}
          />
        </>
      )}

      {status === "processed" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.processed!} onPrimary={advance} onDecline={decline} disabled={!can("application_review")} />
          <InfoCallout
            tone="purple"
            icon={CreditCardIcon}
            title="Processing final payment"
            description="Payment submitted — waiting on bank confirmation."
            items={["Contract signed", "ACH payment processing (1–2 business days)"]}
          />
          <div className="rounded-xl border border-green-200 bg-green-50 px-5 py-3.5">
            <p className="text-sm font-bold text-green-700">Payment received — $848.46</p>
            <p className="text-xs text-neutral-500">8/1/2026, 2:06 PM · Mastercard •••• 2896 · Receipt saved to documents</p>
          </div>
        </>
      )}

      {status === "funded" && (
        <div className="rounded-xl border border-green-200 bg-green-50 px-5 py-3.5">
          <p className="text-sm font-bold text-green-700">Payment received — $848.46</p>
          <p className="text-xs text-neutral-500">8/1/2026, 2:06 PM · Mastercard •••• 2896 · Receipt saved to documents</p>
        </div>
      )}

      {status === "declined" && (
        <div className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-bold text-red-700">Application Declined</p>
            <p className="text-xs text-neutral-500">
              Prior lease-to-own default found on background check — application declined per risk policy.
            </p>
          </div>
          <button
            onClick={() => setStatus("submitted")}
            className="font-heading shrink-0 self-start rounded-md border border-neutral-300 bg-white px-4 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50 sm:self-auto"
          >
            Change Status
          </button>
        </div>
      )}

      {status === "withdrawn" && (
        <div className="rounded-xl border border-neutral-200 bg-neutral-100 px-5 py-4">
          <p className="text-sm font-bold text-neutral-700">Application Withdrawn</p>
          <p className="text-xs text-neutral-500">Customer withdrew the application — decided to pay cash instead.</p>
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_1fr_20rem]">
        <div className="space-y-6 lg:col-span-2">
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <DetailCard
              title="Customer"
              editable
              canEdit={can("application_review")}
              onEdit={() => setEditingCard("customer")}
              rows={[
                { label: "Name", value: customer.name },
                { label: "DL matches address", value: customer.dlMatches },
                { label: "Mailing address", value: customer.mailingAddress },
                { label: "Physical address", value: customer.physicalAddress },
                { label: "County", value: customer.county },
                { label: "Years at residence", value: customer.yearsAtResidence },
                { label: "Own / rent", value: customer.ownRent },
              ]}
            />
            <DetailCard
              title="Lease terms"
              editable
              canEdit={can("contract_generation")}
              onEdit={() => setEditingCard("lease")}
              rows={[
                { label: "Term", value: lease.term },
                { label: "Monthly rental", value: lease.monthlyRental },
                { label: "Sales tax @ 8.25%", value: lease.salesTax },
                { label: "Total monthly", value: lease.totalMonthly },
                { label: "Security deposit", value: lease.securityDeposit },
                { label: "Total due", value: lease.totalDue },
                { label: "AutoPay", value: lease.autopay },
                { label: "LDW selected", value: lease.ldw },
                { label: "Promo applied", value: lease.promo },
              ]}
            />
            <DetailCard
              title="Equipment unit"
              editable
              canEdit={can("equipment_tracking")}
              onEdit={() => setEditingCard("equipment")}
              rows={[
                { label: "Make / model", value: equipment.makeModel },
                { label: "Cash price", value: equipment.cashPrice },
                { label: "Condition", value: equipment.condition },
                { label: "Serial # / VIN", value: equipment.serial },
                { label: "Delivery date", value: equipment.deliveryDate },
                { label: "Expected ownership", value: equipment.expectedOwnership },
                { label: "Live EPO price", value: <span className="text-red-600">{equipment.liveEpo}</span> },
              ]}
              note="Service history: no records yet · GPS: not tracked (Phase 2)"
            />
            <DetailCard
              title="Risk profile"
              editable
              canEdit={can("risk_assessment")}
              onEdit={() => setEditingCard("risk")}
              rows={[
                { label: "Identity verification", value: <span className={RISK_COLOR[risk.identity]}>{risk.identity}</span> },
                { label: "Employment verification", value: <span className={RISK_COLOR[risk.employment]}>{risk.employment}</span> },
                { label: "Bank verification (Plaid)", value: <span className={RISK_COLOR[risk.bank]}>{risk.bank}</span> },
                { label: "Background check", value: <span className={RISK_COLOR[risk.background]}>{risk.background}</span> },
                { label: "Residence type", value: "Single-family, Own" },
              ]}
              note={risk.note}
            />
          </div>

          <div className="rounded-xl border border-neutral-200 bg-white p-5">
            <div className="mb-4 flex items-center justify-between">
              <span className="flex items-center gap-2">
                <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
                <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">Contract & e-signature</h2>
              </span>
            </div>
            <div className="space-y-2.5 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-neutral-500">Document</span>
                <span className="font-semibold text-neutral-900">Lease Purchase Agreement v1</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-neutral-500">Signed by</span>
                <span className="font-semibold text-neutral-900">{signed ? customer.name : "—"}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-neutral-500">Timestamp</span>
                <span className="font-semibold text-neutral-900">{signed ? "7/20/2026, 4:12 PM CT" : "—"}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-neutral-500">Status</span>
                <span className={`font-semibold ${signed ? "text-green-600" : "text-amber-600"}`}>
                  {signed ? "Signed & legally valid" : "Awaiting signature"}
                </span>
              </div>
            </div>
            {signed ? (
              <Link
                href={`/admin/applications/${params.id}/contract`}
                className="font-heading mt-4 inline-block rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
              >
                Download signed PDF
              </Link>
            ) : (
              <Link
                href={`/admin/applications/${params.id}/contract`}
                className="font-heading mt-4 inline-block rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
              >
                Send For Signature
              </Link>
            )}
          </div>
        </div>

        <div className="space-y-6">
          <DealerNotes notes={notes} onAdd={addNote} />
          <ChecklistCard items={checklist.map((c) => ({ ...c, done: c.done || checklistDone }))} onToggle={toggleChecklist} />
          <AssignmentCard salesperson="Willis" reviewedBy="Renee · ProStart" />
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-2 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">EPO payoff schedule</h2>
        </div>
        <p className="mb-6 text-xs text-neutral-400">
          Early Purchase Option price by month, updated live as payments post. Excludes tax.
        </p>
        <EpoChart schedule={EPO_SCHEDULE} />
      </div>

      {editingCard === "customer" && (
        <EditDetailModal
          title="Customer"
          fields={CUSTOMER_FIELDS.map((f) => ({ ...f, value: customer[f.key] }))}
          onSave={(values) => setCustomer((c) => ({ ...c, ...values }))}
          onClose={() => setEditingCard(null)}
        />
      )}
      {editingCard === "lease" && (
        <EditDetailModal
          title="Lease terms"
          fields={LEASE_FIELDS.map((f) => ({ ...f, value: lease[f.key] }))}
          onSave={(values) => setLease((l) => ({ ...l, ...values }))}
          onClose={() => setEditingCard(null)}
        />
      )}
      {editingCard === "equipment" && (
        <EditDetailModal
          title="Equipment unit"
          fields={EQUIPMENT_FIELDS.map((f) => ({ ...f, value: equipment[f.key] }))}
          onSave={(values) => setEquipment((e) => ({ ...e, ...values }))}
          onClose={() => setEditingCard(null)}
        />
      )}
      {editingCard === "risk" && (
        <EditDetailModal
          title="Risk profile"
          fields={[
            { key: "identity", label: "Identity verification", value: risk.identity, type: "select", options: RISK_OPTIONS },
            { key: "employment", label: "Employment verification", value: risk.employment, type: "select", options: RISK_OPTIONS },
            { key: "bank", label: "Bank verification (Plaid)", value: risk.bank, type: "select", options: RISK_OPTIONS },
            { key: "background", label: "Background check", value: risk.background, type: "select", options: RISK_OPTIONS },
            { key: "note", label: "Note", value: risk.note, type: "textarea" },
          ]}
          onSave={(values) => setRisk((r) => ({ ...r, ...values }))}
          onClose={() => setEditingCard(null)}
        />
      )}
    </div>
  );
}
