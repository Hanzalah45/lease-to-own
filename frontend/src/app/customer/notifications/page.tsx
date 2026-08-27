import {
  AlertCircleIcon,
  CheckCircleIcon,
  ClockIcon,
  CreditCardIcon,
  DocumentIcon,
  RefreshCwIcon,
  ShieldIcon,
  XIcon,
} from "@/components/icons";
import type { ComponentType, SVGProps } from "react";

interface NotificationItem {
  id: number;
  icon: ComponentType<SVGProps<SVGSVGElement>>;
  tone: "neutral" | "amber" | "blue" | "green" | "purple" | "red";
  title: string;
  description: string;
  when: string;
  isNew?: boolean;
}

const TONE_STYLE: Record<NotificationItem["tone"], string> = {
  neutral: "bg-neutral-100 text-neutral-500",
  amber: "bg-amber-50 text-amber-600",
  blue: "bg-blue-50 text-blue-600",
  green: "bg-green-50 text-green-600",
  purple: "bg-purple-50 text-purple-600",
  red: "bg-red-50 text-red-500",
};

const ITEMS: NotificationItem[] = [
  {
    id: 1,
    icon: DocumentIcon,
    tone: "neutral",
    title: "Application submitted",
    description: 'We received your lease application for the Worldlawn Diamondback 60". Outdoor Fix will review it shortly.',
    when: "7/16/2026, 2:10 PM",
  },
  {
    id: 2,
    icon: AlertCircleIcon,
    tone: "amber",
    title: "Additional information needed",
    description: "Your driver's license upload was unreadable. Please re-upload a clear photo to continue.",
    when: "7/17/2026, 10:05 AM",
  },
  {
    id: 3,
    icon: DocumentIcon,
    tone: "blue",
    title: "Document requested",
    description: "Outdoor Fix requested a recent pay stub to verify your income.",
    when: "7/17/2026, 10:06 AM",
  },
  {
    id: 4,
    icon: CheckCircleIcon,
    tone: "blue",
    title: "Application approved",
    description: 'Your lease application for the Worldlawn Diamondback 60" was approved.',
    when: "7/18/2026, 11:20 AM",
  },
  {
    id: 5,
    icon: DocumentIcon,
    tone: "green",
    title: "Contract signed & on file",
    description: "Your Lease Purchase Agreement v1 was signed and saved to your account.",
    when: "7/20/2026, 4:12 PM",
  },
  {
    id: 6,
    icon: RefreshCwIcon,
    tone: "blue",
    title: "AutoPay enabled",
    description: "Your monthly payment will be automatically charged to Checking •••• 7920 each cycle.",
    when: "7/20/2026, 4:15 PM",
  },
  {
    id: 7,
    icon: CreditCardIcon,
    tone: "green",
    title: "Payment received",
    description: "$404.52 charged to Checking •••• 7920 for your August payment.",
    when: "8/15/2026, 9:02 AM",
  },
  {
    id: 8,
    icon: ShieldIcon,
    tone: "red",
    title: "Your Early Purchase Option price updated",
    description: "New payoff price: $6,690.77 after your last payment posted.",
    when: "8/15/2026, 9:03 AM",
  },
  {
    id: 9,
    icon: XIcon,
    tone: "red",
    title: "Your request was declined",
    description: "Reason: prior lease-to-own default found on background check. Contact Outdoor Fix with questions.",
    when: "8/3/2026, 1:40 PM",
  },
  {
    id: 10,
    icon: ClockIcon,
    tone: "amber",
    title: "Upcoming payment reminder",
    description: "$404.52 is due on 9/15/2026. AutoPay is enabled — no action needed.",
    when: "9/8/2026 (scheduled)",
    isNew: true,
  },
];

export default function CustomerNotificationsPage() {
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
        <p className="text-sm text-neutral-400">Everything needing attention — account requests and submitted applications, together.</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white">
        <div className="divide-y divide-neutral-100">
          {ITEMS.map((item) => {
            const ItemIcon = item.icon;
            return (
              <div key={item.id} className={`flex items-start gap-3 px-5 py-4 ${item.isNew ? "bg-red-50/40" : ""}`}>
                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${TONE_STYLE[item.tone]}`}>
                  <ItemIcon className="h-4 w-4" />
                </span>
                <div>
                  <p className="flex items-center gap-2 text-sm font-semibold text-neutral-900">
                    {item.title}
                    {item.isNew && (
                      <span className="font-heading rounded-full bg-red-600 px-2 py-0.5 text-[9px] font-bold uppercase text-white">
                        New
                      </span>
                    )}
                  </p>
                  <p className="mt-0.5 text-sm text-neutral-500">{item.description}</p>
                  <p className="mt-1 text-xs text-neutral-400">{item.when}</p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
