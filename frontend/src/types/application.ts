import type { AuthUser } from "@/types/auth";
import type { LeaseAgreement } from "@/types/lease-agreement";

export type ApplicationStatus =
  | "waiting_review"
  | "needs_info"
  | "waiting_approval"
  | "in_verification"
  | "waiting_deposit"
  | "waiting_delivery"
  | "finished"
  | "declined"
  | "withdrawn";

export interface ApplicationDealerNote {
  id: number;
  text: string;
  created_at: string;
  author: { id: number; name: string };
}

/**
 * One "needs info" round-trip — the ask, and (once answered) the reply.
 * `requested_by` is only present in the admin's view of the application.
 */
export interface ApplicationInfoRequest {
  id: number;
  requested_by?: string;
  request_text: string;
  requested_at: string;
  reply_text: string | null;
  reply_has_document: boolean;
  replied_at: string | null;
}

export interface Application {
  id: number;
  customer_id: number;
  status: ApplicationStatus;
  status_notes: string | null;
  signature_received: boolean;
  deposit_received: boolean;
  /** Set the moment the customer signs the contract — the deposit secures the unit for 30 days from this timestamp. */
  deposit_hold_expires_at: string | null;
  /** Set once the daily deposits:forfeit-expired-holds job actually declines this application for a missed pickup. */
  deposit_forfeited_at: string | null;
  /** Eager-loaded as the reviewing admin's {id, name} — Eloquent serializes the `reviewedBy` relation under this same key as the raw FK column. */
  reviewed_by: { id: number; name: string } | null;
  /** Who submitted this application — an admin (New Application wizard) or the customer themselves (self-service). Null on rows created before this tracking existed. */
  created_by: { id: number; name: string } | null;
  internal_notes: string | null;
  created_at: string;
  updated_at: string;
  customer?: AuthUser;
  lease_agreement?: LeaseAgreement | null;
  dealer_notes?: ApplicationDealerNote[];
  info_requests?: ApplicationInfoRequest[];
}
