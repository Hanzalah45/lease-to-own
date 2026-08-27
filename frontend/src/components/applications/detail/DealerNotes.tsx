"use client";

import { useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import type { DealerNote } from "@/components/applications/detail/types";

export function DealerNotes({ notes, onAdd }: { notes: DealerNote[]; onAdd: (text: string) => void }) {
  const [draft, setDraft] = useState("");

  function submit() {
    if (!draft.trim()) return;
    onAdd(draft.trim());
    setDraft("");
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
        rows={2}
        placeholder="Write a note..."
        className="mt-4 w-full rounded-md border border-neutral-200 px-3 py-2 text-sm placeholder:text-neutral-400 focus:border-red-300 focus:outline-none"
      />
      <button
        onClick={submit}
        className="font-heading mt-2 w-full rounded-md bg-red-600 py-2 text-sm font-bold text-white hover:bg-red-700"
      >
        Post Note
      </button>
    </div>
  );
}
