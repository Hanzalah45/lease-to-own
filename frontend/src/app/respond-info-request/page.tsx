"use client";

import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { FileInput } from "@/components/applications/wizard/fields";
import {
  getInfoRequestViaLink,
  respondToInfoRequestViaLink,
  type SignedInfoRequestLinkParams,
  type SignedInfoRequestState,
} from "@/lib/applications";
import { ApiError } from "@/lib/api";
import { NOTES_MAX, validateNotes } from "@/lib/validation";

/**
 * Public counterpart to the customer portal's "needs info" reply step —
 * reached from the signed link ApplicationInfoRequestedNotification emails
 * when an admin asks for more information. Exists because a guest-originated
 * customer's account has no usable password until first payment/pickup,
 * which can easily happen well after an admin needs to ask them something —
 * see InfoRequestSigner on the backend.
 */
export default function RespondInfoRequestPage() {
  return (
    <Suspense fallback={null}>
      <RespondInfoRequestFlow />
    </Suspense>
  );
}

function RespondInfoRequestFlow() {
  const searchParams = useSearchParams();
  const id = searchParams.get("id");
  const application = searchParams.get("application");
  const hash = searchParams.get("hash");
  const expires = searchParams.get("expires");
  const signature = searchParams.get("signature");
  const hasAllParams = Boolean(id && application && hash && expires && signature);
  const params: SignedInfoRequestLinkParams | null = hasAllParams
    ? { id: id!, application: application!, hash: hash!, expires: expires!, signature: signature! }
    : null;

  const [state, setState] = useState<SignedInfoRequestState | null>(null);
  const [loading, setLoading] = useState(hasAllParams);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [replyText, setReplyText] = useState("");
  const [newDocument, setNewDocument] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [responded, setResponded] = useState(false);

  useEffect(() => {
    if (!params) return;
    getInfoRequestViaLink(params)
      .then(setState)
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "This link is invalid or has expired."))
      .finally(() => setLoading(false));
    // Runs once on mount with whatever the URL carried.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const replyTextError = replyText.trim() ? validateNotes(replyText) : undefined;
  const canRespond = (!!replyText.trim() || !!newDocument) && !replyTextError && !sending;

  async function handleRespond() {
    if (!params || !canRespond) return;
    setSending(true);
    setSendError(null);
    try {
      setState(await respondToInfoRequestViaLink(params, { replyText: replyText.trim() || undefined, file: newDocument }));
      setReplyText("");
      setNewDocument(null);
      setResponded(true);
    } catch (err) {
      setSendError(err instanceof ApiError ? err.message : "Could not send your response. Please try again.");
    } finally {
      setSending(false);
    }
  }

  const stillOpen = state?.status === "needs_info" && !!state.open_request_text && !responded;

  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-xl py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Respond to Prostart Leasing</h1>
          <p className="mt-1 text-sm text-neutral-500">A quick reply keeps your application moving.</p>
        </div>

        {!hasAllParams ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-red-600">This link is incomplete.</p>
          </div>
        ) : loading ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-neutral-400">Checking link…</p>
          </div>
        ) : loadError || !state ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-red-600">{loadError ?? "Application not found."}</p>
          </div>
        ) : !stillOpen ? (
          <div className="rounded-xl border border-green-200 bg-green-50 p-5">
            <p className="text-sm font-bold text-green-700">{responded ? "Response sent" : "Already answered"}</p>
            <p className="mt-1 text-sm text-neutral-600">
              {responded
                ? "Thanks — your response was sent back to Prostart Leasing for review."
                : "This request has already been answered. No action needed."}
            </p>
          </div>
        ) : (
          <div className="rounded-xl border border-amber-200 bg-amber-50 p-5">
            <p className="text-sm font-bold text-amber-800">Action required</p>
            <p className="text-sm text-neutral-700">{state.open_request_text}</p>
            <div className="mt-4 space-y-3">
              <div>
                <textarea
                  value={replyText}
                  onChange={(e) => setReplyText(e.target.value)}
                  rows={3}
                  placeholder="Type a reply (optional if you're attaching a document below)..."
                  aria-label="Reply to Prostart Leasing"
                  className={`w-full rounded-md border bg-white px-3 py-2 text-sm placeholder:text-neutral-400 focus:outline-none ${
                    replyTextError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-amber-300"
                  }`}
                />
                <div className="mt-1 flex items-start justify-between gap-2">
                  {replyTextError ? <p className="text-xs text-red-600">{replyTextError}</p> : <span />}
                  <p className={`shrink-0 text-xs ${replyText.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}>
                    {replyText.length}/{NOTES_MAX}
                  </p>
                </div>
              </div>
              <FileInput value={newDocument} onChange={setNewDocument} />
              {sendError && <p className="text-xs text-red-600">{sendError}</p>}
              <button
                onClick={handleRespond}
                disabled={!canRespond}
                className="font-heading w-full rounded-md bg-amber-600 py-3 text-sm font-bold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-40"
              >
                {sending ? "Sending…" : "Send Response"}
              </button>
            </div>
          </div>
        )}

        <div className="mt-6 text-center text-sm text-neutral-500">
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        </div>
      </div>
    </main>
  );
}
