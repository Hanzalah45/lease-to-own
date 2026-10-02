import Image from "next/image";
import Link from "next/link";

/**
 * Customer-facing Q&A about how the lease works (client, Joel, 2026-10-02) —
 * a small, separate feature linked from the bottom of /sign-contract. Plain
 * static content, no login or backend needed; mirrors the signed-link pages'
 * full-page card style for visual consistency. Content here is a reasonable
 * first draft built from this app's actual pricing/contract rules, meant for
 * Joel to review and edit, not final copy.
 */
const FAQS: { question: string; answer: string }[] = [
  {
    question: "What do I pay at signing?",
    answer:
      "A security deposit, a $150 equipment tracking device fee, and your first month's payment. You can pay all of it now, or just the deposit to hold your lease and pay the tracking fee and first month later, whenever you're ready to pick up your equipment.",
  },
  {
    question: "What is the tracking device fee for?",
    answer:
      "It covers the GPS tracking device installed on your equipment. It's a flat, one-time fee, separate from your deposit and monthly payments.",
  },
  {
    question: "How does AutoPay work?",
    answer:
      "You add a bank account and a backup card to your account. You choose which one AutoPay charges first each month; the other is used automatically if the first one fails, so a payment is never missed over an expired card or a bank hiccup.",
  },
  {
    question: "What if a payment is late?",
    answer:
      "A payment more than 10 days past due is charged a late fee of 10% of that payment, with a $5 minimum and a $30 maximum.",
  },
  {
    question: "What's the Early Purchase Option (EPO)?",
    answer:
      "You can buy out your equipment at any time instead of finishing the full lease term. Within the first 90 days, the payoff is the cash price minus everything you've paid toward it. After 90 days, it's the cash price minus half of what's been scheduled to date, plus anything you're behind on. Your security deposit doesn't reduce this amount; taxes are due separately if you exercise the EPO.",
  },
  {
    question: "What is Loss Damage Waiver (LDW)?",
    answer:
      "LDW is optional coverage that protects you against accidental damage to the equipment during your lease. Choosing it adds a small amount to your monthly payment and deposit; declining it carries no monthly surcharge.",
  },
  {
    question: "Do I get my deposit back?",
    answer:
      "Your deposit secures your lease and equipment reservation. Once you sign, you have 30 days to pick up your equipment; if you don't, the deposit may be forfeited and the application canceled, so it's important to complete pickup promptly.",
  },
  {
    question: "What happens once I own the equipment?",
    answer:
      "Once you've made every scheduled rental payment for your term, ownership transfers to you automatically. No extra paperwork or final payment is needed beyond your normal schedule.",
  },
];

export default function FaqPage() {
  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-2xl py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Frequently asked questions</h1>
          <p className="mt-1 text-sm text-neutral-500">Common questions about how your lease works.</p>
        </div>

        <div className="space-y-4">
          {FAQS.map((item) => (
            <div key={item.question} className="rounded-xl border border-neutral-200 bg-white p-5">
              <h2 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">{item.question}</h2>
              <p className="mt-2 text-sm leading-relaxed text-neutral-600">{item.answer}</p>
            </div>
          ))}
        </div>

        <div className="mt-6 text-center text-sm text-neutral-500">
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        </div>
      </div>
    </main>
  );
}
