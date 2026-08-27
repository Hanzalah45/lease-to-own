export type UserRole = "customer" | "admin" | "super_admin";

export type UserStatus = "active" | "suspended" | "pending";

export type AdminPermissionKey =
  | "application_review"
  | "risk_assessment"
  | "contract_generation"
  | "equipment_tracking"
  | "payment_tracking";

/** One row = one area this admin is restricted to. No rows = full access. */
export interface AdminPermission {
  id: number;
  permission: AdminPermissionKey;
}

export interface CustomerProfile {
  id: number;
  government_id_type: string | null;
  government_id_number: string | null;
  address_line_1: string | null;
  city: string | null;
  state: string | null;
  zip: string | null;
  date_of_birth: string | null;
  residence_type: "apartment" | "house" | "other" | null;
  landlord_name: string | null;
  landlord_phone: string | null;
  move_notification_agreed: boolean;
  internal_notes: string | null;
  employment_status: string | null;
  employer_name: string | null;
  employer_phone: string | null;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: UserRole;
  status: UserStatus;
  customer_profile?: CustomerProfile | null;
  admin_permissions?: AdminPermission[];
}

export interface LoginResponse {
  user: AuthUser;
  token: string;
}
