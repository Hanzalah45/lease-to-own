"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { AuthCard, AuthField, AuthSubmitButton } from "@/components/auth/AuthCard";
import { ApiError } from "@/lib/api";
import { forgotPassword } from "@/lib/auth";
import { validateEmail } from "@/lib/validation";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [touched, setTouched] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const emailError = validateEmail(email);
  const isValid = !emailError;

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
      await forgotPassword(email);
      // Deliberately shown regardless of whether the email matched an
      // account — the backend never reveals that either.
      setSent(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      eyebrow="Outdoor Fix"
      title="Reset your password"
      subtitle="We'll email you a link to set a new one."
      footer={
        <Link href="/login" className="font-semibold text-neutral-900 underline">
          Back to sign in
        </Link>
      }
    >
      {sent ? (
        <p className="text-sm text-neutral-600">
          If an account exists for <span className="font-semibold">{email}</span>, a password reset link has been
          sent. Check your inbox.
        </p>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          {error && <p className="text-sm text-red-600">{error}</p>}

          <AuthField
            label="Email"
            id="email"
            type="email"
            required
            value={email}
            onChange={setEmail}
            onBlur={() => setTouched(true)}
            placeholder="you@outdoorfix.org"
            error={touched ? emailError : undefined}
          />

          <AuthSubmitButton disabled={submitting || !isValid}>
            {submitting ? "Sending…" : "Send reset link →"}
          </AuthSubmitButton>
        </form>
      )}
    </AuthCard>
  );
}
