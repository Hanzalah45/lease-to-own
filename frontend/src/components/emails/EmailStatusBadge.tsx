import type { DeliveryInfo, DeliveryStatus, EmailLogEntry } from "@/lib/email-logs";

const STYLE: Record<EmailLogEntry["status"], { label: string; className: string; hint: string }> = {
  sent: { label: "Sent", className: "bg-green-100 text-green-700", hint: "Our email service accepted this message." },
  failed: { label: "Failed", className: "bg-red-100 text-red-700", hint: "The email service refused or could not send this message." },
  sending: { label: "Not confirmed", className: "bg-amber-100 text-amber-700", hint: "The email service has not confirmed this message." },
};

/** "Sent" means our email service accepted the message. Whether it reached the inbox is the DeliveryBadge beside it. */
export function EmailStatusBadge({ status }: { status: EmailLogEntry["status"] }) {
  const s = STYLE[status];
  return (
    <span title={s.hint} className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${s.className}`}>
      {s.label}
    </span>
  );
}

const DELIVERY_STYLE: Record<DeliveryStatus, { label: string; className: string; hint: string }> = {
  delivered: { label: "Delivered", className: "bg-green-100 text-green-700", hint: "The mailbox provider accepted this message." },
  bounced: { label: "Bounced", className: "bg-red-100 text-red-700", hint: "The mailbox provider refused this message. Check the address." },
  complaint: { label: "Marked as spam", className: "bg-red-100 text-red-700", hint: "The person marked this message as spam." },
  delayed: { label: "Delayed", className: "bg-amber-100 text-amber-700", hint: "The email service is still trying to deliver this message." },
  rejected: { label: "Rejected", className: "bg-red-100 text-red-700", hint: "The email service refused to send this message." },
};

/** What happened to a message after the email service accepted it. Shows nothing while there is no result (or for mail from before this was tracked). */
export function DeliveryBadge({ log }: { log: Partial<DeliveryInfo> | null | undefined }) {
  if (!log?.delivery_status) return null;
  const s = DELIVERY_STYLE[log.delivery_status];

  return (
    <span title={log.delivery_detail ? `${s.hint} ${log.delivery_detail}` : s.hint} className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${s.className}`}>
      {s.label}
    </span>
  );
}

/** The same result as a few words at the end of a line (for the send logs of receipts and payment links). */
export function DeliveryNote({ log }: { log: Partial<DeliveryInfo> | null | undefined }) {
  if (!log?.delivery_status) return null;
  const s = DELIVERY_STYLE[log.delivery_status];
  const bad = log.delivery_status !== "delivered" && log.delivery_status !== "delayed";

  return (
    <span className={`font-semibold ${bad ? "text-red-600" : log.delivery_status === "delayed" ? "text-amber-700" : "text-green-700"}`}>
      {" · "}
      {s.label}
      {log.delivery_status === "delivered" && log.delivered_at ? ` ${new Date(log.delivered_at).toLocaleString()}` : ""}
      {bad && log.delivery_detail ? `: ${log.delivery_detail}` : ""}
    </span>
  );
}