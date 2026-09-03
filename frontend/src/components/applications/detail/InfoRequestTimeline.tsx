import { SectionHeading } from "@/components/dashboard/SectionHeading";
import type { ApplicationInfoRequest } from "@/types/application";

/** Full history of "needs info" round-trips — every ask and every reply, oldest question never erased by a newer one. */
export function InfoRequestTimeline({
  requests,
  onDownloadDocument,
}: {
  requests: ApplicationInfoRequest[];
  onDownloadDocument: (infoRequestId: number) => void;
}) {
  if (requests.length === 0) return null;

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Info requests" subtitle="Every ask and reply, oldest to newest" />
      <div className="mt-4 space-y-4">
        {requests.map((r) => (
          <div key={r.id} className="rounded-lg border border-neutral-100 bg-neutral-50 p-3.5">
            <div className="flex items-start justify-between gap-2">
              <p className="text-sm text-neutral-800">{r.request_text}</p>
              <span
                className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                  r.replied_at ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"
                }`}
              >
                {r.replied_at ? "Answered" : "Waiting"}
              </span>
            </div>
            <p className="mt-1 text-xs text-neutral-400">
              Asked{r.requested_by ? ` by ${r.requested_by}` : ""} · {new Date(r.requested_at).toLocaleString()}
            </p>

            {r.replied_at && (
              <div className="mt-3 border-t border-neutral-200 pt-3">
                {r.reply_text && <p className="text-sm text-neutral-700">{r.reply_text}</p>}
                {r.reply_has_document && (
                  <button onClick={() => onDownloadDocument(r.id)} className="mt-1 text-sm font-semibold text-red-600 hover:underline">
                    {r.reply_text ? "Also attached: " : "Attached: "}updated ID document — Download →
                  </button>
                )}
                <p className="mt-1 text-xs text-neutral-400">Replied {new Date(r.replied_at).toLocaleString()}</p>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
