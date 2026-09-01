import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { WizardState } from "@/components/applications/wizard/types";
import type { Application, ApplicationStatus } from "@/types/application";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

export async function listApplications(): Promise<Application[]> {
  const data = await apiFetch<{ data: Application[] }>("/admin/applications", { token: getToken() });
  return data.data;
}

export async function getApplication(id: number | string): Promise<Application> {
  const data = await apiFetch<{ data: Application }>(`/admin/applications/${id}`, { token: getToken() });
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
  set("cell_phone", state.cellPhone);
  set("mailing_address", state.mailingAddress);
  set("city", state.city);
  set("state", state.state);
  set("zip", state.zip);
  set("date_of_birth", state.dob);
  set("drivers_license", state.driversLicense);
  set("residence_type", state.residenceType);
  set("years_at_residence", state.yearsAtResidence);
  set("income_source", state.incomeSource);
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
  set("monthly_rental", state.monthlyRental);
  set("tax_rate", state.taxRate);
  set("security_deposit", state.securityDeposit);
  set("payment_due_day", state.paymentDueDay);
  set("autopay", state.autopay);

  if (state.idDocument) {
    form.set("id_document", state.idDocument);
  }

  return form;
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

/** Streams the applicant's uploaded ID document through an authenticated request and triggers a browser download. */
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
