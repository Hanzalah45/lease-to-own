"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";
import { AuthCard, AuthField, AuthSubmitButton } from "@/components/auth/AuthCard";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import { dashboardPathForRole, login } from "@/lib/auth";

export default function LoginPage() {
  return (
    <Suspense fallback={null}>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { refresh } = useAuth();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);

    try {
      const { user } = await login(email, password);
      await refresh();
      const next = searchParams.get("next") ?? dashboardPathForRole(user.role);
      router.push(next);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setFieldErrors(err.errors);
      } else {
        setError(err instanceof ApiError ? err.message : "Something went wrong.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      eyebrow="Outdoor Fix"
      title="Sign in"
      subtitle="Access your customer or admin portal."
      footer={
        <>
          No account?{" "}
          <Link href="/register" className="font-semibold text-neutral-900 underline">
            Create an account
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <p className="text-sm text-red-600">{error}</p>}

        <AuthField
          label="Email"
          id="email"
          type="email"
          required
          value={email}
          onChange={setEmail}
          error={fieldErrors.email?.[0]}
        />
        <AuthField
          label="Password"
          id="password"
          type="password"
          required
          value={password}
          onChange={setPassword}
          error={fieldErrors.password?.[0]}
        />

        <AuthSubmitButton disabled={submitting}>
          {submitting ? "Signing in…" : "Sign in →"}
        </AuthSubmitButton>
      </form>
    </AuthCard>
  );
}
