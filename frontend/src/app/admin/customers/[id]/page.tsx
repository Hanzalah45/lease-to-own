"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { getCustomer, type CustomerDetail } from "@/lib/customers";
import { ApiError } from "@/lib/api";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

function Field({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <div>
      <p className="text-xs font-semibold uppercase tracking-wide text-neutral-400">{label}</p>
      <p className="mt-0.5 text-sm text-neutral-800">{value || "—"}</p>
    </div>
  );
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

  return (
    <div className="space-y-6">
      <div>
        <Link href="/admin/customers" className="text-xs font-semibold text-neutral-400 hover:text-neutral-700">
          ← Back to customers
        </Link>
        <h1 className="mt-1 text-2xl font-bold uppercase tracking-tight">{customer.name}</h1>
        <p className="text-sm text-neutral-500">{customer.email}</p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <SectionHeading title="Profile" />
        <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
          <Field label="Phone" value={customer.phone} />
          <Field label="Status" value={customer.status} />
          <Field label="Residence type" value={profile?.residence_type} />
          <Field label="Address" value={profile?.address_line_1} />
          <Field label="City" value={profile?.city} />
          <Field label="State" value={profile?.state} />
          <Field label="ZIP" value={profile?.zip} />
          <Field label="Landlord name" value={profile?.landlord_name} />
          <Field label="Landlord phone" value={profile?.landlord_phone} />
          <Field label="Employer" value={profile?.employer_name} />
          <Field label="Employment status" value={profile?.employment_status} />
          <Field label="Government ID type" value={profile?.government_id_type} />
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <SectionHeading title="Applications" subtitle="Milestone 5 — application workflow" />
        <p className="mt-4 text-sm text-neutral-400">
          {customer.applications?.length ? `${customer.applications.length} on file` : "None yet."}
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <SectionHeading title="Lease agreements" subtitle="Milestone 2 — lease & ownership engine" />
        <p className="mt-4 text-sm text-neutral-400">
          {customer.lease_agreements?.length ? `${customer.lease_agreements.length} on file` : "None yet."}
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <SectionHeading title="Risk profile" subtitle="Milestone 3 — risk assessment engine" />
        <p className="mt-4 text-sm text-neutral-400">
          {customer.risk_profile ? "On file" : "Not assessed yet."}
        </p>
      </div>
    </div>
  );
}
