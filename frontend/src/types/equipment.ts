export type EquipmentStatus = "in_stock" | "leased" | "returned" | "owned_by_customer";

export interface EquipmentUnit {
  id: number;
  model: string;
  serial_number: string;
  vin: string | null;
  condition_notes: string | null;
  delivery_date: string | null;
  expected_return_or_ownership_date: string | null;
  status: EquipmentStatus;
  gps_device_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface EquipmentServiceRecord {
  id: number;
  equipment_unit_id: number;
  service_date: string;
  description: string;
  performed_by: number | null;
}
