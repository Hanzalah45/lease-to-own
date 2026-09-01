"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { AuthCard, AuthField, AuthSubmitButton } from "@/components/auth/AuthCard";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import { dashboardPathForRole, register } from "@/lib/auth";
import { validateEmail, validateName, validatePassword, validatePhone } from "@/lib/validation";

export default function RegisterPage() {
  const router = useRouter();
  const { refresh } = useAuth();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitting, setSubmitting] = useState(false);

  function touch(key: string) {
    setTouched((prev) => (prev[key] ? prev : { ...prev, [key]: true }));
  }

  function clearServerError(key: string) {
    setServerErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  }

  // Same shared validators (@/lib/validation) used across every form in the
  // dashboard. Phone stays optional here (unlike the admin customer form) —
  // this is a public self-signup, and the backend treats phone as nullable.
  const clientErrors: Record<string, string> = {};
  const nameErr = validateName(name, "Full name");
  if (nameErr) clientErrors.name = nameErr;
  const emailErr = validateEmail(email);
  if (emailErr) clientErrors.email = emailErr;
  const phoneErr = validatePhone(phone, false);
  if (phoneErr) clientErrors.phone = phoneErr;
  const passwordErr = validatePassword(password, true);
  if (passwordErr) clientErrors.password = passwordErr;
  if (!passwordConfirmation) clientErrors.password_confirmation = "Please confirm your password.";
  else if (password !== passwordConfirmation) clientErrors.password_confirmation = "Passwords do not match.";

  const isValid = Object.keys(clientErrors).length === 0;

  // Server error takes priority (it's authoritative); otherwise show the
  // live client-side error once the field has been touched (blurred).
  function fieldError(key: string): string | undefined {
    return serverErrors[key] ?? (touched[key] ? clientErrors[key] : undefined);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return; // guard against a double-click/double-Enter race
    setError(null);

    if (!isValid) {
      setTouched({ name: true, email: true, phone: true, password: true, password_confirmation: true });
      return;
    }

    setSubmitting(true);

    try {
      const { user } = await register({
        name,
        email,
        phone: phone || undefined,
        password,
        password_confirmation: passwordConfirmation,
      });
      await refresh();
      router.push(dashboardPathForRole(user.role));
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        // Each message is already shown inline under its field — no need
        // to repeat it in a summary banner too.
        setServerErrors(
          Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])),
        );
      } else {
        setError(err instanceof ApiError ? err.message : "Something went wrong.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      eyebrow="Outdoor Fix · Get the equipment. Get to work."
      title="Create account"
      subtitle="Set up your customer account."
      footer={
        <>
          Already have an account?{" "}
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Sign in
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        {error && <p className="text-sm text-red-600">{error}</p>}

        <AuthField
          label="Full name"
          id="name"
          required
          value={name}
          onChange={(v) => {
            setName(v);
            clearServerError("name");
          }}
          onBlur={() => touch("name")}
          error={fieldError("name")}
        />

        <div className="grid grid-cols-2 gap-3">
          <AuthField
            label="Email"
            id="email"
            type="email"
            required
            value={email}
            onChange={(v) => {
              setEmail(v);
              clearServerError("email");
            }}
            onBlur={() => touch("email")}
            placeholder="you@outdoorfix.org"
            error={fieldError("email")}
          />
          <AuthField
            label="Phone"
            id="phone"
            value={phone}
            onChange={(v) => {
              setPhone(v);
              clearServerError("phone");
            }}
            onBlur={() => touch("phone")}
            placeholder="(000) 000-0000"
            error={fieldError("phone")}
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <AuthField
            label="Password"
            id="password"
            type="password"
            required
            minLength={8}
            placeholder="Letter + number, 8+ chars"
            value={password}
            onChange={(v) => {
              setPassword(v);
              clearServerError("password");
            }}
            onBlur={() => touch("password")}
            error={fieldError("password")}
          />
          <AuthField
            label="Confirm password"
            id="password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={(v) => {
              setPasswordConfirmation(v);
              clearServerError("password_confirmation");
            }}
            onBlur={() => touch("password_confirmation")}
            error={fieldError("password_confirmation")}
          />
        </div>

        <AuthSubmitButton disabled={submitting || !isValid}>
          {submitting ? "Creating account…" : "Create account →"}
        </AuthSubmitButton>

        <p className="text-center text-xs text-neutral-400">
          Admin accounts are created by an existing admin, not here.
        </p>
      </form>
    </AuthCard>
  );
}
