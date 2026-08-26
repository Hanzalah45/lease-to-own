"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { listCustomers } from "@/lib/customers";
import { ApiError } from "@/lib/api";
import { UserIcon } from "@/components/icons";
import type { AuthUser } from "@/types/auth";

const STATUS_BADGE: Record<string, string> = {
  active: "bg-green-50 text-green-700",
  suspended: "bg-red-50 text-red-700",
  pending: "bg-amber-50 text-amber-700",
};

export default function AdminCustomersPage() {
  const [customers, setCustomers] = useState<AuthUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        setCustomers(await listCustomers());
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Could not load customers.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  return (
    <div>
      <div>
        <h1 className="text-2xl font-bold uppercase tracking-tight">Customers</h1>
        <p className="mt-1 text-sm text-neutral-500">
          Everyone who has registered a customer account. Applications, leases, and risk details are still being
          built (Milestones 2–3) — this is the directory view.
        </p>
      </div>

      {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

      {loading ? (
        <p className="mt-6 text-sm text-neutral-500">Loading…</p>
      ) : customers.length === 0 ? (
        <p className="mt-6 text-sm text-neutral-400">No customers have registered yet.</p>
      ) : (
        <div className="mt-6 overflow-hidden rounded-xl border border-neutral-200">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="font-heading border-b border-neutral-200 bg-neutral-50 text-xs font-bold uppercase tracking-wide text-neutral-400">
                <th className="py-2.5 pl-4 font-medium">Customer</th>
                <th className="py-2.5 font-medium">Phone</th>
                <th className="py-2.5 font-medium">Residence</th>
                <th className="py-2.5 font-medium">Status</th>
                <th className="py-2.5 pr-4 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {customers.map((customer) => (
                <tr key={customer.id} className="border-b border-neutral-100 last:border-0">
                  <td className="py-3 pl-4">
                    <div className="flex items-center gap-3">
                      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
                        <UserIcon className="h-4 w-4" />
                      </span>
                      <div>
                        <p className="font-medium text-neutral-900">{customer.name}</p>
                        <p className="text-xs text-neutral-400">{customer.email}</p>
                      </div>
                    </div>
                  </td>
                  <td className="py-3 text-neutral-600">{customer.phone ?? "—"}</td>
                  <td className="py-3 capitalize text-neutral-600">
                    {customer.customer_profile?.residence_type ?? "Not provided"}
                  </td>
                  <td className="py-3">
                    <span
                      className={`font-heading inline-block rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${STATUS_BADGE[customer.status]}`}
                    >
                      {customer.status}
                    </span>
                  </td>
                  <td className="py-3 pr-4 text-right">
                    <Link
                      href={`/admin/customers/${customer.id}`}
                      className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline"
                    >
                      View
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
