import type { AuthUser } from "@/types/auth";
import type { LeaseAgreement } from "@/types/lease-agreement";

export type ApplicationStatus =
  | "submitted"
  | "under_review"
  | "needs_info"
  | "approved"
  | "completed"
  | "processed"
  | "funded_paid"
  | "declined"
  | "withdrawn";

export interface Application {
  id: number;
  customer_id: number;
  status: ApplicationStatus;
  status_notes: string | null;
  signature_received: boolean;
  deposit_received: boolean;
  /** Eager-loaded as the reviewing admin's {id, name} — Eloquent serializes the `reviewedBy` relation under this same key as the raw FK column. */
  reviewed_by: { id: number; name: string } | null;
  internal_notes: string | null;
  created_at: string;
  updated_at: string;
  customer?: AuthUser;
  lease_agreement?: LeaseAgreement | null;
}
