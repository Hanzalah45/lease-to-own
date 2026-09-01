export type VerificationStatus = "pending" | "verified" | "failed";
export type BackgroundCheckStatus = "pending" | "clear" | "flagged";

export type RedFlagType =
  | "missed_payment"
  | "late_payment"
  | "failed_ach"
  | "unreachable_customer"
  | "bank_account_change"
  | "suspicious_behavior"
  | "undisclosed_move"
  | "gps_anomaly";

export interface RiskRedFlag {
  id: number;
  risk_profile_id: number;
  type: RedFlagType;
  description: string | null;
  flagged_at: string;
  resolved: boolean;
}

export interface RiskProfile {
  id: number;
  customer_id: number;
  identity_verification_status: VerificationStatus;
  employment_verification_status: VerificationStatus;
  bank_verification_status: VerificationStatus;
  background_check_status: BackgroundCheckStatus;
  background_check_notes: string | null;
  risk_score: number | null;
  landlord_contact_required: boolean;
  landlord_contact_reason: string | null;
  red_flags?: RiskRedFlag[];
  customer?: { id: number; name: string; email: string };
}
