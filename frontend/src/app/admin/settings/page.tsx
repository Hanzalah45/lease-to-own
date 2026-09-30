"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import { disconnectQuickbooks, getQuickbooksStatus, startQuickbooksConnect, type QuickbooksStatus } from "@/lib/quickbooks";
import { Modal } from "@/components/ui/Modal";
import { AlertCircleIcon, CheckCircleIcon, DollarIcon, TrashIcon } from "@/components/icons";

const CALLBACK_BANNER: Record<string, { tone: "success" | "error"; message: string }> = {
  connected: { tone: "success", message: "QuickBooks connected successfully." },
  denied: { tone: "error", message: "The QuickBooks connection was cancelled or denied." },
  invalid_state: { tone: "error", message: "That connection attempt expired or was invalid. Please try again." },
  exchange_failed: { tone: "error", message: "QuickBooks did not accept the connection. Please try again." },
};

export default function AdminSettingsPage() {
  return (
    <Suspense fallback={null}>
      <AdminSettingsContent />
    </Suspense>
  );
}

function AdminSettingsContent() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();

  const [status, setStatus] = useState<QuickbooksStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [connecting, setConnecting] = useState(false);
  const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);

  // Settings (QuickBooks connection, etc.) is super_admin only — the API
  // already enforces this, this just avoids showing a broken page to a
  // regular admin. Same pattern as /admin/admin-users.
  useEffect(() => {
    if (!authLoading && user && user.role !== "super_admin") {
      router.replace("/admin/dashboard");
    }
  }, [authLoading, user, router]);

  async function load() {
    setLoading(true);
    try {
      setStatus(await getQuickbooksStatus());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load QuickBooks status.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  // Captured into state (not read live from searchParams) because the URL is
  // stripped right after — reading it live would make the banner vanish the
  // instant router.replace() re-renders this page without the query param.
  const [banner, setBanner] = useState<{ tone: "success" | "error"; message: string } | undefined>(undefined);
  useEffect(() => {
    const result = searchParams.get("quickbooks");
    if (result && CALLBACK_BANNER[result]) {
      setBanner(CALLBACK_BANNER[result]);
      router.replace("/admin/settings");
    }
    // Only ever meant to run once, right after landing back from the OAuth
    // callback redirect — not on every searchParams/router identity change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleConnect() {
    setError(null);
    setConnecting(true);
    try {
      const url = await startQuickbooksConnect();
      window.location.href = url;
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not start the QuickBooks connection.");
      setConnecting(false);
    }
  }

  async function handleDisconnect() {
    setError(null);
    setDisconnecting(true);
    try {
      await disconnectQuickbooks();
      setConfirmingDisconnect(false);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not disconnect QuickBooks.");
    } finally {
      setDisconnecting(false);
    }
  }

  if (!authLoading && user && user.role !== "super_admin") {
    return null;
  }

  return (
    <div>
      <div>
        <h1 className="text-2xl font-bold uppercase tracking-tight">Settings</h1>
        <p className="mt-1 text-sm text-neutral-500">Company-wide integrations and connections.</p>
      </div>

      {banner && (
        <div
          className={`mt-4 flex items-center gap-2 rounded-md border px-3.5 py-2.5 text-sm font-medium ${
            banner.tone === "success"
              ? "border-green-200 bg-green-50 text-green-700"
              : "border-red-200 bg-red-50 text-red-700"
          }`}
        >
          {banner.tone === "success" ? (
            <CheckCircleIcon className="h-4 w-4 shrink-0" />
          ) : (
            <AlertCircleIcon className="h-4 w-4 shrink-0" />
          )}
          {banner.message}
        </div>
      )}

      {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

      <div className="mt-6 max-w-xl rounded-xl border border-neutral-200 p-5">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
            <DollarIcon className="h-5 w-5" />
          </span>
          <div className="flex-1">
            <h2 className="font-heading text-base font-bold">QuickBooks Online</h2>
            <p className="mt-0.5 text-sm text-neutral-500">
              Connects the company&apos;s QuickBooks account so payment records can sync automatically.
            </p>

            {loading ? (
              <p className="mt-3 text-sm text-neutral-400">Loading…</p>
            ) : status?.connected ? (
              <div className="mt-3 space-y-2">
                <span className="font-heading inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-green-700">
                  <CheckCircleIcon className="h-3.5 w-3.5" />
                  Connected
                </span>
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                  <dt className="text-neutral-400">Company ID</dt>
                  <dd className="text-neutral-700">{status.realm_id}</dd>
                  <dt className="text-neutral-400">Connected by</dt>
                  <dd className="text-neutral-700">{status.connected_by ?? "—"}</dd>
                  <dt className="text-neutral-400">Connected on</dt>
                  <dd className="text-neutral-700">
                    {status.connected_at ? new Date(status.connected_at).toLocaleDateString() : "—"}
                  </dd>
                </dl>
                {status.needs_reconnect && (
                  <p className="flex items-center gap-1.5 text-sm font-medium text-amber-700">
                    <AlertCircleIcon className="h-4 w-4 shrink-0" />
                    This connection has expired — reconnect to keep syncing.
                  </p>
                )}
                <div className="pt-1">
                  <button
                    onClick={() => setConfirmingDisconnect(true)}
                    className="font-heading flex items-center gap-1.5 rounded-md border border-red-200 px-3.5 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
                  >
                    <TrashIcon className="h-4 w-4" />
                    Disconnect
                  </button>
                </div>
              </div>
            ) : (
              <div className="mt-3">
                <span className="font-heading inline-block rounded-full bg-neutral-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-neutral-500">
                  Not connected
                </span>
                <div className="mt-3">
                  <button
                    onClick={handleConnect}
                    disabled={connecting}
                    className="font-heading rounded-md bg-neutral-900 px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-white hover:bg-neutral-800 disabled:opacity-50"
                  >
                    {connecting ? "Redirecting…" : "Connect to QuickBooks"}
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {confirmingDisconnect && (
        <Modal title="Disconnect QuickBooks" onClose={() => setConfirmingDisconnect(false)} maxWidthClassName="max-w-sm">
          <div className="space-y-4">
            <p className="text-sm text-neutral-600">
              Disconnecting stops any QuickBooks syncing until this is reconnected. This can&apos;t be undone from here.
            </p>
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setConfirmingDisconnect(false)}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
              >
                Cancel
              </button>
              <button
                onClick={handleDisconnect}
                disabled={disconnecting}
                className="font-heading flex items-center gap-1.5 rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                <TrashIcon className="h-4 w-4" />
                {disconnecting ? "Disconnecting…" : "Disconnect"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
