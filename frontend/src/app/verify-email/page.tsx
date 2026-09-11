"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { AuthCard } from "@/components/auth/AuthCard";
import { ApiError } from "@/lib/api";
import { verifyEmail } from "@/lib/auth";

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={null}>
      <VerifyEmailStatus />
    </Suspense>
  );
}

function VerifyEmailStatus() {
  const searchParams = useSearchParams();
  const id = searchParams.get("id");
  const hash = searchParams.get("hash");
  const expires = searchParams.get("expires");
  const signature = searchParams.get("signature");

  const hasAllParams = Boolean(id && hash && expires && signature);

  const [status, setStatus] = useState<"checking" | "success" | "error">(hasAllParams ? "checking" : "error");
  const [message, setMessage] = useState<string | null>(hasAllParams ? null : "This verification link is incomplete.");

  useEffect(() => {
    if (!id || !hash || !expires || !signature) return;

    verifyEmail({ id, hash, expires, signature })
      .then((data) => {
        setStatus("success");
        setMessage(data.message);
      })
      .catch((err) => {
        setStatus("error");
        setMessage(err instanceof ApiError ? err.message : "This verification link is invalid or has expired.");
      });
    // Runs once on mount with whatever the URL carried — the link is single-use in intent.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <AuthCard
      eyebrow="Prostart Leasing"
      title="Verify your email"
      subtitle={status === "checking" ? "One moment…" : "Account activation"}
      footer={
        status === "error" ? (
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in, you can resend the link there
          </Link>
        ) : (
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        )
      }
    >
      {status === "checking" && <p className="text-center text-sm text-neutral-600">Verifying your email…</p>}
      {status === "success" && <p className="text-center text-sm text-green-700">{message}</p>}
      {status === "error" && <p className="text-center text-sm text-red-600">{message}</p>}
    </AuthCard>
  );
}
