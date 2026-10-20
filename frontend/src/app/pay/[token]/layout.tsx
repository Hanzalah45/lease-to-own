import type { Metadata } from "next";
import type { ReactNode } from "react";

// The secret that proves the link is the customer's is in this address: never sent on to another site and never indexed.
export const metadata: Metadata = {
  title: "Pay Prostart Leasing",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function PayLinkLayout({ children }: { children: ReactNode }) {
  return children;
}
