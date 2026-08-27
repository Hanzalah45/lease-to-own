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
 * Sample Early Purchase Option payoff schedule — matches the reference
 * design's numbers exactly. The real pricing engine (with its "step-up after
 * the first 90 days, then decline to $0 at term end" rule) ships in
 * Milestone 2; this is illustrative sample data kept consistent across
 * every screen that shows an EPO price.
 */
export const EPO_SCHEDULE_FULL: { month: number; value: number }[] = [
  { month: 1, value: 7025.31 },
  { month: 2, value: 6651.62 },
  { month: 3, value: 6277.93 },
  { month: 4, value: 18609.7 },
  { month: 5, value: 18049.17 },
  { month: 6, value: 17488.63 },
  { month: 7, value: 16928.1 },
  { month: 8, value: 16367.56 },
  { month: 9, value: 15807.03 },
  { month: 10, value: 15246.49 },
  { month: 11, value: 14685.95 },
  { month: 12, value: 14125.42 },
  { month: 13, value: 13564.88 },
  { month: 14, value: 13004.35 },
  { month: 15, value: 12443.81 },
  { month: 16, value: 11883.28 },
  { month: 17, value: 11322.75 },
  { month: 18, value: 10762.21 },
  { month: 19, value: 10201.68 },
  { month: 20, value: 9641.14 },
  { month: 21, value: 9080.6 },
  { month: 22, value: 8520.07 },
  { month: 23, value: 7959.53 },
  { month: 24, value: 7399.0 },
  { month: 25, value: 6838.47 },
  { month: 26, value: 6277.93 },
  { month: 27, value: 5717.4 },
  { month: 28, value: 5156.86 },
  { month: 29, value: 4596.32 },
  { month: 30, value: 4035.79 },
  { month: 31, value: 3475.26 },
  { month: 32, value: 2914.72 },
  { month: 33, value: 2354.18 },
  { month: 34, value: 1793.65 },
  { month: 35, value: 1233.12 },
  { month: 36, value: 0.0 },
];

export function epoAt(month: number): number {
  const point = EPO_SCHEDULE_FULL.find((p) => p.month === month);
  if (point) return point.value;
  const clamped = Math.max(1, Math.min(36, month));
  return EPO_SCHEDULE_FULL[clamped - 1].value;
}

export const EPO_SCHEDULE_SAMPLE = EPO_SCHEDULE_FULL.filter((p) => p.month === 1 || p.month % 3 === 0);
