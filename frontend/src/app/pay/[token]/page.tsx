"use client";

import Image from "next/image";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { getPublicPaymentLink, payPublicPaymentLink, type PublicPaymentLink } from "@/lib/payment-links";
import { getStripe } from "@/lib/stripe-client";

type Method = "bank" | "card";

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * The page a payment link opens (client, Joel, 2026-10-19 checklist, section 6). No login: the secret in the address proves the link is
 * the customer's. It shows what is being paid and exactly what each way of paying costs, and takes the payment with a saved bank account
 * or card. A customer with nothing saved is sent to add one first.
 */
export default function PayLinkPage() {
  const { token } = useParams<{ token: string }>();
  const [link, setLink] = useState<PublicPaymentLink | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [method, setMethod] = useState<Method | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getPublicPaymentLink(token)
      .then((data) => {
        if (cancelled) return;
        setLink(data);
        setMethod(data.available_methods.includes("bank") ? "bank" : (data.available_methods[0] ?? null));
      })
      .catch((err) => {
        if (!cancelled) setLoadError(err instanceof ApiError ? err.message : "This link could not be opened. Please try again.");
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  const total = link && method ? link.prices[method] : null;

  async function handlePay() {
    if (!link || !method || total === null || busy) return;
    setBusy(true);
    setError(null);
    try {
      const result = await payPublicPaymentLink(token, { method, expectedTotalCents: Math.round(total * 100) });
      if (result.status === "failed") {
        setLink(result.link);
        setError(result.message ?? "The payment did not go through. Please try again.");
        setBusy(false);
        return;
      }

      if (result.status === "requires_action" && result.client_secret) {
        const stripe = await getStripe();
        if (!stripe) throw new Error("Stripe failed to load. Please refresh and try again.");
        const { error: confirmError } = await stripe.confirmCardPayment(result.client_secret);
        if (confirmError) {
          setError(confirmError.message ?? "The card could not be confirmed. Please try again.");
          setBusy(false);
          return;
        }
        // Stripe tells us the card went through a moment later: wait for the link to show as paid.
        for (let attempt = 0; attempt < 5; attempt++) {
          await sleep(2000);
          const fresh = await getPublicPaymentLink(token).catch(() => null);
          if (fresh?.status === "paid") {
            setDone(`Your payment of ${money(total)} was received. Thank you.`);
            return;
          }
        }
        setDone("Your payment is being confirmed. You do not need to do anything else.");
        return;
      }

      setDone(
        result.status === "processing"
          ? `Your bank payment of ${money(total)} is processing. It usually clears in 1 to 4 business days. You do not need to do anything else.`
          : `Your payment of ${money(total)} was received. Thank you.`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : err instanceof Error ? err.message : "The payment could not be made. Please try again.");
      setBusy(false);
    }
  }

  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-md py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Make a payment</h1>
        </div>

        <div className="rounded-2xl bg-white p-6 shadow-xl shadow-black/5">
          {loadError ? (
            <p role="alert" className="text-center text-sm text-red-600">
              {loadError}
            </p>
          ) : !link ? (
            <p className="text-center text-sm text-neutral-400">Checking link…</p>
          ) : done ? (
            <div role="status" className="space-y-2 text-center">
              <p className="text-base font-semibold text-green-700">{done}</p>
              <p className="text-sm text-neutral-500">You can close this page.</p>
            </div>
          ) : link.blocker ? (
            <p role="status" className="text-center text-sm text-neutral-700">
              {link.blocker}
            </p>
          ) : link.available_methods.length === 0 ? (
            <div className="space-y-4 text-center">
              <p className="text-sm text-neutral-700">
                {link.customer_name ? `Hello ${link.customer_name}. ` : ""}To pay {link.description.toLowerCase()}, add a bank account or a card first.
              </p>
              {link.setup_url && (
                <a
                  href={link.setup_url}
                  className="font-heading inline-block rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
                >
                  Add a bank account or card
                </a>
              )}
              <p className="text-xs text-neutral-500">When you are done, open this link again to pay.</p>
            </div>
          ) : (
            <div className="space-y-5">
              <div>
                <p className="text-sm text-neutral-600">{link.customer_name ? `Hello ${link.customer_name}.` : "Hello."}</p>
                <p className="mt-1 text-sm font-semibold text-neutral-900">{link.description}</p>
                <p className="mt-0.5 text-xs text-neutral-500">Choose how you want to pay.</p>
              </div>

              <div className="space-y-2">
                {link.available_methods.map((m) => (
                  <label
                    key={m}
                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${
                      method === m ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"
                    }`}
                  >
                    <input
                      type="radio"
                      name="pay-link-method"
                      className="mt-1"
                      checked={method === m}
                      disabled={busy}
                      onChange={() => {
                        setMethod(m);
                        setError(null);
                      }}
                    />
                    <span className="text-sm">
                      <span className="font-semibold text-neutral-900">
                        {m === "bank" ? "Bank account" : "Card"}: {money(link.prices[m])}
                      </span>
                      <span className="block text-xs text-neutral-500">
                        {m === "bank"
                          ? "No fee. Takes 1 to 4 business days to clear."
                          : `Includes a ${money(link.prices.card_fee)} card fee. Paid right away.`}
                      </span>
                    </span>
                  </label>
                ))}
              </div>

              {error && (
                <p role="alert" className="text-sm text-red-600">
                  {error}
                </p>
              )}

              <button
                onClick={handlePay}
                disabled={busy || total === null}
                className="font-heading w-full rounded-md bg-red-600 px-3.5 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                {busy ? "Processing…" : `Pay ${total !== null ? money(total) : ""}`}
              </button>
              <p className="text-center text-xs text-neutral-400">
                This link works until {new Date(link.expires_at).toLocaleDateString()}.
              </p>
            </div>
          )}
        </div>
      </div>
    </main>
  );
}
