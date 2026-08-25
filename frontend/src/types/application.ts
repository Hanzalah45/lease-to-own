export type ApplicationStatus =
  | "submitted"
  | "under_review"
  | "needs_info"
  | "approved"
  | "completed"
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
  reviewed_by: number | null;
  internal_notes: string | null;
  created_at: string;
  updated_at: string;
}
