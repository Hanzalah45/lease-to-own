"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
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
import type { Application } from "@/types/application";
import { downloadIdDocument, getApplication, updateApplication } from "@/lib/applications";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import {
  AlertCircleIcon,
  CheckCircleIcon,
  ClockIcon,
  CreditCardIcon,
  DocumentIcon,
  SettingsIcon,
} from "@/components/icons";

const RESIDENCE_LABEL: Record<string, string> = { house: "House", apartment: "Apartment", other: "Other" };
const VERIFICATION_LABEL: Record<string, string> = { pending: "Pending", verified: "Verified", failed: "Failed" };
const BACKGROUND_LABEL: Record<string, string> = { pending: "Pending", clear: "Clear", flagged: "Flagged" };
const RISK_COLOR: Record<string, string> = {
  pending: "text-amber-600",
  verified: "text-green-600",
  clear: "text-green-600",
  failed: "text-red-600",
  flagged: "text-red-600",
};

const BADGE_STYLE: Record<AppStatus, { label: string; color: string }> = {
  submitted: { label: "Submitted", color: "bg-neutral-700 text-white" },
  under_review: { label: "Under Review", color: "bg-amber-500 text-white" },
  needs_info: { label: "Needs Info", color: "bg-amber-500 text-white" },
  approved: { label: "Approved", color: "bg-blue-600 text-white" },
  completed: { label: "Completed", color: "bg-teal-500 text-white" },
  processed: { label: "Processed", color: "bg-purple-600 text-white" },
  funded_paid: { label: "Funded", color: "bg-green-600 text-white" },
  declined: { label: "Application Declined", color: "bg-neutral-100 text-red-700 border border-red-200" },
  withdrawn: { label: "Withdrawn", color: "bg-neutral-200 text-neutral-700" },
};

const FLOW: Partial<Record<AppStatus, AppStatus>> = {
  submitted: "under_review",
  under_review: "approved",
  needs_info: "approved",
  approved: "completed",
  completed: "processed",
  processed: "funded_paid",
};

const PRIMARY_LABEL: Partial<Record<AppStatus, string>> = {
  submitted: "Mark Under Review",
  under_review: "Approved",
  needs_info: "Approved",
  approved: "Mark Completed",
  completed: "Mark Processed",
  processed: "Mark Funded",
};

const INITIAL_CHECKLIST = [
  { label: "Payment collected", done: false },
  { label: "Invoice received", done: false },
  { label: "Signed documents", done: false },
];

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function ApplicationDetailPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const hasFullAccess = isSuperAdmin || restrictions.length === 0;
  const can = (perm: AdminPermissionKey) => hasFullAccess || restrictions.includes(perm);

  const [application, setApplication] = useState<Application | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [acting, setActing] = useState(false);

  const [notes, setNotes] = useState<DealerNote[]>([]);
  const [checklist, setChecklist] = useState(INITIAL_CHECKLIST);
  const [editingCard, setEditingCard] = useState<"customer" | "lease" | "equipment" | "risk" | null>(null);

  const load = useCallback(async () => {
    try {
      const app = await getApplication(params.id);
      setApplication(app);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this application.");
    } finally {
      setLoading(false);
    }
  }, [params.id]);

  useEffect(() => {
    load();
  }, [load]);

  async function advance() {
    if (!application) return;
    const next = FLOW[application.status];
    if (!next) return;
    setActing(true);
    try {
      setApplication(await updateApplication(application.id, { status: next }));
    } finally {
      setActing(false);
    }
  }

  async function decline() {
    if (!application) return;
    setActing(true);
    try {
      setApplication(
        await updateApplication(application.id, { status: "declined", status_notes: "Declined during admin review." }),
      );
    } finally {
      setActing(false);
    }
  }

  function addNote(text: string) {
    setNotes((n) => [...n, { id: n.length + 1, author: "You", time: "Just now", text }]);
  }

  function toggleChecklist(index: number) {
    setChecklist((c) => c.map((item, i) => (i === index ? { ...item, done: !item.done } : item)));
  }

  async function saveLease(values: Record<string, string>) {
    if (!application) return;
    setApplication(
      await updateApplication(application.id, {
        lease: {
          term_months: Number(values.term_months),
          monthly_rental_payment: Number(values.monthly_rental_payment),
          security_deposit: Number(values.security_deposit),
          autopay_enabled: values.autopay_enabled === "Yes",
          ldw_selected: values.ldw_selected === "Yes",
          promo_code: values.promo_code || null,
        },
      }),
    );
  }

  async function saveEquipment(values: Record<string, string>) {
    if (!application) return;
    setApplication(
      await updateApplication(application.id, {
        equipment: {
          model: values.model,
          serial_number: values.serial_number,
          condition_notes: values.condition_notes || null,
        },
      }),
    );
  }

  async function saveCustomer(values: Record<string, string>) {
    if (!application) return;
    setApplication(
      await updateApplication(application.id, {
        customer: {
          address_line_1: values.address_line_1 || null,
          city: values.city || null,
          state: values.state || null,
          zip: values.zip || null,
          residence_type: values.residence_type || null,
        },
      }),
    );
  }

  async function saveRisk(values: Record<string, string>) {
    if (!application) return;
    setApplication(
      await updateApplication(application.id, {
        risk: {
          identity_verification_status: values.identity_verification_status,
          employment_verification_status: values.employment_verification_status,
          bank_verification_status: values.bank_verification_status,
          background_check_status: values.background_check_status,
          background_check_notes: values.background_check_notes || null,
        },
      }),
    );
  }

  if (loading) {
    return <p className="py-12 text-center text-sm text-neutral-400">Loading…</p>;
  }

  if (error || !application) {
    return (
      <div className="space-y-3">
        <Link href="/admin/applications" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
          ← All Applications
        </Link>
        <p className="text-sm text-red-600">{error ?? "Application not found."}</p>
      </div>
    );
  }

  const status = application.status;
  const customer = application.customer;
  const profile = customer?.customer_profile;
  const risk = customer?.risk_profile;
  const lease = application.lease_agreement;
  const equipment = lease?.equipment_unit;
  const badge = BADGE_STYLE[status];
  const paidPayment = lease?.payments?.slice().reverse().find((p) => p.status === "paid");

  const signed = status === "completed" || status === "processed" || status === "funded_paid";
  const checklistDone = signed;

  const salesTaxPct = lease ? (num(lease.sales_tax_rate) * 100).toFixed(2) : "0";
  const totalDue = lease ? num(lease.total_monthly_payment) + num(lease.security_deposit) : 0;

  const CUSTOMER_FIELDS: EditField[] = [
    { key: "address_line_1", label: "Mailing address", value: profile?.address_line_1 ?? "" },
    { key: "city", label: "City", value: profile?.city ?? "" },
    { key: "state", label: "State", value: profile?.state ?? "" },
    { key: "zip", label: "Zip", value: profile?.zip ?? "" },
    {
      key: "residence_type",
      label: "Residence type",
      value: profile?.residence_type ?? "house",
      type: "select",
      options: ["house", "apartment", "other"],
    },
  ];

  const LEASE_FIELDS: EditField[] = lease
    ? [
        { key: "term_months", label: "Term (months)", value: String(lease.term_months) },
        { key: "monthly_rental_payment", label: "Monthly rental", value: lease.monthly_rental_payment },
        { key: "security_deposit", label: "Security deposit", value: lease.security_deposit },
        { key: "autopay_enabled", label: "AutoPay", value: lease.autopay_enabled ? "Yes" : "No", type: "select", options: ["Yes", "No"] },
        { key: "ldw_selected", label: "LDW selected", value: lease.ldw_selected ? "Yes" : "No", type: "select", options: ["Yes", "No"] },
        { key: "promo_code", label: "Promo code", value: lease.promo_code ?? "" },
      ]
    : [];

  const EQUIPMENT_FIELDS: EditField[] = [
    { key: "model", label: "Make / model", value: equipment?.model ?? "" },
    { key: "serial_number", label: "Serial # / VIN", value: equipment?.serial_number ?? "" },
    { key: "condition_notes", label: "Condition notes", value: equipment?.condition_notes ?? "" , type: "textarea"},
  ];

  const RISK_FIELDS: EditField[] = [
    { key: "identity_verification_status", label: "Identity verification", value: risk?.identity_verification_status ?? "pending", type: "select", options: ["pending", "verified", "failed"] },
    { key: "employment_verification_status", label: "Employment verification", value: risk?.employment_verification_status ?? "pending", type: "select", options: ["pending", "verified", "failed"] },
    { key: "bank_verification_status", label: "Bank verification (Plaid)", value: risk?.bank_verification_status ?? "pending", type: "select", options: ["pending", "verified", "failed"] },
    { key: "background_check_status", label: "Background check", value: risk?.background_check_status ?? "pending", type: "select", options: ["pending", "clear", "flagged"] },
    { key: "background_check_notes", label: "Notes", value: risk?.background_check_notes ?? "", type: "textarea" },
  ];

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{ background: "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)" }}
      >
        <Link href="/admin/applications" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
          ← All Applications
        </Link>
        <div className="mt-1 flex items-center gap-3">
          <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">{customer?.name ?? "—"}</h1>
          <span className={`font-heading rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ${badge.color}`}>{badge.label}</span>
        </div>
        <p className="text-sm text-neutral-400">
          Outdoor Fix · Application #{application.id} · {new Date(application.created_at).toLocaleDateString()}
        </p>
      </div>

      {status !== "declined" && status !== "withdrawn" && <StatusPipeline status={status} />}

      {status === "submitted" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.submitted!} onPrimary={advance} onDecline={decline} disabled={!can("application_review") || acting} />
          <InfoCallout tone="blue" icon={DocumentIcon} title="New submission" description="Just came in — no review or automated checks have started yet." />
        </>
      )}

      {status === "under_review" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.under_review!} onPrimary={advance} onDecline={decline} disabled={!can("application_review") || acting} />
          <InfoCallout
            tone="green"
            icon={ClockIcon}
            title="Verification in progress"
            description="Automated checks are running."
            items={[
              `Identity check — ${VERIFICATION_LABEL[risk?.identity_verification_status ?? "pending"]}`,
              `Bank verification (Plaid) — ${VERIFICATION_LABEL[risk?.bank_verification_status ?? "pending"]}`,
              `Background check — ${BACKGROUND_LABEL[risk?.background_check_status ?? "pending"]}`,
            ]}
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
            disabled={!can("application_review") || acting}
          />
          <InfoCallout tone="amber" icon={AlertCircleIcon} title="Action required" description={application.status_notes ?? "Missing information — see notes."} />
        </>
      )}

      {status === "approved" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.approved!} onPrimary={advance} onDecline={decline} disabled={!can("application_review") || acting} />
          <InfoCallout tone="blue" icon={CheckCircleIcon} title="Approved — next step" description="Application passed underwriting. Contract not yet sent." items={["Payment schedule generated", "Contract generation pending — send for signature"]} />
        </>
      )}

      {status === "completed" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.completed!} onPrimary={advance} onDecline={decline} disabled={!can("application_review") || acting} />
          <InfoCallout
            tone="teal"
            icon={SettingsIcon}
            title="Equipment delivered"
            description="Unit is with the customer. Final funding step remaining."
            items={["Contract signed", ...(equipment?.delivery_date ? [`Delivered ${equipment.delivery_date}`] : []), "Awaiting first payment to move to Funded"]}
          />
        </>
      )}

      {status === "processed" && (
        <>
          <TakeActionBanner primaryLabel={PRIMARY_LABEL.processed!} onPrimary={advance} onDecline={decline} disabled={!can("application_review") || acting} />
          <InfoCallout tone="purple" icon={CreditCardIcon} title="Processing final payment" description="Payment submitted — waiting on bank confirmation." items={["Contract signed", "ACH payment processing (1–2 business days)"]} />
          {paidPayment && (
            <div className="rounded-xl border border-green-200 bg-green-50 px-5 py-3.5">
              <p className="text-sm font-bold text-green-700">Payment received — {money(num(paidPayment.amount))}</p>
              <p className="text-xs text-neutral-500">{paidPayment.paid_date}</p>
            </div>
          )}
        </>
      )}

      {status === "funded_paid" && (
        <div className="rounded-xl border border-green-200 bg-green-50 px-5 py-3.5">
          <p className="text-sm font-bold text-green-700">
            {paidPayment ? `Payment received — ${money(num(paidPayment.amount))}` : "Lease funded — awaiting first payment"}
          </p>
          {paidPayment && <p className="text-xs text-neutral-500">{paidPayment.paid_date}</p>}
        </div>
      )}

      {status === "declined" && (
        <div className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-bold text-red-700">Application Declined</p>
            <p className="text-xs text-neutral-500">{application.status_notes ?? "Declined per risk policy."}</p>
          </div>
          {can("application_review") && (
            <button
              onClick={async () => setApplication(await updateApplication(application.id, { status: "submitted", status_notes: null }))}
              className="font-heading shrink-0 self-start rounded-md border border-neutral-300 bg-white px-4 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50 sm:self-auto"
            >
              Change Status
            </button>
          )}
        </div>
      )}

      {status === "withdrawn" && (
        <div className="rounded-xl border border-neutral-200 bg-neutral-100 px-5 py-4">
          <p className="text-sm font-bold text-neutral-700">Application Withdrawn</p>
          <p className="text-xs text-neutral-500">{application.status_notes ?? "Customer withdrew the application."}</p>
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
                { label: "Name", value: customer?.name ?? "—" },
                { label: "Email", value: customer?.email ?? "—" },
                { label: "Phone", value: customer?.phone ?? "—" },
                { label: "Mailing address", value: profile?.address_line_1 ?? "—" },
                { label: "City / State / Zip", value: `${profile?.city ?? "—"}, ${profile?.state ?? "—"} ${profile?.zip ?? ""}`.trim() },
                { label: "Date of birth", value: profile?.date_of_birth?.slice(0, 10) ?? "—" },
                { label: "Driver's license #", value: profile?.government_id_number ?? "—" },
                { label: "Residence type", value: RESIDENCE_LABEL[profile?.residence_type ?? ""] ?? "—" },
                { label: "Monthly income", value: profile?.monthly_income ? money(num(profile.monthly_income)) : "—" },
                ...(profile?.government_id_document_path
                  ? [{
                      label: "ID document",
                      value: (
                        <button onClick={() => downloadIdDocument(application.id, `application-${application.id}-id`)} className="text-red-600 hover:underline">
                          Download →
                        </button>
                      ),
                    }]
                  : []),
              ]}
            />
            <DetailCard
              title="Lease terms"
              editable
              canEdit={can("contract_generation")}
              onEdit={() => setEditingCard("lease")}
              rows={
                lease
                  ? [
                      { label: "Term", value: `${lease.term_months} months` },
                      { label: "Monthly rental", value: money(num(lease.monthly_rental_payment)) },
                      { label: `Sales tax @ ${salesTaxPct}%`, value: money(num(lease.sales_tax_amount)) },
                      { label: "Total monthly", value: money(num(lease.total_monthly_payment)) },
                      { label: "Security deposit", value: money(num(lease.security_deposit)) },
                      { label: "Total due", value: money(totalDue) },
                      { label: "AutoPay", value: lease.autopay_enabled ? "Yes" : "No" },
                      { label: "LDW selected", value: lease.ldw_selected ? "Yes" : "No" },
                      { label: "Promo applied", value: lease.promo_code ?? "—" },
                    ]
                  : [{ label: "Lease", value: "Not yet created" }]
              }
            />
            <DetailCard
              title="Equipment unit"
              editable
              canEdit={can("equipment_tracking")}
              onEdit={() => setEditingCard("equipment")}
              rows={[
                { label: "Make / model", value: equipment?.model ?? "—" },
                { label: "Cash price", value: lease ? money(num(lease.cash_price)) : "—" },
                { label: "Condition", value: equipment?.condition_notes ?? "—" },
                { label: "Serial # / VIN", value: equipment?.serial_number ?? "—" },
                { label: "Delivery date", value: equipment?.delivery_date ?? "—" },
                { label: "Expected ownership", value: equipment?.expected_return_or_ownership_date?.slice(0, 10) ?? "—" },
                { label: "Live EPO price", value: <span className="text-red-600">{lease ? money(lease.epo_today) : "—"}</span> },
              ]}
              note="Service history: no records yet · GPS: not tracked (Phase 2)"
            />
            <DetailCard
              title="Risk profile"
              editable
              canEdit={can("risk_assessment")}
              onEdit={() => setEditingCard("risk")}
              rows={[
                { label: "Identity verification", value: <span className={RISK_COLOR[risk?.identity_verification_status ?? "pending"]}>{VERIFICATION_LABEL[risk?.identity_verification_status ?? "pending"]}</span> },
                { label: "Employment verification", value: <span className={RISK_COLOR[risk?.employment_verification_status ?? "pending"]}>{VERIFICATION_LABEL[risk?.employment_verification_status ?? "pending"]}</span> },
                { label: "Bank verification (Plaid)", value: <span className={RISK_COLOR[risk?.bank_verification_status ?? "pending"]}>{VERIFICATION_LABEL[risk?.bank_verification_status ?? "pending"]}</span> },
                { label: "Background check", value: <span className={RISK_COLOR[risk?.background_check_status ?? "pending"]}>{BACKGROUND_LABEL[risk?.background_check_status ?? "pending"]}</span> },
                { label: "Risk score", value: risk?.risk_score != null ? `${risk.risk_score} / 100` : "—" },
                { label: "Residence type", value: RESIDENCE_LABEL[profile?.residence_type ?? ""] ?? "—" },
              ]}
              note={risk?.background_check_notes ?? "No flags on file."}
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
                <span className="font-semibold text-neutral-900">{signed ? customer?.name : "—"}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-neutral-500">Status</span>
                <span className={`font-semibold ${signed ? "text-green-600" : "text-amber-600"}`}>{signed ? "Signed & legally valid" : "Awaiting signature"}</span>
              </div>
            </div>
            <Link href={`/admin/applications/${application.id}/contract`} className="font-heading mt-4 inline-block rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">
              {signed ? "Download signed PDF" : "Send For Signature"}
            </Link>
          </div>
        </div>

        <div className="space-y-6">
          <DealerNotes notes={notes} onAdd={addNote} />
          <ChecklistCard items={checklist.map((c) => ({ ...c, done: c.done || checklistDone }))} onToggle={toggleChecklist} />
          <AssignmentCard salesperson={application.internal_notes?.replace("Sales person: ", "") || "Outdoor Fix"} reviewedBy={application.reviewed_by?.name ?? "—"} />
        </div>
      </div>

      {lease?.epo_schedule && (
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <div className="mb-2 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">EPO payoff schedule</h2>
          </div>
          <p className="mb-6 text-xs text-neutral-400">Early Purchase Option price by month, updated live as payments post. Excludes tax.</p>
          <EpoChart schedule={lease.epo_schedule.filter((p) => p.month === 1 || p.month % 3 === 0)} />
        </div>
      )}

      {editingCard === "customer" && <EditDetailModal title="Customer" fields={CUSTOMER_FIELDS} onSave={saveCustomer} onClose={() => setEditingCard(null)} />}
      {editingCard === "lease" && lease && <EditDetailModal title="Lease terms" fields={LEASE_FIELDS} onSave={saveLease} onClose={() => setEditingCard(null)} />}
      {editingCard === "equipment" && <EditDetailModal title="Equipment unit" fields={EQUIPMENT_FIELDS} onSave={saveEquipment} onClose={() => setEditingCard(null)} />}
      {editingCard === "risk" && <EditDetailModal title="Risk profile" fields={RISK_FIELDS} onSave={saveRisk} onClose={() => setEditingCard(null)} />}
    </div>
  );
}
