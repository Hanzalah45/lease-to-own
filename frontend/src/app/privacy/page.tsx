import type { Metadata } from "next";
import LegalPage, { type LegalSection } from "@/components/legal/LegalPage";

export const metadata: Metadata = {
  title: "Privacy Policy · Prostart Leasing",
  description: "How Prostart Leasing collects, uses and protects your personal information.",
};

const SECTIONS: LegalSection[] = [
  {
    heading: "Who we are",
    paragraphs: [
      "Prostart Leasing provides lease-to-own equipment through this website and customer portal. In this policy, we, us and our mean Prostart Leasing.",
      "Prostart Leasing, 2170 Buckthorne Place, Suite 160, The Woodlands, TX 77380. Phone (832) 482-4622. Email info@prostartleasing.com.",
    ],
  },
  {
    heading: "Information we collect",
    bullets: [
      "Account details: your name, email address, phone number and a password, which is stored in protected form and never in plain text.",
      "Application details: your home address and residence history, date of birth, driver's license number and a photo or scan of your ID, a recent utility bill, housing information (landlord or mortgage details), employment information, and the alternate contacts you choose to give us.",
      "Lease and payment records: your lease agreement and signatures (including the date, time and IP address when you sign), your payment schedule, payments made, late fees, and notes about your account.",
      "Payment methods: card and bank account details are entered with, and held by, our payment processor Stripe. We do not store your full card or bank account number.",
      "Equipment location: leased equipment carries a GPS tracking device, so we can see where the equipment is.",
      "Email records: the emails we send you about your account and whether they were delivered.",
      "Security records: your IP address and the time of important actions, such as signing in or signing a document.",
    ],
  },
  {
    heading: "How we use your information",
    bullets: [
      "To review your application and confirm your identity and eligibility.",
      "To create, manage and enforce your lease.",
      "To take and record payments, send receipts, and send payment reminders and notices.",
      "To keep accounting and tax records.",
      "To locate and recover equipment as allowed by your lease.",
      "To contact you about your account, and about offers if you agreed to that in your lease agreement.",
      "To prevent fraud, keep the portal secure, and follow the law.",
    ],
    paragraphs: ["We do not sell your personal information."],
  },
  {
    heading: "Who we share it with",
    paragraphs: ["We share information only with the people and services we need to run your lease:"],
    bullets: [
      "Stripe, which processes card and bank payments and verifies bank accounts.",
      "Amazon Web Services, which hosts the portal and delivers our emails.",
      "Intuit QuickBooks, our accounting software. See the QuickBooks section below.",
      "The company that supplies the equipment tracking device.",
      "Our accountants, lawyers and other professional advisers, who must keep it confidential.",
      "Courts, regulators and law enforcement when the law requires it, or to protect our rights and your safety.",
      "A buyer, if the business or its lease accounts are sold.",
    ],
  },
  {
    heading: "QuickBooks connection",
    paragraphs: [
      "Prostart Leasing can connect this portal to its own QuickBooks Online company. The connection is used to read the list of accounts and to add accounting entries for the payments we receive. An entry can include the amount, the date, the customer name and the lease number.",
      "Information from QuickBooks is used only for our own bookkeeping. It is not sold and is not shared with anyone else. The connection can be switched off and removed at any time, in the portal settings or in QuickBooks.",
    ],
  },
  {
    heading: "How long we keep it",
    paragraphs: [
      "We keep your information while your lease is active and afterward for as long as we need it for tax, accounting, legal and dispute purposes. After that we delete it or remove what identifies you.",
    ],
  },
  {
    heading: "How we protect it",
    paragraphs: [
      "The portal uses encrypted connections (HTTPS), access to your information is limited to staff who need it, and card and bank details are handled by Stripe. No system is perfectly secure, so please keep your password private.",
    ],
  },
  {
    heading: "Your choices",
    bullets: [
      "You can turn off payment reminder emails in your account. Notices about missed or failed payments and late fees are still sent, because your lease depends on them.",
      "You can opt out of marketing calls, texts and emails at any time by telling us in writing.",
      "You can ask us to show you, correct or delete the information we hold about you by contacting us. We may need to keep some of it to meet legal and lease requirements.",
    ],
  },
  {
    heading: "Children",
    paragraphs: ["Our services are for adults aged 18 or older. We do not knowingly collect information from children."],
  },
  {
    heading: "Changes to this policy",
    paragraphs: [
      "If we change this policy, we will post the new version on this page and update the date at the top. If a change is significant, we will also tell you by email.",
    ],
  },
  {
    heading: "Contact us",
    paragraphs: [
      "For any question about this policy or your information, write to Prostart Leasing, 2170 Buckthorne Place, Suite 160, The Woodlands, TX 77380, call (832) 482-4622, or email info@prostartleasing.com.",
    ],
  },
];

export default function PrivacyPage() {
  return (
    <LegalPage
      title="Privacy Policy"
      updated="November 2026"
      intro="This policy explains what personal information Prostart Leasing collects when you apply for or lease equipment, how we use it, and who we share it with."
      sections={SECTIONS}
    />
  );
}
