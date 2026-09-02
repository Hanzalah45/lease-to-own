"use client";

import { useEffect, useMemo, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { deleteCustomer, listCustomers } from "@/lib/customers";
import { ApiError } from "@/lib/api";
import { Modal } from "@/components/ui/Modal";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { EditCustomerModal } from "@/components/customers/EditCustomerModal";
import { PencilIcon, PlusIcon, SearchIcon, TrashIcon } from "@/components/icons";
import type { AuthUser } from "@/types/auth";

function pct(value: number, total: number): number {
  return total === 0 ? 0 : Math.round((value / total) * 100);
}

export default function AdminCustomersPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const canReview = isSuperAdmin || restrictions.length === 0 || restrictions.includes("application_review");

  const [customers, setCustomers] = useState<AuthUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | "declined">("all");
  const [editing, setEditing] = useState<AuthUser | null>(null);
  const [creating, setCreating] = useState(false);
  const [deletingCustomer, setDeletingCustomer] = useState<AuthUser | null>(null);

  useEffect(() => {
    if (!canReview) return;
    (async () => {
      try {
        setCustomers(await listCustomers());
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Could not load customers.");
      } finally {
        setLoading(false);
      }
    })();
  }, [canReview]);

  const total = customers.length;
  const active = customers.filter((c) => c.status !== "suspended").length;
  const declined = customers.filter((c) => c.status === "suspended").length;

  const filteredCustomers = useMemo(() => {
    const q = search.trim().toLowerCase();
    return customers.filter((c) => {
      const isDeclined = c.status === "suspended";
      if (statusFilter === "declined" && !isDeclined) return false;
      if (!q) return true;
      return c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q);
    });
  }, [customers, search, statusFilter]);

  function handleSaved(saved: AuthUser) {
    setCustomers((prev) => {
      const exists = prev.some((c) => c.id === saved.id);
      return exists ? prev.map((c) => (c.id === saved.id ? saved : c)) : [saved, ...prev];
    });
  }

  if (!canReview) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Customer List</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include application review. Ask a super admin to add the
          Application Review permission to your account.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Customer List"
        subtitle={`Outdoor Fix · Willis — ${total} total`}
        action={
          canReview ? (
            <button
              onClick={() => setCreating(true)}
              className="font-heading flex items-center gap-1.5 self-start rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
            >
              <PlusIcon className="h-4 w-4" />
              Add Customer
            </button>
          ) : undefined
        }
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
            <p className="font-heading text-3xl font-black text-neutral-900">{total}</p>
            <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">Total customers</p>
            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
              <div className="h-full rounded-full bg-neutral-900" style={{ width: "100%" }} />
            </div>
          </div>
          <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
            <p className="font-heading text-3xl font-black text-green-600">{active}</p>
            <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">Active</p>
            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
              <div className="h-full rounded-full bg-green-600" style={{ width: `${pct(active, total)}%` }} />
            </div>
          </div>
          <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
            <p className="font-heading text-3xl font-black text-red-600">{declined}</p>
            <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">Declined</p>
            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
              <div className="h-full rounded-full bg-red-600" style={{ width: `${pct(declined, total)}%` }} />
            </div>
          </div>
        </div>
      </PageHeroHeader>

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">All customers</h2>
        </div>

        <div className="relative mb-4">
          <SearchIcon className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name or email..."
            className="w-full max-w-sm rounded-md border border-neutral-200 py-2 pl-10 pr-3 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none"
          />
        </div>

        <div className="mb-4 flex gap-2">
          <button
            onClick={() => setStatusFilter("all")}
            className={`font-heading rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
              statusFilter === "all" ? "bg-red-600 text-white" : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
            }`}
          >
            All
          </button>
          <button
            onClick={() => setStatusFilter("declined")}
            className={`font-heading flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
              statusFilter === "declined" ? "bg-red-600 text-white" : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
            }`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${statusFilter === "declined" ? "bg-white" : "bg-red-500"}`} />
            Declined
          </button>
        </div>

        {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

        {loading ? (
          <p className="py-6 text-sm text-neutral-500">Loading…</p>
        ) : (
          <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <table className="w-full min-w-[640px] text-left text-sm">
              <thead>
                <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                  <th className="pb-2 font-medium">Customer</th>
                  <th className="pb-2 font-medium">Email</th>
                  <th className="pb-2 font-medium">Joined</th>
                  <th className="pb-2 font-medium">Status</th>
                  <th className="pb-2 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredCustomers.map((customer) => {
                  const isDeclined = customer.status === "suspended";
                  return (
                    <tr key={customer.id} className="border-b border-neutral-100 last:border-0">
                      <td className="py-3 font-medium text-neutral-900">{customer.name}</td>
                      <td className="py-3 text-neutral-500">{customer.email}</td>
                      <td className="py-3 text-neutral-500">
                        {customer.created_at ? new Date(customer.created_at).toLocaleDateString() : "—"}
                      </td>
                      <td className="py-3">
                        <span className="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-neutral-700">
                          <span className={`h-1.5 w-1.5 rounded-full ${isDeclined ? "bg-red-500" : "bg-green-500"}`} />
                          {isDeclined ? "Declined" : "Accepted"}
                        </span>
                      </td>
                      <td className="py-3">
                        <div className="flex gap-1">
                          <button
                            onClick={() => setEditing(customer)}
                            title="Edit"
                            className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                          >
                            <PencilIcon className="h-4 w-4" />
                          </button>
                          {canReview && (
                            <button
                              onClick={() => setDeletingCustomer(customer)}
                              title="Delete"
                              className="rounded p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600"
                            >
                              <TrashIcon className="h-4 w-4" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
                {filteredCustomers.length === 0 && (
                  <tr>
                    <td colSpan={5} className="py-6 text-center text-sm text-neutral-400">
                      No customers match this search.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {editing && <EditCustomerModal customer={editing} onClose={() => setEditing(null)} onSaved={handleSaved} />}
      {creating && <EditCustomerModal onClose={() => setCreating(false)} onSaved={handleSaved} />}

      {deletingCustomer && (
        <Modal title="Delete customer" onClose={() => setDeletingCustomer(null)} maxWidthClassName="max-w-sm">
          <DeleteCustomerConfirm
            customer={deletingCustomer}
            onCancel={() => setDeletingCustomer(null)}
            onDeleted={() => {
              setCustomers((prev) => prev.filter((c) => c.id !== deletingCustomer.id));
              setDeletingCustomer(null);
            }}
          />
        </Modal>
      )}
    </div>
  );
}

function DeleteCustomerConfirm({
  customer,
  onCancel,
  onDeleted,
}: {
  customer: AuthUser;
  onCancel: () => void;
  onDeleted: () => void;
}) {
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleDelete() {
    setError(null);
    setSubmitting(true);
    try {
      await deleteCustomer(customer.id);
      onDeleted();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete this customer.");
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      {error && <p className="text-sm text-red-600">{error}</p>}
      <p className="text-sm text-neutral-600">
        Remove <span className="font-semibold text-neutral-900">{customer.name}</span> ({customer.email}) from the
        active directory? This is a soft delete — the record is kept and can be restored later, it just disappears
        from this list.
      </p>
      <div className="flex justify-end gap-2">
        <button
          onClick={onCancel}
          className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
        >
          Cancel
        </button>
        <button
          onClick={handleDelete}
          disabled={submitting}
          className="font-heading flex items-center gap-1.5 rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
        >
          <TrashIcon className="h-4 w-4" />
          {submitting ? "Deleting…" : "Delete"}
        </button>
      </div>
    </div>
  );
}
