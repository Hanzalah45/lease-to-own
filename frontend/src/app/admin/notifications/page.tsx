"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { ApiError } from "@/lib/api";
import { listNotifications, markNotificationRead, type AppNotification } from "@/lib/notifications";
import { BellIcon, DocumentIcon, ShieldIcon, UserIcon } from "@/components/icons";
import type { ComponentType, SVGProps } from "react";

const TYPE_STYLE: Record<string, { icon: ComponentType<SVGProps<SVGSVGElement>>; tone: string; label: string }> = {
  account: { icon: UserIcon, tone: "bg-blue-50 text-blue-500", label: "Account" },
  bank_verified: { icon: ShieldIcon, tone: "bg-green-50 text-green-600", label: "Bank verification" },
};

export default function NotificationsPage() {
  const [items, setItems] = useState<AppNotification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<"all" | "unread">("all");

  useEffect(() => {
    (async () => {
      try {
        const result = await listNotifications();
        setItems(result.data);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Could not load notifications.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const unreadCount = items.filter((i) => !i.read_at).length;
  const filtered = useMemo(
    () => (filter === "unread" ? items.filter((i) => !i.read_at) : items),
    [items, filter],
  );

  async function handleOpen(n: AppNotification) {
    if (!n.read_at) {
      setItems((prev) => prev.map((i) => (i.id === n.id ? { ...i, read_at: new Date().toISOString() } : i)));
      try {
        await markNotificationRead(n.id);
      } catch {
        // non-critical — local state already reflects "read"
      }
    }
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="All Notifications"
        subtitle="Real events from account signups and account activity, as they happen."
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <MetricCard value={unreadCount} label="Unread" barColor="#DC2626" barPercent={unreadCount ? 70 : 0} />
          <MetricCard value={items.length} label="Total" barColor="#171717" barPercent={items.length ? 100 : 0} />
        </div>

        <div className="flex flex-wrap gap-2">
          {(["all", "unread"] as const).map((key) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              className={`font-heading rounded-full px-4 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
                filter === key ? "bg-red-600 text-white" : "border border-red-200 bg-white text-red-600 hover:bg-red-50"
              }`}
            >
              {key === "all" ? "All" : "Unread"}
            </button>
          ))}
        </div>
      </PageHeroHeader>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">Recent</h2>
        </div>

        {loading ? (
          <p className="py-8 text-center text-sm text-neutral-400">Loading…</p>
        ) : filtered.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-12 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-neutral-100 text-neutral-400">
              <BellIcon className="h-5 w-5" />
            </span>
            <p className="text-sm font-semibold text-neutral-700">Nothing here</p>
            <p className="text-sm text-neutral-400">New signups and account activity will show up here as they happen.</p>
          </div>
        ) : (
          <div className="divide-y divide-neutral-100">
            {filtered.map((n) => {
              const style = TYPE_STYLE[n.data.type] ?? { icon: DocumentIcon, tone: "bg-neutral-100 text-neutral-500", label: "Update" };
              const ItemIcon = style.icon;
              const isNew = !n.read_at;
              return (
                <div key={n.id} className={`flex items-center justify-between gap-4 py-4 ${isNew ? "bg-red-50/30" : ""}`}>
                  <button onClick={() => handleOpen(n)} className="flex flex-1 items-center gap-3 text-left">
                    <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${style.tone}`}>
                      <ItemIcon className="h-4 w-4" />
                    </span>
                    <div>
                      <p className="flex items-center gap-2 text-sm font-semibold text-neutral-900">
                        {n.data.title}
                        {isNew && (
                          <span className="font-heading rounded-full bg-red-600 px-2 py-0.5 text-[9px] font-bold uppercase text-white">
                            New
                          </span>
                        )}
                      </p>
                      <p className="text-xs text-neutral-400">
                        {n.data.body} · {new Date(n.created_at).toLocaleString()}
                      </p>
                    </div>
                  </button>
                  {n.data.action_url && (
                    <Link
                      href={n.data.action_url}
                      className="font-heading shrink-0 text-xs font-bold text-red-600 hover:underline"
                    >
                      View →
                    </Link>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
