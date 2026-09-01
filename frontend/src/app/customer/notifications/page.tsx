"use client";

import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api";
import { listNotifications, markNotificationRead, type AppNotification } from "@/lib/notifications";
import {
  AlertCircleIcon,
  BellIcon,
  CheckCircleIcon,
  DocumentIcon,
  RefreshCwIcon,
  ShieldIcon,
} from "@/components/icons";
import type { ComponentType, SVGProps } from "react";

const TYPE_STYLE: Record<string, { icon: ComponentType<SVGProps<SVGSVGElement>>; tone: string }> = {
  account: { icon: DocumentIcon, tone: "bg-blue-50 text-blue-600" },
  bank_verified: { icon: ShieldIcon, tone: "bg-green-50 text-green-600" },
  autopay: { icon: RefreshCwIcon, tone: "bg-blue-50 text-blue-600" },
  payment: { icon: CheckCircleIcon, tone: "bg-green-50 text-green-600" },
  alert: { icon: AlertCircleIcon, tone: "bg-amber-50 text-amber-600" },
};

export default function CustomerNotificationsPage() {
  const [items, setItems] = useState<AppNotification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">All Notifications</h1>
        <p className="text-sm text-neutral-400">Everything about your applications, lease, and payments, as it happens.</p>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white">
        {loading ? (
          <p className="px-5 py-8 text-center text-sm text-neutral-400">Loading…</p>
        ) : items.length === 0 ? (
          <div className="flex flex-col items-center gap-2 px-5 py-12 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-neutral-100 text-neutral-400">
              <BellIcon className="h-5 w-5" />
            </span>
            <p className="text-sm font-semibold text-neutral-700">Nothing yet</p>
            <p className="text-sm text-neutral-400">Updates about your applications and lease will show up here.</p>
          </div>
        ) : (
          <div className="divide-y divide-neutral-100">
            {items.map((n) => {
              const style = TYPE_STYLE[n.data.type] ?? { icon: BellIcon, tone: "bg-neutral-100 text-neutral-500" };
              const ItemIcon = style.icon;
              const isNew = !n.read_at;
              return (
                <button
                  key={n.id}
                  onClick={() => handleOpen(n)}
                  className={`flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-neutral-50 ${isNew ? "bg-red-50/40" : ""}`}
                >
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
                    <p className="mt-0.5 text-sm text-neutral-500">{n.data.body}</p>
                    <p className="mt-1 text-xs text-neutral-400">{new Date(n.created_at).toLocaleString()}</p>
                  </div>
                </button>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
