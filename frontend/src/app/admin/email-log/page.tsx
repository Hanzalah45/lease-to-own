"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { EmailStatusBadge } from "@/components/emails/EmailStatusBadge";
import { SearchIcon } from "@/components/icons";
import { ApiError } from "@/lib/api";
import { listEmailLogs, type EmailLogPage } from "@/lib/email-logs";

/** Wait for typing to pause before asking the server again. */
const SEARCH_DELAY_MS = 300;

/** How often the open log reads again. */
const REFRESH_MS = 10_000;

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: "", label: "All" },
  { value: "failed", label: "Failed" },
  { value: "sending", label: "Not confirmed" },
  { value: "sent", label: "Sent" },
];

/**
 * Every email the system sent, newest first (client, Joel, 2026-10-13): who it
 * went to (exactly as typed), what kind it was, and what the email service
 * answered. The text of each message is not kept, because it carries private
 * sign-in links.
 */
export default function EmailLogPage() {
  const { user } = useAuth();
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const canSee = user?.role === "super_admin" || restrictions.length === 0 || restrictions.includes("application_review");

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [loaded, setLoaded] = useState<{ key: string; result: EmailLogPage } | null>(null);
  const [error, setError] = useState<string | null>(null);

  // The search box feeds the query only after typing pauses, and a new search starts on page 1.
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, SEARCH_DELAY_MS);
    return () => clearTimeout(t);
  }, [searchInput]);

  // The log reads again every few seconds while the page is open (client, Joel, 2026-10-15: "in real time"), so a
  // resent link shows up without reloading. The same query again keeps the rows on screen, no loading flicker.
  const [tick, setTick] = useState(0);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
  const loadedKey = useRef<string | null>(null);
  useEffect(() => {
    const id = setInterval(() => {
      if (document.visibilityState === "visible") setTick((n) => n + 1);
    }, REFRESH_MS);
    return () => clearInterval(id);
  }, []);

  const key = JSON.stringify({ search, status, page });
  const loading = canSee && loaded?.key !== key && !error;

  useEffect(() => {
    if (!canSee) return;
    let cancelled = false;
    listEmailLogs({ search, status, page })
      .then((result) => {
        if (!cancelled) {
          setError(null);
          setLoaded({ key, result });
          loadedKey.current = key;
          setUpdatedAt(new Date());
        }
      })
      .catch((err) => {
        // A failed refresh keeps the rows already on screen; only a first load shows the error.
        if (!cancelled && loadedKey.current !== key) setError(err instanceof ApiError ? err.message : "Could not load the email log.");
      });
    return () => {
      cancelled = true;
    };
  }, [canSee, key, search, status, page, tick]);

  if (!canSee) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Email Log</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include application review. Ask a super admin to add the
          Application Review permission to your account.
        </p>
      </div>
    );
  }

  const result = loaded?.result;
  const rows = result?.data ?? [];

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Email Log"
        subtitle={`Every email the system sent, to whom, and what the email service answered. Updates by itself every ${REFRESH_MS / 1000} seconds${
          updatedAt ? ` (last checked ${updatedAt.toLocaleTimeString()})` : ""
        }.`}
      />

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="relative w-full sm:max-w-sm">
            <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
            <input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              maxLength={100}
              placeholder="Search address, subject or customer name..."
              className="w-full rounded-lg border border-neutral-200 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-neutral-400"
            />
          </div>
          <div className="flex gap-2">
            {STATUS_FILTERS.map((f) => (
              <button
                key={f.value}
                onClick={() => {
                  setStatus(f.value);
                  setPage(1);
                }}
                className={`rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide ${
                  status === f.value ? "bg-red-600 text-white" : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        {error ? (
          <p className="mt-4 text-sm text-red-600">{error}</p>
        ) : loading && !result ? (
          <p className="mt-4 text-sm text-neutral-400">Loading…</p>
        ) : rows.length === 0 ? (
          <p className="mt-4 text-sm text-neutral-500">No emails match.</p>
        ) : (
          <ul className={`mt-3 divide-y divide-neutral-100 ${loading ? "opacity-60" : ""}`}>
            {rows.map((e) => (
              <li key={e.id} className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-neutral-900">
                    {e.kind_label}
                    {e.user && (
                      <>
                        {" · "}
                        {e.user.role === "customer" ? (
                          <Link href={`/admin/customers/${e.user.id}`} className="font-medium text-red-600 hover:underline">
                            {e.user.name}
                          </Link>
                        ) : (
                          <span className="font-medium text-neutral-700">{e.user.name}</span>
                        )}
                      </>
                    )}
                  </p>
                  <p className="break-all text-xs text-neutral-500">To {e.to_address}</p>
                  {e.subject && <p className="text-xs text-neutral-400">{e.subject}</p>}
                  {e.error && <p className="mt-0.5 text-xs font-medium text-red-600">{e.error}</p>}
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-xs text-neutral-400">{new Date(e.created_at).toLocaleString()}</span>
                  <EmailStatusBadge status={e.status} />
                </div>
              </li>
            ))}
          </ul>
        )}

        {result && result.meta.last_page > 1 && (
          <div className="mt-4 flex items-center justify-between text-sm">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              className="rounded-md border border-neutral-300 px-3 py-1.5 font-semibold text-neutral-700 hover:bg-neutral-50 disabled:opacity-40"
            >
              ← Newer
            </button>
            <span className="text-xs text-neutral-500">
              Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} emails
            </span>
            <button
              onClick={() => setPage((p) => p + 1)}
              disabled={page >= result.meta.last_page}
              className="rounded-md border border-neutral-300 px-3 py-1.5 font-semibold text-neutral-700 hover:bg-neutral-50 disabled:opacity-40"
            >
              Older →
            </button>
          </div>
        )}
        {result && result.meta.last_page <= 1 && <p className="mt-3 text-xs text-neutral-400">{result.meta.total} emails</p>}
      </div>
    </div>
  );
}
