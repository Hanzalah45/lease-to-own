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

  const clientErrors: Record<string, string> = {};
  if (!email.trim()) clientErrors.email = "Email is required.";
  if (!password) clientErrors.password = "Password is required.";

  const isValid = Object.keys(clientErrors).length === 0;

  function fieldError(key: string): string | undefined {
    return serverErrors[key] ?? (touched[key] ? clientErrors[key] : undefined);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return; // guard against a double-click/double-Enter race
    setError(null);

    if (!isValid) {
      setTouched({ email: true, password: true });
      return;
    }

    setSubmitting(true);

    try {
      const { user } = await login(email, password);
      await refresh();
      const next = searchParams.get("next") ?? dashboardPathForRole(user.role);
      router.push(next);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setServerErrors(
          Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])),
        );
        setError(null);
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
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        {error && <p className="text-sm text-red-600">{error}</p>}

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
          error={fieldError("email")}
        />
        <AuthField
          label="Password"
          id="password"
          type="password"
          required
          value={password}
          onChange={(v) => {
            setPassword(v);
            clearServerError("password");
          }}
          onBlur={() => touch("password")}
          error={fieldError("password")}
        />

        <AuthSubmitButton disabled={submitting || !isValid}>
          {submitting ? "Signing in…" : "Sign in →"}
        </AuthSubmitButton>
      </form>
    </AuthCard>
  );
}
