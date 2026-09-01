"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import { ADMIN_PERMISSIONS, createAdmin, deleteAdmin, listAdmins, updateAdmin } from "@/lib/admin-users";
import { ApiError } from "@/lib/api";
import { Modal } from "@/components/ui/Modal";
import { PencilIcon, PlusIcon, TrashIcon, UserIcon } from "@/components/icons";
import { validateEmail, validateName, validatePassword } from "@/lib/validation";
import type { AdminPermissionKey, AuthUser, UserStatus } from "@/types/auth";

const STATUS_BADGE: Record<UserStatus, string> = {
  active: "bg-green-50 text-green-700",
  suspended: "bg-red-50 text-red-700",
  pending: "bg-amber-50 text-amber-700",
};

export default function AdminUsersPage() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [admins, setAdmins] = useState<AuthUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<AuthUser | null>(null);
  const [deleting, setDeleting] = useState<AuthUser | null>(null);

  // Managing admin accounts is super_admin only — the API already enforces
  // this, this just avoids showing a broken page to a regular admin.
  useEffect(() => {
    if (!authLoading && user && user.role !== "super_admin") {
      router.replace("/admin/dashboard");
    }
  }, [authLoading, user, router]);

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

  if (!authLoading && user && user.role !== "super_admin") {
    return null;
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold uppercase tracking-tight">Admin users</h1>
          <p className="mt-1 text-sm text-neutral-500">
            Every admin has full access by default. Restrict one to specific areas if needed.
          </p>
        </div>
        <button
          onClick={() => setShowCreate(true)}
          className="font-heading flex items-center gap-1.5 rounded-md bg-neutral-900 px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-white hover:bg-neutral-800"
        >
          <PlusIcon className="h-4 w-4" />
          New admin
        </button>
      </div>

      {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

      {loading ? (
        <p className="mt-6 text-sm text-neutral-500">Loading…</p>
      ) : admins.length === 0 ? (
        <p className="mt-6 text-sm text-neutral-400">No admins yet — create one above.</p>
      ) : (
        <div className="mt-6 overflow-hidden rounded-xl border border-neutral-200">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="font-heading border-b border-neutral-200 bg-neutral-50 text-xs font-bold uppercase tracking-wide text-neutral-400">
                <th className="py-2.5 pl-4 font-medium">Admin</th>
                <th className="py-2.5 font-medium">Status</th>
                <th className="py-2.5 font-medium">Access</th>
                <th className="py-2.5 pr-4 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {admins.map((admin) => (
                <tr key={admin.id} className="border-b border-neutral-100 last:border-0">
                  <td className="py-3 pl-4">
                    <div className="flex items-center gap-3">
                      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
                        <UserIcon className="h-4 w-4" />
                      </span>
                      <div>
                        <p className="font-medium text-neutral-900">{admin.name}</p>
                        <p className="text-xs text-neutral-400">{admin.email}</p>
                      </div>
                    </div>
                  </td>
                  <td className="py-3">
                    <span
                      className={`font-heading inline-block rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${STATUS_BADGE[admin.status]}`}
                    >
                      {admin.status}
                    </span>
                  </td>
                  <td className="py-3">
                    {admin.admin_permissions && admin.admin_permissions.length > 0 ? (
                      <span className="text-neutral-700">
                        Restricted: {admin.admin_permissions.map((p) => p.permission.replace(/_/g, " ")).join(", ")}
                      </span>
                    ) : (
                      <span className="font-medium text-green-700">Full access</span>
                    )}
                  </td>
                  <td className="py-3 pr-4">
                    <div className="flex justify-end gap-1.5">
                      <button
                        onClick={() => setEditing(admin)}
                        title="Edit admin"
                        className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                      >
                        <PencilIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setDeleting(admin)}
                        title="Remove admin"
                        className="rounded p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600"
                      >
                        <TrashIcon className="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showCreate && (
        <Modal title="New admin" onClose={() => setShowCreate(false)}>
          <AdminForm
            onSaved={() => {
              setShowCreate(false);
              load();
            }}
            onCancel={() => setShowCreate(false)}
          />
        </Modal>
      )}

      {editing && (
        <Modal title={`Edit — ${editing.name}`} onClose={() => setEditing(null)}>
          <AdminForm
            admin={editing}
            onSaved={() => {
              setEditing(null);
              load();
            }}
            onCancel={() => setEditing(null)}
          />
        </Modal>
      )}

      {deleting && (
        <Modal title="Remove admin" onClose={() => setDeleting(null)} maxWidthClassName="max-w-sm">
          <DeleteConfirm
            admin={deleting}
            onCancel={() => setDeleting(null)}
            onDeleted={() => {
              setDeleting(null);
              load();
            }}
          />
        </Modal>
      )}
    </div>
  );
}

function DeleteConfirm({
  admin,
  onCancel,
  onDeleted,
}: {
  admin: AuthUser;
  onCancel: () => void;
  onDeleted: () => void;
}) {
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleDelete() {
    setError(null);
    setSubmitting(true);
    try {
      await deleteAdmin(admin.id);
      onDeleted();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove admin.");
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      {error && <p className="text-sm text-red-600">{error}</p>}
      <p className="text-sm text-neutral-600">
        Remove <span className="font-semibold text-neutral-900">{admin.name}</span> (
        {admin.email})? They immediately lose access — this can&apos;t be undone.
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
          {submitting ? "Removing…" : "Remove"}
        </button>
      </div>
    </div>
  );
}

function AdminForm({
  admin,
  onSaved,
  onCancel,
}: {
  admin?: AuthUser;
  onSaved: () => void;
  onCancel: () => void;
}) {
  const isEdit = !!admin;
  const [name, setName] = useState(admin?.name ?? "");
  const [email, setEmail] = useState(admin?.email ?? "");
  const [password, setPassword] = useState("");
  const [status, setStatus] = useState<UserStatus>(admin?.status ?? "active");
  const [permissions, setPermissions] = useState<AdminPermissionKey[]>(
    admin?.admin_permissions?.map((p) => p.permission) ?? [],
  );
  const [error, setError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitting, setSubmitting] = useState(false);

  function touch(key: string) {
    setTouched((prev) => (prev[key] ? prev : { ...prev, [key]: true }));
  }

  function clearServerError(key: string) {
    setServerErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  }

  function togglePermission(key: AdminPermissionKey) {
    setPermissions((prev) => (prev.includes(key) ? prev.filter((p) => p !== key) : [...prev, key]));
  }

  const clientErrors: Record<string, string> = {};
  const nameErr = validateName(name, "Name");
  if (nameErr) clientErrors.name = nameErr;
  if (!isEdit) {
    const emailErr = validateEmail(email);
    if (emailErr) clientErrors.email = emailErr;
    const passwordErr = validatePassword(password, true);
    if (passwordErr) clientErrors.password = passwordErr;
  }

  const isValid = Object.keys(clientErrors).length === 0;

  function fieldError(key: string): string | undefined {
    return serverErrors[key] ?? (touched[key] ? clientErrors[key] : undefined);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return; // guard against a double-click/double-Enter race
    setError(null);

    if (!isValid) {
      setTouched({ name: true, email: true, password: true });
      return;
    }

    setSubmitting(true);
    try {
      if (isEdit) {
        await updateAdmin(admin.id, { name, status, permissions });
      } else {
        await createAdmin({ name, email, password, permissions });
      }
      onSaved();
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        // Each message is already shown inline under its field — no need
        // to repeat it in a summary banner too.
        setServerErrors(
          Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])),
        );
      } else {
        setError(err instanceof ApiError ? err.message : "Could not save admin.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  const inputClass = (hasError: boolean) =>
    `w-full rounded-md border px-3 py-2 text-sm focus:outline-none focus:ring-1 disabled:bg-neutral-50 disabled:text-neutral-400 ${
      hasError ? "border-red-400 focus:border-red-600 focus:ring-red-600" : "border-neutral-300 focus:border-red-600 focus:ring-red-600"
    }`;

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-4">
      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="space-y-1">
        <label htmlFor="admin-name" className="font-heading text-sm font-semibold">
          Name<span className="ml-0.5 text-red-600">*</span>
        </label>
        <input
          id="admin-name"
          required
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            clearServerError("name");
          }}
          onBlur={() => touch("name")}
          aria-invalid={!!fieldError("name")}
          aria-describedby={fieldError("name") ? "admin-name-error" : undefined}
          className={inputClass(!!fieldError("name"))}
        />
        {fieldError("name") && (
          <p id="admin-name-error" className="text-xs font-medium text-red-600">
            {fieldError("name")}
          </p>
        )}
      </div>

      <div className="space-y-1">
        <label htmlFor="admin-email" className="font-heading text-sm font-semibold">
          Email{!isEdit && <span className="ml-0.5 text-red-600">*</span>}
        </label>
        <input
          id="admin-email"
          type="email"
          required={!isEdit}
          disabled={isEdit}
          value={email}
          onChange={(e) => {
            setEmail(e.target.value);
            clearServerError("email");
          }}
          onBlur={() => touch("email")}
          aria-invalid={!!fieldError("email")}
          aria-describedby={fieldError("email") ? "admin-email-error" : undefined}
          className={inputClass(!!fieldError("email"))}
        />
        {fieldError("email") && (
          <p id="admin-email-error" className="text-xs font-medium text-red-600">
            {fieldError("email")}
          </p>
        )}
        {!isEdit && (
          <p className="text-xs text-neutral-400">
            Credentials go to this address once notifications are wired up. For now, share the password directly.
          </p>
        )}
      </div>

      {isEdit ? (
        <div className="space-y-1">
          <label htmlFor="admin-status" className="font-heading text-sm font-semibold">
            Status
          </label>
          <select
            id="admin-status"
            value={status}
            onChange={(e) => setStatus(e.target.value as UserStatus)}
            className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-red-600 focus:outline-none focus:ring-1 focus:ring-red-600"
          >
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Pending</option>
          </select>
        </div>
      ) : (
        <div className="space-y-1">
          <label htmlFor="admin-password" className="font-heading text-sm font-semibold">
            Temporary password<span className="ml-0.5 text-red-600">*</span>
          </label>
          <input
            id="admin-password"
            type="password"
            required
            minLength={8}
            placeholder="Letter + number, 8+ chars"
            value={password}
            onChange={(e) => {
              setPassword(e.target.value);
              clearServerError("password");
            }}
            onBlur={() => touch("password")}
            aria-invalid={!!fieldError("password")}
            aria-describedby={fieldError("password") ? "admin-password-error" : undefined}
            className={inputClass(!!fieldError("password"))}
          />
          {fieldError("password") && (
            <p id="admin-password-error" className="text-xs font-medium text-red-600">
              {fieldError("password")}
            </p>
          )}
        </div>
      )}

      <div className="space-y-1">
        <span className="font-heading text-sm font-semibold">Restrict access (optional)</span>
        <p className="text-xs text-neutral-400">
          Leave everything unchecked for full access. Check specific areas to limit this admin to only those.
        </p>
        <div className="mt-1 space-y-1 rounded-md border border-neutral-200 p-3">
          {ADMIN_PERMISSIONS.map((p) => (
            <label key={p.key} className="flex items-center gap-2 text-sm text-neutral-700">
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

      <div className="flex justify-end gap-2 pt-1">
        <button
          type="button"
          onClick={onCancel}
          disabled={submitting}
          className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Cancel
        </button>
        <button
          type="submit"
          disabled={submitting || !isValid}
          className="font-heading rounded-md bg-neutral-900 px-4 py-2 text-sm font-bold uppercase tracking-wide text-white hover:bg-neutral-800 disabled:opacity-50"
        >
          {submitting ? "Saving…" : isEdit ? "Save changes" : "Create admin"}
        </button>
      </div>
    </form>
  );
}
