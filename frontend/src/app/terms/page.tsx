import type { Metadata } from "next";
import LegalPage, { type LegalSection } from "@/components/legal/LegalPage";

export const metadata: Metadata = {
  title: "Terms of Use · Prostart Leasing",
  description: "The terms for using the Prostart Leasing website and customer portal.",
};

const SECTIONS: LegalSection[] = [
  {
    heading: "Agreement to these terms",
    paragraphs: [
      "These terms cover your use of the Prostart Leasing website and customer portal. By creating an account or using the portal, you agree to them.",
      "Your lease is a separate signed agreement. If these terms and your lease agreement differ on anything about your lease, the lease agreement controls.",
    ],
  },
  {
    heading: "License to use the portal",
    paragraphs: [
      "We give you a limited, personal, non-exclusive, non-transferable right to use the portal to manage your own lease account. You may not copy it, resell it, reverse engineer it, or use it for anyone else.",
      "The portal, its design and its content belong to Prostart Leasing and its suppliers. Nothing in these terms transfers ownership of them to you.",
    ],
  },
  {
    heading: "Your account",
    bullets: [
      "You must be at least 18 years old and give us accurate, current information.",
      "Keep your password private and tell us right away if you think someone else has used your account.",
      "You are responsible for what happens under your account.",
    ],
  },
  {
    heading: "Using the portal",
    paragraphs: ["The portal lets you view your lease, make payments, sign documents and update your details. You agree not to:"],
    bullets: [
      "use it for anything unlawful, or give false information;",
      "try to see another customer's information or get into parts of the system you are not meant to reach;",
      "interfere with the portal, overload it, or copy its content with automated tools.",
    ],
  },
  {
    heading: "Electronic signatures and notices",
    paragraphs: [
      "You agree that documents you sign in the portal with an electronic signature have the same effect as signed paper documents. You also agree that we may send you notices by email or in the portal. Please keep your contact details current.",
    ],
  },
  {
    heading: "Payments",
    paragraphs: [
      "Your payments, AutoPay, late fees and the Early Purchase Option work as set out in your lease agreement. When you authorize AutoPay, you authorize us to charge the payment method you chose on the due dates. Card and bank details are handled by our payment processor, Stripe.",
    ],
  },
  {
    heading: "Equipment tracking",
    paragraphs: [
      "Leased equipment carries a GPS tracking device, as described in your lease agreement. You must not remove, disable or tamper with it.",
    ],
  },
  {
    heading: "Availability and changes",
    paragraphs: [
      "The portal is provided as is and as available. It may be unavailable from time to time, for example during maintenance, and we may change or remove features.",
    ],
  },
  {
    heading: "Limits on our liability",
    paragraphs: [
      "To the extent the law allows, Prostart Leasing is not responsible for indirect or consequential losses that come from using the portal, and our total responsibility for claims about the portal is limited to the amount you paid us in the 12 months before the claim. This does not change your rights under your lease agreement, and nothing in these terms limits a responsibility that the law does not allow us to limit.",
    ],
  },
  {
    heading: "Suspending or ending access",
    paragraphs: [
      "We may suspend or end your access to the portal if you break these terms or we suspect fraud or misuse. You may stop using the portal at any time. Ending your access does not end your lease or the amounts due under it.",
    ],
  },
  {
    heading: "Governing law",
    paragraphs: [
      "These terms are governed by the laws of the State of Texas. Disputes about a lease are handled as set out in your lease agreement. Any other dispute about the portal will be brought in the courts located in Texas.",
    ],
  },
  {
    heading: "Changes to these terms",
    paragraphs: [
      "We may update these terms. The new version will be posted on this page with a new date, and using the portal after that means you accept it.",
    ],
  },
  {
    heading: "Contact us",
    paragraphs: [
      "Prostart Leasing, 2170 Buckthorne Place, Suite 160, The Woodlands, TX 77380. Phone (832) 482-4622. Email info@prostartleasing.com.",
    ],
  },
];

export default function TermsPage() {
  return (
    <LegalPage
      title="Terms of Use"
      updated="November 2026"
      intro="These terms apply to the Prostart Leasing website and customer portal, and also serve as the end-user license for the portal."
      sections={SECTIONS}
    />
  );
}
