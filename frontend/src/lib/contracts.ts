import { API_BASE_URL, apiFetch, ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { Contract, LeaseAgreement } from "@/types/lease-agreement";

export async function signLease(leaseAgreementId: number, signerName: string): Promise<Contract> {
  const data = await apiFetch<{ data: Contract }>("/customer/contracts", {
    method: "POST",
    token: getToken(),
    body: { lease_agreement_id: leaseAgreementId, signer_name: signerName },
  });
  return data.data;
}

/** The signed params carried by a "sign your contract" email link — see ContractSigner on the backend. */
export interface SignedContractLinkParams {
  id: string;
  lease: string;
  hash: string;
  expires: string;
  signature: string;
}

/** Unauthenticated counterpart to fetching a lease for signing — used by /sign-contract, reached from the emailed signed link (guest customers have no working login yet). */
export async function getSignedLease(params: SignedContractLinkParams): Promise<LeaseAgreement> {
  const data = await apiFetch<{ data: LeaseAgreement }>("/contracts/verify-lease", {
    method: "POST",
    body: params,
  });
  return data.data;
}

/** Unauthenticated counterpart to signLease() — used by /sign-contract. */
export async function signLeaseViaLink(params: SignedContractLinkParams, signerName: string): Promise<Contract> {
  const data = await apiFetch<{ data: Contract }>("/contracts/verify-sign", {
    method: "POST",
    body: { ...params, signer_name: signerName },
  });
  return data.data;
}

async function downloadFile(path: string, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) {
    throw new ApiError(response.status, "Could not download the contract.");
  }
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export function downloadMyContract(contractId: number): Promise<void> {
  return downloadFile(`/customer/contracts/${contractId}/download`, `lease-agreement-${contractId}.pdf`);
}

export function downloadContract(contractId: number): Promise<void> {
  return downloadFile(`/admin/contracts/${contractId}/download`, `lease-agreement-${contractId}.pdf`);
}

/** Voids a signed contract — the customer is notified and can sign again once this clears. */
export async function voidContract(contractId: number, reason: string): Promise<Contract> {
  const data = await apiFetch<{ data: Contract }>(`/admin/contracts/${contractId}/void`, {
    method: "POST",
    token: getToken(),
    body: { reason },
  });
  return data.data;
}
