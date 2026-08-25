"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { AuthCard, AuthField, AuthSubmitButton } from "@/components/auth/AuthCard";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import { dashboardPathForRole, register } from "@/lib/auth";

export default function RegisterPage() {
  const router = useRouter();
  const { refresh } = useAuth();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
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
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
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
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <p className="text-sm text-red-600">{error}</p>}

        <AuthField label="Full name" id="name" required value={name} onChange={setName} />

        <div className="grid grid-cols-2 gap-3">
          <AuthField
            label="Email"
            id="email"
            type="email"
            required
            value={email}
            onChange={setEmail}
            placeholder="you@outdoorfix.org"
          />
          <AuthField
            label="Phone"
            id="phone"
            value={phone}
            onChange={setPhone}
            placeholder="(000) 000-0000"
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <AuthField
            label="Password"
            id="password"
            type="password"
            required
            minLength={8}
            value={password}
            onChange={setPassword}
          />
          <AuthField
            label="Confirm password"
            id="password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={setPasswordConfirmation}
          />
        </div>

        <AuthSubmitButton disabled={submitting}>
          {submitting ? "Creating account…" : "Create account →"}
        </AuthSubmitButton>

        <p className="text-center text-xs text-neutral-400">
          Admin accounts are created by an existing admin, not here.
        </p>
      </form>
    </AuthCard>
  );
}
