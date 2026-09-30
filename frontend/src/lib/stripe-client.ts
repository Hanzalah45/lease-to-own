import { loadStripe, type Stripe } from "@stripe/stripe-js";

/** loadStripe() fetches Stripe.js once and caches it — call this, don't call loadStripe() directly elsewhere. */
let stripePromise: Promise<Stripe | null> | null = null;

export function getStripe(): Promise<Stripe | null> {
  if (!stripePromise) {
    stripePromise = loadStripe(process.env.NEXT_PUBLIC_STRIPE_KEY ?? "");
  }
  return stripePromise;
}
