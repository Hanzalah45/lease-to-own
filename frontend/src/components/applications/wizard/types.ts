import type { StepKey } from "@/components/applications/wizard/WizardSteps";
import {
  DRIVERS_LICENSE_MAX,
  validateCity,
  validateDob,
  validateEmail,
  validateEquipmentModel,
  validateIntegerInRange,
  validateMoney,
  validatePercent,
  validatePhone,
  validatePromoCode,
  validateSerialNumber,
  validateState,
  validateStreet,
  validateYear,
  validateZip,
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

  put(errors, "cash_price", validateMoney(state.cashPrice, "Cash price", { aboveZero: true }));
  if (!state.condition) {
    errors.condition = ["Equipment condition is required."];
  }
  put(errors, "year", validateYear(state.year));
  put(errors, "make", validateEquipmentModel(state.make));
  put(errors, "model", validateEquipmentModel(state.model));
  // The same serial rule the equipment module enforces — this field creates
  // the equipment record, so a serial with spaces in it would be unsearchable.
  put(errors, "serial", validateSerialNumber(state.serial));
  put(errors, "promo_code", validatePromoCode(state.promoCode ?? ""));

  return errors;
}

export function validateLeaseStep(state: WizardState): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  // 1-120 and 0-100 mirror ApplicationController's own rules exactly, so the
  // form refuses what the API would refuse rather than finding out on submit.
  put(errors, "term_months", validateIntegerInRange(state.termMonths, "Lease term", 1, 120));
  put(errors, "monthly_rental", validateMoney(state.monthlyRental, "Monthly rental", { aboveZero: true }));
  put(errors, "tax_rate", validatePercent(state.taxRate, "Sales tax rate"));
  put(errors, "payment_due_day", validateIntegerInRange(state.paymentDueDay, "Payment due day", 1, 31));
  put(
    errors,
    "security_deposit",
    validateMoney(state.securityDeposit ?? "", "Security deposit", { required: false }),
  );

  return errors;
}

export function validateCustomerStep(state: WizardState, isCustomerApp = false): Record<string, string[]> {
  const errors: Record<string, string[]> = {};

  if (!isCustomerApp && (!state.registeredCustomerId || state.registeredCustomerId.trim() === "")) {
    errors.registered_customer_id = ["Please select a registered customer."];
  }
  put(errors, "email", validateEmail(state.email ?? ""));
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
