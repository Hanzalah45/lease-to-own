export type AppStatus =
  | "submitted"
  | "under_review"
  | "needs_info"
  | "approved"
  | "completed"
  | "processed"
  | "funded"
  | "declined"
  | "withdrawn";

export interface DealerNote {
  id: number;
  author: string;
  time: string;
  text: string;
  isDealer?: boolean;
}

export interface ChecklistItem {
  label: string;
  done: boolean;
}

export interface ApplicationDetail {
  id: number;
  status: AppStatus;
  customerName: string;
  dealerLine: string;
  declineReason?: string;
  withdrawReason?: string;
  paymentReceived?: { amount: string; when: string };
  customer: {
    dlMatches: string;
    mailingAddress: string;
    physicalAddress: string;
    county: string;
    yearsAtResidence: string;
    ownRent: string;
  };
  lease: {
    term: string;
    monthlyRental: string;
    taxRate: string;
    salesTax: string;
    totalMonthly: string;
    securityDeposit: string;
    totalDue: string;
    autopay: string;
    ldw: string;
    promo: string;
  };
  equipment: {
    makeModel: string;
    cashPrice: string;
    condition: string;
    serial: string;
    deliveryDate: string;
    expectedOwnership: string;
    liveEpo: string;
  };
  risk: {
    identity: string;
    employment: string;
    bank: string;
    background: string;
    residenceType: string;
    note: string;
  };
  contract: {
    document: string;
    signedBy: string;
    timestamp: string;
    signed: boolean;
  };
  notes: DealerNote[];
  checklist: ChecklistItem[];
  assignment: { salesperson: string; reviewedBy: string };
  epoSchedule: { month: number; value: number }[];
}
