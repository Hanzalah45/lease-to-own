export interface Contract {
  id: number;
  lease_agreement_id: number;
  signer_user_id: number;
  file_path: string;
  version: number;
  signed_at: string | null;
  external_provider: string | null;
  external_envelope_id: string | null;
}
