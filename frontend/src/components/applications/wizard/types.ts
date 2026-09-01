import type { StepKey } from "@/components/applications/wizard/WizardSteps";

/** First message for a field from a Laravel-style { field: string[] } validation error map. */
export function fieldError(errors: Record<string, string[]> | undefined, key: string): string | undefined {
  return errors?.[key]?.[0];
}

/** Which wizard step a given API field name belongs to — used to jump straight to the step with the error. */
export const FIELD_TO_STEP: Record<string, StepKey> = {
  sales_person: "equipment",
  cash_price: "equipment",
  condition: "equipment",
  year: "equipment",
  make: "equipment",
  model: "equipment",
  serial: "equipment",
  description: "equipment",
  ldw: "equipment",
  promo_code: "equipment",

  term_months: "lease",
  tax_rate: "lease",
  monthly_rental: "lease",
  security_deposit: "lease",
  payment_due_day: "lease",
  autopay: "lease",

  registered_customer_id: "customer",
  email: "customer",
  cell_phone: "customer",
  mailing_address: "customer",
  city: "customer",
  state: "customer",
  zip: "customer",
  date_of_birth: "customer",
  drivers_license: "customer",
  id_document: "customer",

  residence_type: "risk",
  years_at_residence: "risk",
  income_source: "risk",
  gross_monthly_income: "risk",
  move_notification_agreed: "risk",
};

export const STATE_TO_FIELD: Record<keyof WizardState, string> = {
  salesPerson: "sales_person",
  condition: "condition",
  make: "make",
  model: "model",
  serial: "serial",
  description: "description",
  ldw: "ldw",
  cashPrice: "cash_price",
  year: "year",
  promoCode: "promo_code",
  termMonths: "term_months",
  monthlyRental: "monthly_rental",
  taxRate: "tax_rate",
  securityDeposit: "security_deposit",
  paymentDueDay: "payment_due_day",
  autopay: "autopay",
  registeredCustomerId: "registered_customer_id",
  email: "email",
  cellPhone: "cell_phone",
  mailingAddress: "mailing_address",
  city: "city",
  state: "state",
  zip: "zip",
  dob: "date_of_birth",
  driversLicense: "drivers_license",
  idDocument: "id_document",
  residenceType: "residence_type",
  yearsAtResidence: "years_at_residence",
  incomeSource: "income_source",
  grossMonthlyIncome: "gross_monthly_income",
  moveNotificationAgreed: "move_notification_agreed",
};

export function firstErrorStep(errors: Record<string, string[]>): StepKey | null {
  for (const key of Object.keys(errors)) {
    if (FIELD_TO_STEP[key]) return FIELD_TO_STEP[key];
  }
  return null;
}

export function validateEquipmentStep(state: WizardState): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  if (!state.cashPrice || isNaN(parseFloat(state.cashPrice)) || parseFloat(state.cashPrice) <= 0) {
    errors.cash_price = ["Cash price is required and must be greater than $0."];
  }
  if (!state.condition) {
    errors.condition = ["Equipment condition is required."];
  }
  if (!state.year || state.year.trim() === "") {
    errors.year = ["Year is required."];
  }
  if (!state.make || state.make.trim() === "") {
    errors.make = ["Make is required."];
  }
  if (!state.model || state.model.trim() === "") {
    errors.model = ["Model is required."];
  }
  if (!state.serial || state.serial.trim() === "") {
    errors.serial = ["Serial number is required."];
  }

  return errors;
}

export function validateLeaseStep(state: WizardState): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  if (!state.termMonths || isNaN(parseInt(state.termMonths, 10)) || parseInt(state.termMonths, 10) <= 0) {
    errors.term_months = ["Lease term in months is required."];
  }
  if (!state.monthlyRental || isNaN(parseFloat(state.monthlyRental)) || parseFloat(state.monthlyRental) <= 0) {
    errors.monthly_rental = ["Monthly rental payment is required and must be greater than $0."];
  }
  if (state.taxRate === "" || state.taxRate === undefined || state.taxRate === null || isNaN(parseFloat(state.taxRate)) || parseFloat(state.taxRate) < 0) {
    errors.tax_rate = ["Sales tax rate percentage is required."];
  }
  if (!state.paymentDueDay || state.paymentDueDay.trim() === "") {
    errors.payment_due_day = ["Payment due day is required."];
  }

  return errors;
}

export function validateCustomerStep(state: WizardState, isCustomerApp = false): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  if (!isCustomerApp && (!state.registeredCustomerId || state.registeredCustomerId.trim() === "")) {
    errors.registered_customer_id = ["Please select a registered customer."];
  }
  if (!state.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.email.trim())) {
    errors.email = ["A valid email address is required."];
  }
  if (!state.cellPhone || state.cellPhone.trim() === "") {
    errors.cell_phone = ["Cell phone number is required."];
  }
  if (!state.mailingAddress || state.mailingAddress.trim() === "") {
    errors.mailing_address = ["Mailing address is required."];
  }
  if (!state.city || state.city.trim() === "") {
    errors.city = ["City is required."];
  }
  if (!state.state || state.state.trim() === "") {
    errors.state = ["State is required."];
  }
  if (!state.zip || state.zip.trim() === "") {
    errors.zip = ["Zip code is required."];
  }
  if (!state.dob || state.dob.trim() === "") {
    errors.date_of_birth = ["Date of birth is required."];
  }
  if (!state.driversLicense || state.driversLicense.trim() === "") {
    errors.drivers_license = ["Driver's license number is required."];
  }
  if (!state.idDocument) {
    errors.id_document = ["Driver's License or Government ID document upload is required."];
  }

  return errors;
}

export function validateRiskStep(state: WizardState): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  if (!state.residenceType || state.residenceType.trim() === "") {
    errors.residence_type = ["Residence type is required."];
  }
  if (!state.yearsAtResidence || state.yearsAtResidence.trim() === "") {
    errors.years_at_residence = ["Years at residence is required."];
  }
  if (!state.incomeSource || state.incomeSource.trim() === "") {
    errors.income_source = ["Income source is required."];
  }
  if (!state.grossMonthlyIncome || isNaN(parseFloat(state.grossMonthlyIncome)) || parseFloat(state.grossMonthlyIncome) <= 0) {
    errors.gross_monthly_income = ["Gross monthly income is required and must be greater than $0."];
  }
  if (!state.moveNotificationAgreed) {
    errors.move_notification_agreed = ["Customer must agree to lease terms notification."];
  }

  return errors;
}

export function validateStep(stepKey: StepKey, state: WizardState, isCustomerApp = false): Record<string, string[]> {
  switch (stepKey) {
    case "equipment":
      return validateEquipmentStep(state);
    case "lease":
      return validateLeaseStep(state);
    case "customer":
      return validateCustomerStep(state, isCustomerApp);
    case "risk":
      return validateRiskStep(state);
    default:
      return {};
  }
}

export function validateAllSteps(state: WizardState, isCustomerApp = false): Record<string, string[]> {
  return {
    ...validateEquipmentStep(state),
    ...validateLeaseStep(state),
    ...validateCustomerStep(state, isCustomerApp),
    ...validateRiskStep(state),
  };
}

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
  idDocument: File | null;
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
  idDocument: null,
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

/**
 * Real EPO formula from the signed lease agreement (Section 3): within the
 * first 90 days (~3 monthly cycles), EPO = Cash Price − payments paid to
 * date. After that, EPO = Cash Price − 50% of payments scheduled to date +
 * payments still owed + additional funds. Taxes are due separately when the
 * EPO is exercised, not folded into this number. At the final month the
 * customer already owns the unit via the full-term path, so EPO is 0.
 */
const EPO_NINETY_DAY_MONTH_CUTOFF = 3;

function epoAtMonth(cashPrice: number, monthlyRental: number, term: number, month: number, additionalFunds = 0) {
  const m = Math.max(0, Math.min(term, month));
  if (term <= 0 || m >= term) return 0;

  const paymentsToDate = m * monthlyRental;
  if (m <= EPO_NINETY_DAY_MONTH_CUTOFF) {
    return Math.max(0, cashPrice - paymentsToDate);
  }

  const stillOwed = (term - m) * monthlyRental;
  return Math.max(0, cashPrice - 0.5 * paymentsToDate + stillOwed + additionalFunds);
}

/** Lease pricing math — mirrors the signed contract's formulas exactly (see epoAtMonth above for EPO). */
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
  const epoAt = (month: number) => epoAtMonth(cashPrice, monthlyRental, term, month);

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
