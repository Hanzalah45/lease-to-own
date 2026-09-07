import type { StepKey } from "@/components/applications/wizard/WizardSteps";
import {
  DRIVERS_LICENSE_MAX,
  validateCity,
  validateConditionNotes,
  validateDob,
  validateEmail,
  validateEquipmentModel,
  validateIntegerInRange,
  validateMoney,
  validateName,
  validatePercent,
  validatePhone,
  validatePromoCode,
  validateSerialNumber,
  validateState,
  validateStreet,
  validateYear,
  validateZip,
  optional,
} from "@/lib/validation";

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
  name: "customer",
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
  previous_address: "risk",
  landlord_name: "risk",
  landlord_phone: "risk",
  monthly_rent: "risk",
  mortgage_amount: "risk",
  mortgage_years: "risk",
  utility_bill: "risk",
  alternate_contact_1_name: "risk",
  alternate_contact_1_phone: "risk",
  alternate_contact_2_name: "risk",
  alternate_contact_2_phone: "risk",
  income_source: "risk",
  employer_name: "risk",
  employer_phone: "risk",
  employer_position: "risk",
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
  name: "name",
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
  previousAddress: "previous_address",
  landlordName: "landlord_name",
  landlordPhone: "landlord_phone",
  monthlyRent: "monthly_rent",
  mortgageAmount: "mortgage_amount",
  mortgageYears: "mortgage_years",
  utilityBill: "utility_bill",
  alternateContact1Name: "alternate_contact_1_name",
  alternateContact1Phone: "alternate_contact_1_phone",
  alternateContact2Name: "alternate_contact_2_name",
  alternateContact2Phone: "alternate_contact_2_phone",
  incomeSource: "income_source",
  employerName: "employer_name",
  employerPhone: "employer_phone",
  employerPosition: "employer_position",
  grossMonthlyIncome: "gross_monthly_income",
  moveNotificationAgreed: "move_notification_agreed",
};

export function firstErrorStep(errors: Record<string, string[]>): StepKey | null {
  for (const key of Object.keys(errors)) {
    if (FIELD_TO_STEP[key]) return FIELD_TO_STEP[key];
  }
  return null;
}

/**
 * Records a message under an API field name. Errors are kept in the Laravel
 * `{ field: string[] }` shape so client-side rules and the API's own
 * validation errors can share one map and one renderer.
 */
function put(errors: Record<string, string[]>, key: string, message: string | undefined): void {
  if (message) errors[key] = [message];
}

/**
 * Every rule below comes from @/lib/validation — the same functions the
 * customer and equipment forms use, so a field is checked the same way
 * wherever it is typed. The wizard previously checked only that fields were
 * non-empty, which let malformed values through to the API and turned into a
 * 422 after the whole wizard had been filled in.
 */
export function validateEquipmentStep(state: WizardState): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  put(errors, "sales_person", optional((v) => validateName(v, "Sales person name"))(state.salesPerson));
  put(errors, "cash_price", validateMoney(state.cashPrice, "Cash price", { aboveZero: true }));
  if (!state.condition) {
    errors.condition = ["Equipment condition is required."];
  }
  put(errors, "year", validateYear(state.year));
  put(errors, "make", validateEquipmentModel(state.make, "Make"));
  put(errors, "model", validateEquipmentModel(state.model));
  // The same serial rule the equipment module enforces — this field creates
  // the equipment record, so a serial with spaces in it would be unsearchable.
  put(errors, "serial", validateSerialNumber(state.serial));
  put(errors, "description", validateConditionNotes(state.description ?? ""));
  put(errors, "promo_code", validatePromoCode(state.promoCode ?? ""));

  return errors;
}

export function validateLeaseStep(state: WizardState): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  // Only 12/24/36 months are priced (official divisor table — see
  // computeLeasePricing) — any other term has no defined monthly payment.
  if (!TERM_MONTH_OPTIONS.includes(Number(state.termMonths) as 12 | 24 | 36)) {
    errors.term_months = ["Lease term must be 12, 24, or 36 months."];
  }
  put(errors, "tax_rate", validatePercent(state.taxRate, "Sales tax rate"));
  put(errors, "payment_due_day", validateIntegerInRange(state.paymentDueDay, "Payment due day", 1, 31));

  return errors;
}

export function validateCustomerStep(state: WizardState, isCustomerApp = false, isGuestApp = false): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  if (isGuestApp) {
    put(errors, "name", validateName(state.name ?? "", "Full name"));
    put(errors, "email", validateEmail(state.email ?? ""));
  } else if (!isCustomerApp && (!state.registeredCustomerId || state.registeredCustomerId.trim() === "")) {
    errors.registered_customer_id = ["Please select a registered customer."];
  }
  put(errors, "cell_phone", validatePhone(state.cellPhone ?? "", true));
  put(errors, "mailing_address", validateStreet(state.mailingAddress ?? ""));
  put(errors, "city", validateCity(state.city ?? ""));
  // Case-insensitive: the field does not force upper case as you type, and the
  // API only caps the length.
  put(errors, "state", validateState((state.state ?? "").toUpperCase()));
  put(errors, "zip", validateZip(state.zip ?? ""));
  put(errors, "date_of_birth", validateDob(state.dob ?? ""));

  const licence = (state.driversLicense ?? "").trim();
  if (!licence) {
    errors.drivers_license = ["Driver's license number is required."];
  } else if (licence.length > DRIVERS_LICENSE_MAX) {
    errors.drivers_license = [`Driver's license must be ${DRIVERS_LICENSE_MAX} characters or fewer.`];
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
  // "lt1" is the only bucket unambiguously under 2 years — the "1-3" bucket
  // straddles the 2-year line, so it isn't treated as requiring this.
  if (state.yearsAtResidence === "lt1" && !(state.previousAddress ?? "").trim()) {
    errors.previous_address = ["Previous address is required when at this residence less than 2 years."];
  }
  if (state.residenceType.startsWith("rent_")) {
    put(errors, "landlord_name", validateName(state.landlordName ?? "", "Landlord name"));
    put(errors, "landlord_phone", validatePhone(state.landlordPhone ?? "", true));
    put(errors, "monthly_rent", validateMoney(state.monthlyRent ?? "", "Monthly rent", { aboveZero: true }));
  }
  if (state.residenceType.startsWith("own_")) {
    put(errors, "mortgage_amount", validateMoney(state.mortgageAmount ?? "", "Mortgage amount", { aboveZero: true }));
    if (!(state.mortgageYears ?? "").trim()) errors.mortgage_years = ["Mortgage history (years) is required for homeowners."];
  }
  put(errors, "alternate_contact_1_name", validateName(state.alternateContact1Name ?? "", "First alternate contact name"));
  put(errors, "alternate_contact_1_phone", validatePhone(state.alternateContact1Phone ?? "", true));
  put(errors, "alternate_contact_2_name", validateName(state.alternateContact2Name ?? "", "Second alternate contact name"));
  put(errors, "alternate_contact_2_phone", validatePhone(state.alternateContact2Phone ?? "", true));
  put(errors, "employer_name", validateName(state.employerName ?? "", "Employer name"));
  put(errors, "employer_phone", validatePhone(state.employerPhone ?? "", true));
  put(errors, "employer_position", validateName(state.employerPosition ?? "", "Position/title"));
  if (!state.incomeSource || state.incomeSource.trim() === "") {
    errors.income_source = ["Income source is required."];
  }
  put(
    errors,
    "gross_monthly_income",
    validateMoney(state.grossMonthlyIncome, "Gross monthly income", { aboveZero: true }),
  );
  if (!state.moveNotificationAgreed) {
    errors.move_notification_agreed = ["Customer must agree to lease terms notification."];
  }

  return errors;
}

export function validateStep(stepKey: StepKey, state: WizardState, isCustomerApp = false, isGuestApp = false): Record<string, string[]> {
  switch (stepKey) {
    case "equipment":
      return validateEquipmentStep(state);
    case "lease":
      return validateLeaseStep(state);
    case "customer":
      return validateCustomerStep(state, isCustomerApp, isGuestApp);
    case "risk":
      return validateRiskStep(state);
    default:
      return {};
  }
}

export function validateAllSteps(state: WizardState, isCustomerApp = false, isGuestApp = false): Record<string, string[]> {
  return {
    ...(isGuestApp ? {} : validateEquipmentStep(state)),
    ...(isGuestApp ? {} : validateLeaseStep(state)),
    ...validateCustomerStep(state, isCustomerApp, isGuestApp),
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
  /** Guest (no-login) application only — every other entry point already has an authenticated/selected user. */
  name: string;
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
  previousAddress: string;
  landlordName: string;
  landlordPhone: string;
  monthlyRent: string;
  mortgageAmount: string;
  mortgageYears: string;
  utilityBill: File | null;
  alternateContact1Name: string;
  alternateContact1Phone: string;
  alternateContact2Name: string;
  alternateContact2Phone: string;
  incomeSource: string;
  employerName: string;
  employerPhone: string;
  employerPosition: string;
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
  paymentDueDay: "15",
  autopay: "no",
  registeredCustomerId: "",
  name: "",
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
  previousAddress: "",
  landlordName: "",
  landlordPhone: "",
  monthlyRent: "",
  mortgageAmount: "",
  mortgageYears: "",
  utilityBill: null,
  alternateContact1Name: "",
  alternateContact1Phone: "",
  alternateContact2Name: "",
  alternateContact2Phone: "",
  incomeSource: "",
  employerName: "",
  employerPhone: "",
  employerPosition: "",
  grossMonthlyIncome: "",
  moveNotificationAgreed: false,
};

export function num(value: string): number {
  const n = parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

function round2(n: number): number {
  return Math.round(n * 100) / 100;
}

export interface LeasePricing {
  cashPrice: number;
  term: number;
  monthlyRental: number;
  ldwAmount: number;
  ldwSelected: boolean;
  salesTax: number;
  totalMonthlyPayment: number;
  totalDueToday: number;
  totalRentalPrice: number;
  securityDeposit: number;
  trackingDeviceFee: number;
  epoToday: number;
  schedule: { month: number; value: number }[];
}

/**
 * Real EPO formula (client, direct answer, 2026-09-05 — supersedes the
 * 2026-09-04 "full term scheduled" restatement, which could produce an EPO
 * exceeding the cash price and jumping discontinuously at the 90-day mark):
 * within the first 90 days (~3 monthly cycles), EPO = Cash Price − payments
 * scheduled to date (100% credit). After that, EPO = Cash Price − 50% of
 * payments scheduled TO DATE (not the full term) + any payments still owed
 * (past-due, unpaid amounts — always 0 in this wizard preview, since no
 * payment history exists yet at application time) − additional funds. The
 * security deposit does NOT reduce this — the client was explicit it's
 * "the cost of the loan," not applied to EPO. Taxes are due separately when
 * the EPO is exercised, not folded into this number. At the final month the
 * customer already owns the unit via the full-term path, so EPO is 0.
 */
const EPO_NINETY_DAY_MONTH_CUTOFF = 3;

function epoAtMonth(cashPrice: number, monthlyRental: number, term: number, month: number, additionalFunds = 0) {
  const m = Math.max(0, Math.min(term, month));
  if (term <= 0 || m >= term) return 0;

  const paymentsScheduledToDate = m * monthlyRental;

  if (m <= EPO_NINETY_DAY_MONTH_CUTOFF) {
    return Math.max(0, cashPrice - paymentsScheduledToDate);
  }

  return Math.max(0, cashPrice - 0.5 * paymentsScheduledToDate - additionalFunds);
}

/**
 * Official payment divisors from Outdoor Fix's own customer-facing lease
 * terms sheet (2026-09-04): "Divide the cash price (excluding tax) by 19.8
 * for 36-months, 16.0 for 24-months, or 10.0 for 12-months." These are NOT
 * proportional to term (10/12, 16/24, 19.8/36 are all different ratios —
 * longer terms carry progressively more markup), so this must be a lookup,
 * not a formula. Only these three terms are priced/supported.
 */
export const TERM_MONTH_OPTIONS = [12, 24, 36] as const;
const MONTHLY_PAYMENT_DIVISORS: Record<number, number> = { 12: 10.0, 24: 16.0, 36: 19.8 };

/** Flat GPS tracking device fee — a separate line item from the deposit, due at the same time (client's official pricing blueprint, 2026-09-04). */
export const TRACKING_DEVICE_FEE = 150;

/**
 * Lease pricing math — the client's official pricing blueprint (2026-09-04),
 * verified against its own worked example (Cash Price $4,899 / 36mo -> LDW:
 * $284.16/mo + $342.93 deposit + $777.09 total; no-LDW: $264.57/mo + $793.71
 * deposit + $1,208.28 total — both match to the cent):
 *   Base Monthly = Cash Price / divisor (12/24/36mo -> 10.0/16.0/19.8)
 *   Taking LDW:    +0.75%/mo of cash price; deposit = 7% of cash price
 *   Declining LDW: +0.35%/mo "no-LDW surcharge" instead; deposit = 3x the
 *                  (base + surcharge) monthly payment
 *   Tracking device fee: flat $150, always — due alongside the deposit but
 *   NOT part of it (kept as a separate addend everywhere "total due today"
 *   is computed).
 * Monthly rental and security deposit are never admin-typed (client
 * requirement, 2026-09-04) — always computed from cash price/term/LDW.
 */
export function computeLeasePricing(state: WizardState): LeasePricing {
  const cashPrice = num(state.cashPrice);
  const term = parseInt(state.termMonths, 10) || 0;
  const taxRate = num(state.taxRate) / 100;
  const ldwSelected = state.ldw === "yes";

  const divisor = MONTHLY_PAYMENT_DIVISORS[term];
  const monthlyRental = divisor ? round2(cashPrice / divisor) : 0;
  // Exactly one of these applies — ldwAmount holds whichever does, matching
  // how the backend stores both in the same ldw_amount column.
  const ldwAmount = round2(cashPrice * (ldwSelected ? 0.0075 : 0.0035));
  const securityDeposit = ldwSelected
    ? round2(cashPrice * 0.07)
    : round2((monthlyRental + ldwAmount) * 3);

  const salesTax = round2((monthlyRental + ldwAmount) * taxRate);
  const totalMonthlyPayment = round2(monthlyRental + ldwAmount + salesTax);
  const totalDueToday = round2(totalMonthlyPayment + securityDeposit + TRACKING_DEVICE_FEE);
  const totalRentalPrice = round2(monthlyRental * term);
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
    ldwAmount,
    ldwSelected,
    salesTax,
    totalMonthlyPayment,
    totalDueToday,
    totalRentalPrice,
    securityDeposit,
    trackingDeviceFee: TRACKING_DEVICE_FEE,
    epoToday: epoAt(1),
    schedule,
  };
}

export function money(value: number): string {
  return value.toLocaleString("en-US", { style: "currency", currency: "USD" });
}
