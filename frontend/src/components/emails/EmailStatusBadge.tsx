import type { EmailLogEntry } from "@/lib/email-logs";

const STYLE: Record<EmailLogEntry["status"], { label: string; className: string; hint: string }> = {
  sent: { label: "Sent", className: "bg-green-100 text-green-700", hint: "Our email service accepted this message." },
  failed: { label: "Failed", className: "bg-red-100 text-red-700", hint: "The email service refused or could not send this message." },
  sending: { label: "Not confirmed", className: "bg-amber-100 text-amber-700", hint: "The email service has not confirmed this message." },
};

/** "Sent" means our email service accepted the message, not that it reached an inbox. */
export function EmailStatusBadge({ status }: { status: EmailLogEntry["status"] }) {
  const s = STYLE[status];
  return (
    <span title={s.hint} className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${s.className}`}>
      {s.label}
    </span>
  );
}
