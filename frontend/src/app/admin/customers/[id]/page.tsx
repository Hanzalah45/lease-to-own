"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { getCustomer, type CustomerDetail } from "@/lib/customers";
import { downloadIdDocument, downloadInfoRequestDocument, downloadUtilityBill } from "@/lib/applications";
import { ApiError } from "@/lib/api";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { DetailCard } from "@/components/applications/detail/DetailCard";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { InfoRequestTimeline } from "@/components/applications/detail/InfoRequestTimeline";
import { money } from "@/components/applications/wizard/types";
import type { Application, ApplicationStatus } from "@/types/application";

const RESIDENCE_LABEL: Record<string, string> = { house: "House", apartment: "Apartment", other: "Other" };
const VERIFICATION_LABEL: Record<string, string> = { pending: "Pending", verified: "Verified", failed: "Failed" };

const STATUS_STYLE: Record<ApplicationStatus, { color: string; label: string }> = {
  waiting_review: { color: "#404040", label: "Waiting review" },
  needs_info: { color: "#D97706", label: "Needs info" },
  waiting_approval: { color: "#D97706", label: "Waiting approval" },
  in_verification: { color: "#2563EB", label: "In verification" },
  waiting_deposit: { color: "#0D9488", label: "Waiting deposit" },
  waiting_delivery: { color: "#7C3AED", label: "Waiting delivery" },
  finished: { color: "#16A34A", label: "Finished" },
  declined: { color: "#DC2626", label: "Declined" },
  withdrawn: { color: "#A3A3A3", label: "Withdrawn" },
};

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function AdminCustomerDetailPage() {
  const params = useParams<{ id: string }>();
  const [customer, setCustomer] = useState<CustomerDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        setCustomer(await getCustomer(Number(params.id)));
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Could not load this customer.");
      } finally {
        setLoading(false);
      }
    })();
  }, [params.id]);

  if (loading) return <p className="text-sm text-neutral-500">Loading…</p>;
  if (error) return <p className="text-sm text-red-600">{error}</p>;
  if (!customer) return null;

  const profile = customer.customer_profile;
  const risk = customer.risk_profile;
  const applications = customer.applications ?? [];
  // The download endpoints read straight off customer_profile, so it doesn't
  // matter which application's id fronts the request — the most recent one
  // is just the least likely to 404 on an old, since-superseded record.
  const documentApplicationId = applications[0]?.id;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/admin/customers" className="text-xs font-semibold text-neutral-400 hover:text-neutral-700">
          ← Back to customers
        </Link>
        <h1 className="mt-1 text-2xl font-bold uppercase tracking-tight">{customer.name}</h1>
        <p className="text-sm text-neutral-500">{customer.email}</p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <DetailCard
          title="Profile"
          rows={[
            { label: "Phone", value: customer.phone ?? "—" },
            { label: "Account status", value: customer.status === "active" ? "Active" : customer.status === "suspended" ? "Suspended" : "Not set up yet" },
            { label: "Date of birth", value: profile?.date_of_birth?.slice(0, 10) ?? "—" },
            { label: "Residence type", value: profile?.residence_type ? RESIDENCE_LABEL[profile.residence_type] : "—" },
            { label: "Mailing address", value: profile?.address_line_1 ?? "—" },
            { label: "City / State / Zip", value: `${profile?.city ?? "—"}, ${profile?.state ?? "—"} ${profile?.zip ?? ""}`.trim() },
            { label: "Employer", value: profile?.employer_name ?? "—" },
            { label: "Employer phone", value: profile?.employer_phone ?? "—" },
            { label: "Monthly income", value: profile?.monthly_income ? money(num(profile.monthly_income)) : "—" },
            { label: "Landlord", value: profile?.landlord_name ? `${profile.landlord_name} · ${profile.landlord_phone ?? "—"}` : "—" },
            { label: "Alt. contact 1", value: profile?.alternate_contact_1_name ? `${profile.alternate_contact_1_name} · ${profile.alternate_contact_1_phone ?? "—"}` : "—" },
            { label: "Alt. contact 2", value: profile?.alternate_contact_2_name ? `${profile.alternate_contact_2_name} · ${profile.alternate_contact_2_phone ?? "—"}` : "—" },
            ...(profile?.government_id_document_path && documentApplicationId
              ? [{
                  label: "ID document",
                  value: (
                    <button onClick={() => downloadIdDocument(documentApplicationId, `${customer.name}-id`)} className="text-red-600 hover:underline">
                      Download →
                    </button>
                  ),
                }]
              : []),
            ...(profile?.utility_bill_document_path && documentApplicationId
              ? [{
                  label: "Utility bill",
                  value: (
                    <button onClick={() => downloadUtilityBill(documentApplicationId, `${customer.name}-utility-bill`)} className="text-red-600 hover:underline">
                      Download →
                    </button>
                  ),
                }]
              : []),
          ]}
        />

        <DetailCard
          title="Risk profile"
          rows={
            risk
              ? [
                  { label: "Identity verification", value: VERIFICATION_LABEL[risk.identity_verification_status] },
                  { label: "Employment verification", value: VERIFICATION_LABEL[risk.employment_verification_status] },
                  { label: "Bank verification (Plaid)", value: VERIFICATION_LABEL[risk.bank_verification_status] },
                  { label: "Background check", value: risk.background_check_status },
                  { label: "Risk score", value: risk.risk_score != null ? `${risk.risk_score} / 100` : "—" },
                  { label: "Open red flags", value: risk.red_flags?.filter((f) => !f.resolved).length ?? 0 },
                ]
              : [{ label: "Status", value: "Not assessed yet" }]
          }
        />
      </div>

      <div>
        <SectionHeading title="Applications" subtitle={applications.length ? `${applications.length} on file` : undefined} />
      </div>

      {applications.length === 0 && (
        <p className="rounded-xl border border-neutral-200 bg-white p-5 text-sm text-neutral-400">
          This customer has no applications yet.
        </p>
      )}

      {applications.map((application) => (
        <ApplicationBlock key={application.id} application={application} />
      ))}
    </div>
  );
}

function ApplicationBlock({ application }: { application: Application }) {
  const lease = application.lease_agreement;
  const equipment = lease?.equipment_unit;
  const contract = lease?.contract;
  const signed = !!contract;
  const badge = STATUS_STYLE[application.status];
  const payments = lease?.payments ?? [];
  const collected = payments.filter((p) => p.status === "paid").reduce((sum, p) => sum + num(p.amount), 0);
  const nextDue = payments
    .filter((p) => p.status === "pending")
    .sort((a, b) => new Date(a.due_date).getTime() - new Date(b.due_date).getTime())[0];

  return (
    <div className="space-y-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-3">
          <StatusTag color={badge.color} label={badge.label} />
          <span className="text-sm text-neutral-500">
            Application #{application.id} · {new Date(application.created_at).toLocaleDateString()}
          </span>
        </div>
        <Link href={`/admin/applications/${application.id}`} className="text-sm font-semibold text-red-600 hover:underline">
          Manage this application →
        </Link>
      </div>

      {!lease ? (
        <p className="text-sm text-neutral-500">No equipment or pricing attached yet.</p>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <DetailCard
            title="Lease terms"
            rows={[
              { label: "Term", value: `${lease.term_months} months` },
              { label: "Monthly rental", value: money(lease.total_monthly_payment) },
              { label: "Security deposit", value: money(num(lease.security_deposit)) },
              { label: "AutoPay", value: lease.autopay_enabled ? "On" : "Off" },
            ]}
          />
          <DetailCard
            title="Equipment"
            rows={[
              { label: "Make / model", value: equipment?.model ?? "—" },
              { label: "Serial # / VIN", value: equipment?.serial_number ?? "—" },
              { label: "Delivery date", value: equipment?.delivery_date?.slice(0, 10) ?? "Not yet delivered" },
              { label: "GPS device serial", value: equipment?.gps_device_id ?? "—" },
            ]}
          />
          <DetailCard
            title="Contract & payments"
            rows={[
              { label: "Signature", value: signed ? `Signed ${new Date(contract.signed_at).toLocaleDateString()}` : "Awaiting signature" },
              { label: "Payments made", value: `${lease.payments_made} of ${lease.term_months}` },
              { label: "Collected to date", value: money(collected) },
              { label: "Next due", value: nextDue ? `${money(num(nextDue.amount))} on ${new Date(nextDue.due_date).toLocaleDateString()}` : "—" },
            ]}
            note={
              <Link href={`/admin/applications/${application.id}/contract`} className="font-semibold text-red-600 hover:underline">
                {signed ? "View / download contract →" : "View lease terms →"}
              </Link>
            }
          />
        </div>
      )}

      <InfoRequestTimeline
        requests={application.info_requests ?? []}
        onDownloadDocument={(infoRequestId) => downloadInfoRequestDocument(application.id, infoRequestId, `application-${application.id}-id-r${infoRequestId}`)}
      />
    </div>
  );
}
