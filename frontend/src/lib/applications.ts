import { API_BASE_URL, apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { computeLeasePricing, num, type WizardState } from "@/components/applications/wizard/types";
import type { Application, ApplicationDealerNote, ApplicationStatus } from "@/types/application";




export async function listApplications(): Promise<Application[]> {
  const data = await apiFetch<{ data: Application[] }>("/admin/applications", { token: getToken() });
  return data.data;
}

export async function getApplication(id: number | string): Promise<Application> {
  const data = await apiFetch<{ data: Application }>(`/admin/applications/${id}`, { token: getToken() });
  return data.data;
}

export async function resolveRiskRedFlag(riskProfileId: number, redFlagId: number): Promise<void> {
  await apiFetch(`/admin/risk-profiles/${riskProfileId}/red-flags/${redFlagId}/resolve`, {
    method: "PATCH",
    token: getToken(),
  });
}

/** Admin action: runs the affordability/background check on demand — previously ran automatically at submission. */
export async function runBackgroundCheck(applicationId: number): Promise<void> {
  await apiFetch(`/admin/applications/${applicationId}/run-background-check`, {
    method: "POST",
    token: getToken(),
  });
}

/** Admin action: emails the customer a signed link to connect their bank via Plaid — an admin cannot do this on their behalf. */
export async function requestBankVerification(applicationId: number): Promise<void> {
  await apiFetch(`/admin/applications/${applicationId}/request-bank-verification`, {
    method: "POST",
    token: getToken(),
  });
}

export async function addDealerNote(applicationId: number, text: string): Promise<ApplicationDealerNote> {
  const data = await apiFetch<{ data: ApplicationDealerNote }>(`/admin/applications/${applicationId}/dealer-notes`, {
    method: "POST",
    token: getToken(),
    body: { text },
  });
  return data.data;
}

/** Maps the wizard's local field names onto the API's snake_case contract, including the ID document file. */
export function wizardStateToFormData(state: WizardState): FormData {
  const form = new FormData();
  const set = (key: string, value: string | boolean | undefined | null) => {
    if (value === undefined || value === null || value === "") return;
    form.set(key, typeof value === "boolean" ? (value ? "1" : "0") : value);
  };

  set("registered_customer_id", state.registeredCustomerId);
  set("name", state.name);
  set("email", state.email);
  set("cell_phone", state.cellPhone);
  set("mailing_address", state.mailingAddress);
  set("city", state.city);
  set("state", state.state);
  set("zip", state.zip);
  set("date_of_birth", state.dob);
  set("drivers_license", state.driversLicense);
  set("residence_type", state.residenceType);
  set("years_at_residence", state.yearsAtResidence);
  set("previous_address", state.previousAddress);
  set("landlord_name", state.landlordName);
  set("landlord_phone", state.landlordPhone);
  set("monthly_rent", state.monthlyRent);
  set("mortgage_amount", state.mortgageAmount);
  set("mortgage_years", state.mortgageYears);
  set("alternate_contact_1_name", state.alternateContact1Name);
  set("alternate_contact_1_phone", state.alternateContact1Phone);
  set("alternate_contact_2_name", state.alternateContact2Name);
  set("alternate_contact_2_phone", state.alternateContact2Phone);
  set("income_source", state.incomeSource);
  set("employer_name", state.employerName);
  set("employer_phone", state.employerPhone);
  set("employer_position", state.employerPosition);
  set("gross_monthly_income", state.grossMonthlyIncome);
  set("move_notification_agreed", state.moveNotificationAgreed);
  set("sales_person", state.salesPerson);
  set("condition", state.condition);
  set("make", state.make);
  set("model", state.model);
  set("serial", state.serial);
  set("description", state.description);
  set("ldw", state.ldw);
  set("cash_price", state.cashPrice);
  set("year", state.year);
  set("promo_code", state.promoCode);
  set("term_months", state.termMonths);
  set("tax_rate", state.taxRate);
  set("payment_due_day", state.paymentDueDay);
  set("autopay", state.autopay);

  // Monthly rental and (when LDW is declined) the security deposit are
  // auto-calculated from cash price + term — never read from raw typed
  // state, so a stale/blank value can't be submitted (client requirement,
  // 2026-09-04). Guest applications never reach this branch since they have
  // no cash price yet.
  if (num(state.cashPrice) > 0) {
    const pricing = computeLeasePricing(state);
    set("monthly_rental", String(pricing.monthlyRental));
    set("security_deposit", String(pricing.securityDeposit));
  }

  if (state.idDocument) {
    form.set("id_document", state.idDocument);
  }
  if (state.utilityBill) {
    form.set("utility_bill", state.utilityBill);
  }

  return form;
}

/** Public, no-login application — no auth token, and the wizard payload minus equipment/lease fields (nothing has been priced yet). */
export async function submitGuestApplication(state: WizardState): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/guest-applications", {
    method: "POST",
    body: wizardStateToFormData(state),
  });
}

/** Admin action: adds equipment + lease terms to a guest-originated application that doesn't have them yet. */
export async function attachLeaseToApplication(applicationId: number | string, state: WizardState): Promise<Application> {
  const form = new FormData();
  const set = (key: string, value: string | undefined | null) => {
    if (value === undefined || value === null || value === "") return;
    form.set(key, value);
  };
  set("condition", state.condition);
  set("make", state.make);
  set("model", state.model);
  set("serial", state.serial);
  set("description", state.description);
  set("ldw", state.ldw);
  set("cash_price", state.cashPrice);
  set("year", state.year);
  set("promo_code", state.promoCode);
  set("term_months", state.termMonths);
  set("tax_rate", state.taxRate);
  set("payment_due_day", state.paymentDueDay);
  set("autopay", state.autopay);

  // See the matching comment in wizardStateToFormData — always the computed
  // value, never the raw (now-unused) typed field.
  const pricing = computeLeasePricing(state);
  set("monthly_rental", String(pricing.monthlyRental));
  set("security_deposit", String(pricing.securityDeposit));

  const data = await apiFetch<{ data: Application }>(`/admin/applications/${applicationId}/lease`, {
    method: "POST",
    token: getToken(),
    body: form,
  });
  return data.data;
}

export async function createApplication(state: WizardState): Promise<Application> {
  const data = await apiFetch<{ data: Application }>("/admin/applications", {
    method: "POST",
    token: getToken(),
    body: wizardStateToFormData(state),
  });
  return data.data;
}

export async function listMyApplications(): Promise<Application[]> {
  const data = await apiFetch<{ data: Application[] }>("/customer/applications", { token: getToken() });
  return data.data;
}

export async function getMyApplication(id: number | string): Promise<Application> {
  const data = await apiFetch<{ data: Application }>(`/customer/applications/${id}`, { token: getToken() });
  return data.data;
}

/**
 * Responds to a "needs info" request — a text reply, a replacement ID
 * document, or both (at least one required) — and moves the application
 * back to waiting_review.
 */
export async function respondToInfoRequest(
  applicationId: number | string,
  { replyText, file }: { replyText?: string; file?: File | null },
): Promise<Application> {
  const form = new FormData();
  if (replyText) form.set("reply_text", replyText);
  if (file) form.set("id_document", file);
  const data = await apiFetch<{ data: Application }>(`/customer/applications/${applicationId}/respond`, {
    method: "POST",
    token: getToken(),
    body: form,
  });
  return data.data;
}

/** Lets the customer download exactly what they themselves attached to one of their own info-request replies. */
export async function downloadMyInfoRequestDocument(
  applicationId: number | string,
  infoRequestId: number | string,
  filename = "id-document",
): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/customer/applications/${applicationId}/info-requests/${infoRequestId}/document`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) throw new Error("Could not download this document.");

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

/** Customer self-service submission — same wizard payload, minus the admin-only registered_customer_id/sales_person fields. */
export async function createMyApplication(state: WizardState): Promise<Application> {
  const data = await apiFetch<{ data: Application }>("/customer/applications", {
    method: "POST",
    token: getToken(),
    body: wizardStateToFormData(state),
  });
  return data.data;
}

export interface ApplicationUpdatePayload {
  status?: ApplicationStatus;
  status_notes?: string | null;
  signature_received?: boolean;
  deposit_received?: boolean;
  lease?: Partial<{
    term_months: number;
    monthly_rental_payment: number;
    sales_tax_rate: number;
    security_deposit: number;
    autopay_enabled: boolean;
    ldw_selected: boolean;
    promo_code: string | null;
  }>;
  equipment?: Partial<{ model: string; serial_number: string; condition_notes: string | null }>;
  customer?: Partial<{
    address_line_1: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    residence_type: string | null;
  }>;
  risk?: Partial<{
    identity_verification_status: string;
    employment_verification_status: string;
    bank_verification_status: string;
    background_check_status: string;
    background_check_notes: string | null;
  }>;
}

export async function updateApplication(id: number | string, payload: ApplicationUpdatePayload): Promise<Application> {
  const data = await apiFetch<{ data: Application }>(`/admin/applications/${id}`, {
    method: "PUT",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

/** Streams the applicant's current ID document on file through an authenticated request and triggers a browser download. */
export async function downloadIdDocument(applicationId: number | string, filename = "id-document"): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/admin/applications/${applicationId}/id-document`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) throw new Error("Could not download the ID document.");

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export async function downloadUtilityBill(applicationId: number | string, filename = "utility-bill"): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/admin/applications/${applicationId}/utility-bill`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) throw new Error("Could not download the utility bill.");

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

/** Streams the specific document attached to one historical info-request reply — not just whatever's current on the profile. */
export async function downloadInfoRequestDocument(
  applicationId: number | string,
  infoRequestId: number | string,
  filename = "id-document",
): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/admin/applications/${applicationId}/info-requests/${infoRequestId}/document`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) throw new Error("Could not download this document.");

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}
