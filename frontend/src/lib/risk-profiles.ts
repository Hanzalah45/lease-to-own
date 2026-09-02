import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { RiskProfile } from "@/types/risk-profile";

export async function listRiskProfiles(): Promise<RiskProfile[]> {
  const data = await apiFetch<{ data: RiskProfile[] }>("/admin/risk-profiles", { token: getToken() });
  return data.data;
}
