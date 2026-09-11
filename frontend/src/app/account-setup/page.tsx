"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";
import { AuthCard, AuthField, AuthSubmitButton } from "@/components/auth/AuthCard";
import { ApiError } from "@/lib/api";
import { setUpAccount } from "@/lib/auth";
import { validatePassword } from "@/lib/validation";

/**
 * Reached from ActivateAccountNotification's signed link, sent when a
 * guest-originated customer's first payment is marked paid — their shadow
 * account has no usable password until they set one here (see
 * AccountSetupSigner on the backend).
 */
export default function AccountSetupPage() {
  return (
    <Suspense fallback={null}>
      <AccountSetupForm />
    </Suspense>
  );
}

function AccountSetupForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const id = searchParams.get("id") ?? "";
  const hash = searchParams.get("hash") ?? "";
  const expires = searchParams.get("expires") ?? "";
  const signature = searchParams.get("signature") ?? "";
  const hasAllParams = Boolean(id && hash && expires && signature);

  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [touched, setTouched] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const passwordErr = validatePassword(password, true);
  const confirmErr =
    passwordConfirmation && passwordConfirmation !== password ? "Passwords do not match." : undefined;
  const isValid = !passwordErr && !confirmErr && !!passwordConfirmation;

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    if (!isValid) {
      setTouched(true);
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      await setUpAccount({ id, hash, expires, signature, password, password_confirmation: passwordConfirmation });
      setDone(true);
      setTimeout(() => router.push("/login"), 2000);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "This account setup link is invalid or has expired.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!hasAllParams) {
    return (
      <AuthCard
        eyebrow="Prostart Leasing"
        title="Set up your account"
        subtitle="This link is missing information."
        footer={
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        }
      >
        <p className="text-center text-sm text-red-600">This account setup link is incomplete.</p>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      eyebrow="Prostart Leasing"
      title="Set up your account"
      subtitle="Your first payment is in. Set a password to access your lease portal."
      footer={
        <Link href="/login" className="font-semibold text-neutral-900 underline">
          Back to sign in
        </Link>
      }
    >
      {done ? (
        <p className="text-center text-sm text-green-700">Your account is set up. Redirecting to sign in…</p>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          {error && <p className="text-sm text-red-600">{error}</p>}

          <AuthField
            label="Password"
            id="password"
            type="password"
            required
            minLength={8}
            placeholder="Letter + number, 8+ chars"
            value={password}
            onChange={setPassword}
            onBlur={() => setTouched(true)}
            error={touched ? passwordErr : undefined}
          />
          <AuthField
            label="Confirm password"
            id="password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={setPasswordConfirmation}
            onBlur={() => setTouched(true)}
            error={touched ? confirmErr : undefined}
          />

          <AuthSubmitButton disabled={submitting}>
            {submitting ? "Saving…" : "Set up account →"}
          </AuthSubmitButton>
        </form>
      )}
    </AuthCard>
  );
}
