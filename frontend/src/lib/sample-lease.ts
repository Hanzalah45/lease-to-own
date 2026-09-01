/** Shared sample lease data — kept consistent between the admin contract view and the customer portal. */

export type LeaseStatus = "active" | "paid_off" | "needs_info";

export interface SampleLease {
  id: number;
  equipment: string;
  serial: string;
  condition: string;
  cashPrice: number;
  term: number;
  monthlyRental: number;
  salesTax: number;
  totalMonthly: number;
  securityDeposit: number;
  totalDue: number;
  autopay: boolean;
  ldw: boolean;
  promo: string;
  paymentsMade: number;
  /** Extra funds paid over the required deposit/first payment — reduces the EPO price per the contract. */
  additionalFunds?: number;
  status: LeaseStatus;
  delivered: string;
  signed: boolean;
  signedAt: string;
}

export const CUSTOMER_NAME = "Robert Kirkland";
export const CUSTOMER_ADDRESS = "10320 Walnut Dr";
export const CUSTOMER_COUNTY = "Montgomery";
export const CUSTOMER_OWN_RENT = "Own";

export const SAMPLE_LEASES: SampleLease[] = [
  {
    id: 1,
    equipment: 'Worldlawn Diamondback 60"',
    serial: "AGZ3WA18973",
    condition: "New / 2026",
    cashPrice: 7399,
    term: 36,
    monthlyRental: 373.69,
    salesTax: 30.83,
    totalMonthly: 404.52,
    securityDeposit: 443.94,
    totalDue: 848.46,
    autopay: true,
    ldw: true,
    promo: "—",
    paymentsMade: 6,
    status: "active",
    delivered: "7/22/2026",
    signed: true,
    signedAt: "7/20/2026, 4:12 PM CT",
  },
  {
    id: 2,
    equipment: 'Worldlawn Gater 34"',
    serial: "202303U13213",
    condition: "New / 2026",
    cashPrice: 6000,
    term: 36,
    monthlyRental: 303.03,
    salesTax: 25.0,
    totalMonthly: 328.03,
    securityDeposit: 360,
    totalDue: 688.03,
    autopay: true,
    ldw: true,
    promo: "—",
    paymentsMade: 36,
    status: "paid_off",
    delivered: "7/20/2026",
    signed: true,
    signedAt: "7/18/2026, 2:40 PM CT",
  },
  {
    id: 3,
    equipment: "Toro Timecutter MX54",
    serial: "TMX54-88214",
    condition: "New / 2026",
    cashPrice: 5299,
    term: 24,
    monthlyRental: 267.6,
    salesTax: 22.08,
    totalMonthly: 289.68,
    securityDeposit: 318.65,
    totalDue: 608.33,
    autopay: false,
    ldw: true,
    promo: "—",
    paymentsMade: 0,
    status: "needs_info",
    delivered: "—",
    signed: false,
    signedAt: "",
  },
];

export function getLease(id: number): SampleLease | undefined {
  return SAMPLE_LEASES.find((l) => l.id === id);
}

/**
 * Real Early Purchase Option formula, straight from the signed contract
 * (Section 3, "Rental-Purchase Ownership"):
 *
 *   Within 90 days of the effective date:
 *     EPO = Cash Price − Rental Payments paid to date (excludes tax/fees)
 *
 *   After 90 days:
 *     EPO = Cash Price − 50% of Rental Payments scheduled to date
 *           + Rental Payments still owed + any additional funds
 *
 * Taxes are due separately at the time the EPO is exercised — they are not
 * part of this number. At the final month the customer already owns the
 * unit via the full-term path (Section 2), so EPO is moot there.
 *
 * 90 days is treated as 3 monthly cycles, matching the monthly payment
 * schedule everywhere else in the app.
 */
const EPO_NINETY_DAY_MONTH_CUTOFF = 3;

export function computeEpoAt(lease: SampleLease, month: number): number {
  const m = Math.max(0, Math.min(lease.term, month));
  if (m >= lease.term) return 0;

  const additionalFunds = lease.additionalFunds ?? 0;
  const paymentsToDate = m * lease.monthlyRental;

  if (m <= EPO_NINETY_DAY_MONTH_CUTOFF) {
    return Math.max(0, lease.cashPrice - paymentsToDate);
  }

  const stillOwed = (lease.term - m) * lease.monthlyRental;
  return Math.max(0, lease.cashPrice - 0.5 * paymentsToDate + stillOwed + additionalFunds);
}

export function computeEpoSchedule(lease: SampleLease): { month: number; value: number }[] {
  return Array.from({ length: lease.term }, (_, i) => {
    const month = i + 1;
    return { month, value: computeEpoAt(lease, month) };
  });
}

export function computeEpoScheduleSample(lease: SampleLease): { month: number; value: number }[] {
  return computeEpoSchedule(lease).filter((p) => p.month === 1 || p.month % 3 === 0);
}
