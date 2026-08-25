"use client";

import { useEffect, useState, type FormEvent } from "react";
import { ADMIN_PERMISSIONS, createAdmin, listAdmins } from "@/lib/admin-users";
import { ApiError } from "@/lib/api";
import type { AdminPermissionKey, AuthUser } from "@/types/auth";

export default function AdminUsersPage() {
  const [admins, setAdmins] = useState<AuthUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);

  async function load() {
    setLoading(true);
    try {
      setAdmins(await listAdmins());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load admins.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Admin users</h1>
        <button
          onClick={() => setShowForm((v) => !v)}
          className="font-heading rounded-md bg-neutral-900 px-3 py-2 text-sm font-bold uppercase tracking-wide text-white"
        >
          {showForm ? "Cancel" : "New admin"}
        </button>
      </div>

      {showForm && (
        <NewAdminForm
          onCreated={() => {
            setShowForm(false);
            load();
          }}
        />
      )}

      {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

      {loading ? (
        <p className="mt-6 text-sm text-neutral-500">Loading…</p>
      ) : (
        <table className="mt-6 w-full text-left text-sm">
          <thead>
            <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
              <th className="py-2 font-medium">Name</th>
              <th className="py-2 font-medium">Email</th>
              <th className="py-2 font-medium">Status</th>
              <th className="py-2 font-medium">Permissions</th>
            </tr>
          </thead>
          <tbody>
            {admins.map((admin) => (
              <tr key={admin.id} className="border-b border-neutral-100">
                <td className="py-2">{admin.name}</td>
                <td className="py-2">{admin.email}</td>
                <td className="py-2 capitalize">{admin.status}</td>
                <td className="py-2">
                  {admin.admin_permissions?.map((p) => p.permission).join(", ") || "—"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function NewAdminForm({ onCreated }: { onCreated: () => void }) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [permissions, setPermissions] = useState<AdminPermissionKey[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function togglePermission(key: AdminPermissionKey) {
    setPermissions((prev) => (prev.includes(key) ? prev.filter((p) => p !== key) : [...prev, key]));
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await createAdmin({ name, email, password, permissions });
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not create admin.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="mt-4 max-w-md space-y-3 rounded-md border border-neutral-200 p-4">
      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="space-y-1">
        <label className="font-heading text-sm font-semibold">Name</label>
        <input
          required
          value={name}
          onChange={(e) => setName(e.target.value)}
          className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
        />
      </div>

      <div className="space-y-1">
        <label className="font-heading text-sm font-semibold">Email</label>
        <input
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
        />
      </div>

      <div className="space-y-1">
        <label className="font-heading text-sm font-semibold">Temporary password</label>
        <input
          type="password"
          required
          minLength={8}
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
        />
      </div>

      <div className="space-y-1">
        <span className="font-heading text-sm font-semibold">Permissions</span>
        <div className="space-y-1">
          {ADMIN_PERMISSIONS.map((p) => (
            <label key={p.key} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={permissions.includes(p.key)}
                onChange={() => togglePermission(p.key)}
              />
              {p.label}
            </label>
          ))}
        </div>
      </div>

      <button
        type="submit"
        disabled={submitting}
        className="font-heading w-full rounded-md bg-neutral-900 px-4 py-2 text-sm font-bold uppercase tracking-wide text-white disabled:opacity-50"
      >
        {submitting ? "Creating…" : "Create admin"}
      </button>
    </form>
  );
}
