import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type {
  AssignableLease,
  CustomerEquipmentUnit,
  EquipmentCounts,
  EquipmentReleaseStatus,
  EquipmentServiceRecord,
  EquipmentStatus,
  EquipmentUnit,
  EquipmentUnitPayload,
} from "@/types/equipment";

export interface EquipmentFilters {
  status?: EquipmentStatus | "all";
  search?: string;
  assignable?: boolean;
}

export interface EquipmentListResult {
  units: EquipmentUnit[];
  counts: EquipmentCounts;
}

function query(filters: EquipmentFilters = {}): string {
  const params = new URLSearchParams();
  if (filters.status && filters.status !== "all") params.set("status", filters.status);
  if (filters.search?.trim()) params.set("search", filters.search.trim());
  if (filters.assignable) params.set("assignable", "1");
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

/* ----------------------------------------------------------------------------
 * Admin — requires the `equipment_tracking` permission on the backend.
 * ------------------------------------------------------------------------- */

export async function listEquipmentUnits(filters: EquipmentFilters = {}): Promise<EquipmentListResult> {
  const data = await apiFetch<{ data: EquipmentUnit[]; meta: { counts: EquipmentCounts } }>(
    `/admin/equipment-units${query(filters)}`,
    { token: getToken() },
  );
  return { units: data.data, counts: data.meta.counts };
}

export async function getEquipmentUnit(id: number | string): Promise<EquipmentUnit> {
  const data = await apiFetch<{ data: EquipmentUnit }>(`/admin/equipment-units/${id}`, { token: getToken() });
  return data.data;
}

export async function createEquipmentUnit(payload: EquipmentUnitPayload): Promise<EquipmentUnit> {
  const data = await apiFetch<{ data: EquipmentUnit }>("/admin/equipment-units", {
    method: "POST",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

export async function updateEquipmentUnit(
  id: number,
  payload: Partial<EquipmentUnitPayload>,
): Promise<EquipmentUnit> {
  const data = await apiFetch<{ data: EquipmentUnit }>(`/admin/equipment-units/${id}`, {
    method: "PUT",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

export async function deleteEquipmentUnit(id: number): Promise<void> {
  await apiFetch<void>(`/admin/equipment-units/${id}`, { method: "DELETE", token: getToken() });
}

/** Ties a serial number to a lease and flips the unit to `leased`. */
export async function assignEquipmentUnit(
  id: number,
  payload: { lease_agreement_id: number; delivery_date?: string; condition_notes?: string },
): Promise<EquipmentUnit> {
  const data = await apiFetch<{ data: EquipmentUnit }>(`/admin/equipment-units/${id}/assign`, {
    method: "POST",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

/** Takes a unit back off its lease — returned, back in stock, or paid off by the customer. */
export async function releaseEquipmentUnit(
  id: number,
  payload: { status: EquipmentReleaseStatus; condition_notes?: string },
): Promise<EquipmentUnit> {
  const data = await apiFetch<{ data: EquipmentUnit }>(`/admin/equipment-units/${id}/release`, {
    method: "POST",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

export async function listAssignableLeases(): Promise<AssignableLease[]> {
  const data = await apiFetch<{ data: AssignableLease[] }>("/admin/equipment-units/assignable-leases", {
    token: getToken(),
  });
  return data.data;
}

export async function listServiceRecords(unitId: number): Promise<EquipmentServiceRecord[]> {
  const data = await apiFetch<{ data: EquipmentServiceRecord[] }>(
    `/admin/equipment-units/${unitId}/service-records`,
    { token: getToken() },
  );
  return data.data;
}

export async function createServiceRecord(
  unitId: number,
  payload: { service_date: string; description: string },
): Promise<EquipmentServiceRecord> {
  const data = await apiFetch<{ data: EquipmentServiceRecord }>(
    `/admin/equipment-units/${unitId}/service-records`,
    { method: "POST", token: getToken(), body: payload },
  );
  return data.data;
}

export async function deleteServiceRecord(unitId: number, recordId: number): Promise<void> {
  await apiFetch<void>(`/admin/equipment-units/${unitId}/service-records/${recordId}`, {
    method: "DELETE",
    token: getToken(),
  });
}

/* ----------------------------------------------------------------------------
 * Customer — read-only, scoped to the signed-in customer's own leases.
 * ------------------------------------------------------------------------- */

export async function listMyEquipment(): Promise<CustomerEquipmentUnit[]> {
  const data = await apiFetch<{ data: CustomerEquipmentUnit[] }>("/customer/equipment", { token: getToken() });
  return data.data;
}

/**
 * Date columns come back as ISO datetimes from the admin endpoints and as
 * plain Y-m-d from the customer one — this renders both, and an em dash for null.
 */
export function formatDate(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "—" : date.toLocaleDateString();
}

/** Y-m-d for date inputs, from either payload shape. */
export function toDateInput(value: string | null | undefined): string {
  if (!value) return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : date.toISOString().slice(0, 10);
}
