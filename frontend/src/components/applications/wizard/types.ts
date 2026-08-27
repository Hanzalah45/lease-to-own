export interface WizardState {
  // Step 1 — Equipment
  salesPerson: string;
  condition: "new" | "used";
  make: string;
  model: string;
  serial: string;
  description: string;
  ldw: "yes" | "no";
  cashPrice: string;
  year: string;
  promoCode: string;
  // Step 2 — Lease details
  termMonths: string;
  monthlyRental: string;
  taxRate: string;
  securityDeposit: string;
  paymentDueDay: string;
  autopay: "yes" | "no";
  // Step 3 — Customer info
  registeredCustomerId: string;
  email: string;
  cellPhone: string;
  mailingAddress: string;
  city: string;
  state: string;
  zip: string;
  dob: string;
  driversLicense: string;
  // Step 4 — Risk & verification
  residenceType: string;
  yearsAtResidence: string;
  incomeSource: string;
  grossMonthlyIncome: string;
  moveNotificationAgreed: boolean;
}

export const INITIAL_WIZARD_STATE: WizardState = {
  salesPerson: "",
  condition: "new",
  make: "",
  model: "",
  serial: "",
  description: "",
  ldw: "yes",
  cashPrice: "",
  year: "",
  promoCode: "",
  termMonths: "36",
  monthlyRental: "",
  taxRate: "8.25",
  securityDeposit: "",
  paymentDueDay: "15th",
  autopay: "no",
  registeredCustomerId: "",
  email: "",
  cellPhone: "",
  mailingAddress: "",
  city: "",
  state: "TX",
  zip: "",
  dob: "",
  driversLicense: "",
  residenceType: "",
  yearsAtResidence: "",
  incomeSource: "",
  grossMonthlyIncome: "",
  moveNotificationAgreed: false,
};

export function num(value: string): number {
  const n = parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

export interface LeasePricing {
  cashPrice: number;
  term: number;
  monthlyRental: number;
  salesTax: number;
  totalMonthlyPayment: number;
  totalDueToday: number;
  totalRentalPrice: number;
  epoToday: number;
  schedule: { month: number; value: number }[];
}

/** Sample pricing math — the real engine ships in Milestone 2. Auto-generated from cash price, monthly rental and term. */
export function computeLeasePricing(state: WizardState): LeasePricing {
  const cashPrice = num(state.cashPrice);
  const term = parseInt(state.termMonths, 10) || 0;
  const monthlyRental = num(state.monthlyRental);
  const taxRate = num(state.taxRate) / 100;
  const securityDeposit = num(state.securityDeposit);

  const salesTax = monthlyRental * taxRate;
  const totalMonthlyPayment = monthlyRental + salesTax;
  const totalDueToday = totalMonthlyPayment + securityDeposit;
  const totalRentalPrice = monthlyRental * term;
  const epoAt = (month: number) => Math.max(0, cashPrice - month * monthlyRental);

  const schedule: { month: number; value: number }[] = [];
  if (term > 0) {
    schedule.push({ month: 1, value: epoAt(1) });
    for (let m = 3; m <= term; m += 3) {
      schedule.push({ month: m, value: epoAt(m) });
    }
    if (schedule[schedule.length - 1]?.month !== term) {
      schedule.push({ month: term, value: epoAt(term) });
    }
  }

  return {
    cashPrice,
    term,
    monthlyRental,
    salesTax,
    totalMonthlyPayment,
    totalDueToday,
    totalRentalPrice,
    epoToday: epoAt(1),
    schedule,
  };
}

export function money(value: number): string {
  return value.toLocaleString("en-US", { style: "currency", currency: "USD" });
}
