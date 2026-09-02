export type EquipmentStatus = "in_stock" | "leased" | "returned" | "owned_by_customer";

/** Statuses a unit can be released into — `leased` is only reachable by assigning. */
export type EquipmentReleaseStatus = Exclude<EquipmentStatus, "leased">;

export const EQUIPMENT_STATUS_LABELS: Record<EquipmentStatus, string> = {
  in_stock: "In stock",
  leased: "Leased",
  returned: "Returned",
  owned_by_customer: "Owned by customer",
};

export const EQUIPMENT_STATUS_COLORS: Record<EquipmentStatus, string> = {
  in_stock: "#16A34A",
  leased: "#171717",
  returned: "#D97706",
  owned_by_customer: "#2563EB",
};

/** The lease a unit is currently sitting on, as flattened by the admin endpoint. */
export interface EquipmentCurrentLease {
  id: number;
  application_id: number;
  customer_id: number;
  customer_name: string | null;
  customer_email: string | null;
  term_months: number;
  start_date: string | null;
  renewal_date: string | null;
  ownership_status: "leasing" | "owned";
}

export interface EquipmentServiceRecord {
  id: number;
  equipment_unit_id: number;
  service_date: string;
  description: string;
  performed_by: number | null;
  performed_by_name?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface EquipmentUnit {
  id: number;
  model: string;
  serial_number: string;
  vin: string | null;
  condition_notes: string | null;
  delivery_date: string | null;
  expected_return_or_ownership_date: string | null;
  status: EquipmentStatus;
  /** Phase 2 (GPS provider) placeholder — stored but not acted on yet. */
  gps_device_id: string | null;
  created_at: string;
  updated_at: string;
  /** Server-computed. */
  is_assignable: boolean;
  current_lease: EquipmentCurrentLease | null;
  service_records_count?: number;
  /** Only present on the `show` endpoint, not `index`. */
  service_records?: EquipmentServiceRecord[];
}

export interface EquipmentCounts {
  total: number;
  in_stock: number;
  leased: number;
  returned: number;
  owned_by_customer: number;
}

/** A lease with no unit on it yet — the options in the assign dialog. */
export interface AssignableLease {
  id: number;
  application_id: number;
  customer_id: number;
  customer_name: string | null;
  customer_email: string | null;
  term_months: number;
  start_date: string | null;
  monthly_rental_payment: string;
}

export interface EquipmentUnitPayload {
  model: string;
  serial_number: string;
  vin?: string | null;
  condition_notes?: string | null;
  delivery_date?: string | null;
  expected_return_or_ownership_date?: string | null;
  status?: EquipmentStatus;
  gps_device_id?: string | null;
}

/** Read-only shape the customer portal gets — no internal notes, no GPS fields. */
export interface CustomerEquipmentUnit {
  id: number;
  model: string;
  serial_number: string;
  vin: string | null;
  status: EquipmentStatus;
  delivery_date: string | null;
  expected_return_or_ownership_date: string | null;
  lease_agreement_id: number;
  lease_term_months: number;
  lease_ownership_status: "leasing" | "owned";
  last_service_date: string | null;
  service_records_count: number;
}
