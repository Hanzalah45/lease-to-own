"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";
import { AuthCard, AuthField, AuthSubmitButton } from "@/components/auth/AuthCard";
import { ApiError } from "@/lib/api";
import { resetPassword } from "@/lib/auth";
import { validatePassword } from "@/lib/validation";

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}

function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

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
      await resetPassword({ email, token, password, password_confirmation: passwordConfirmation });
      setDone(true);
      setTimeout(() => router.push("/login"), 2000);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reset your password.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!token || !email) {
    return (
      <AuthCard
        eyebrow="Outdoor Fix"
        title="Reset your password"
        subtitle="This link is missing information."
        footer={
          <Link href="/forgot-password" className="font-semibold text-neutral-900 underline">
            Request a new reset link
          </Link>
        }
      >
        <p className="text-sm text-red-600">
          This reset link is incomplete. Please request a new one.
        </p>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      eyebrow="Outdoor Fix"
      title="Set a new password"
      subtitle={`For ${email}`}
      footer={
        <Link href="/login" className="font-semibold text-neutral-900 underline">
          Back to sign in
        </Link>
      }
    >
      {done ? (
        <p className="text-sm text-green-700">Your password has been reset. Redirecting to sign in…</p>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          {error && <p className="text-sm text-red-600">{error}</p>}

          <AuthField
            label="New password"
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
            label="Confirm new password"
            id="password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={setPasswordConfirmation}
            onBlur={() => setTouched(true)}
            error={touched ? confirmErr : undefined}
          />

          <AuthSubmitButton disabled={submitting || !isValid}>
            {submitting ? "Saving…" : "Reset password →"}
          </AuthSubmitButton>
        </form>
      )}
    </AuthCard>
  );
}
