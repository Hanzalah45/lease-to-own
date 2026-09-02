"use client";

import { useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { NOTES_MAX, validateNotes } from "@/lib/validation";
import type { DealerNote } from "@/components/applications/detail/types";

export function DealerNotes({
  notes,
  onAdd,
  posting = false,
}: {
  notes: DealerNote[];
  onAdd: (text: string) => void;
  posting?: boolean;
}) {
  const [draft, setDraft] = useState("");
  const [touched, setTouched] = useState(false);

  const draftError = draft.trim() ? validateNotes(draft) : undefined;
  const canPost = draft.trim().length > 0 && !draftError && !posting;

  function submit() {
    if (draft.trim().length === 0 || draftError) {
      setTouched(true);
      return;
    }
    if (!canPost) return;
    onAdd(draft.trim());
    setDraft("");
    setTouched(false);
  }

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Dealer notes" subtitle="Two-way · visible to ProStart & the dealer" />
      <div className="mt-4 space-y-3">
        {notes.map((note) => (
          <div key={note.id} className={`border-l-2 pl-3 ${note.isDealer ? "border-red-400" : "border-blue-400"}`}>
            <p className="flex items-center gap-2 text-xs text-neutral-400">
              {note.isDealer ? (
                <>
                  <span>{note.time}</span>
                  <span className="font-heading rounded bg-red-600 px-1.5 py-0.5 text-[9px] font-bold uppercase text-white">
                    Dealer
                  </span>
                </>
              ) : (
                note.author
              )}
            </p>
            <p className="mt-1 text-sm text-neutral-700">{note.text}</p>
          </div>
        ))}
      </div>
      <textarea
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={() => setTouched(true)}
        rows={2}
        placeholder="Write a note..."
        aria-label="Write a note"
        aria-invalid={touched && !!draftError}
        className={`mt-4 w-full rounded-md border px-3 py-2 text-sm placeholder:text-neutral-400 focus:outline-none ${
          touched && draftError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
        }`}
      />
      <div className="mt-1 flex items-start justify-between gap-2">
        {touched && draftError ? <p className="text-xs text-red-600">{draftError}</p> : <span />}
        <p className={`shrink-0 text-xs ${draft.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}>
          {draft.length}/{NOTES_MAX}
        </p>
      </div>
      <button
        onClick={submit}
        disabled={draft.trim().length === 0 || !!draftError || posting}
        className="font-heading mt-2 w-full rounded-md bg-red-600 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {posting ? "Posting…" : "Post Note"}
      </button>
    </div>
  );
}
