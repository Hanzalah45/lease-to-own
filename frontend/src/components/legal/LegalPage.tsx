import Image from "next/image";
import Link from "next/link";

export interface LegalSection {
  heading: string;
  paragraphs?: string[];
  bullets?: string[];
}

interface LegalPageProps {
  title: string;
  updated: string;
  intro: string;
  sections: LegalSection[];
}

/**
 * Shared layout for the public Privacy Policy and Terms of Use pages. Plain static
 * content, no login or backend needed, in the same full-page card style as /faq.
 * Intuit asks for a public privacy policy and end-user license agreement link before
 * it issues production QuickBooks keys, which is why these pages exist. The wording
 * on the pages is a plain-language first draft for the business owner to review.
 */
export default function LegalPage({ title, updated, intro, sections }: LegalPageProps) {
  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-2xl py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">{title}</h1>
          <p className="mt-1 text-sm text-neutral-500">Last updated {updated}</p>
        </div>

        <div className="rounded-xl border border-neutral-200 bg-white p-6">
          <p className="text-sm leading-relaxed text-neutral-600">{intro}</p>

          {sections.map((section, index) => (
            <section key={section.heading} className="mt-6">
              <h2 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">
                {index + 1}. {section.heading}
              </h2>
              {section.paragraphs?.map((text) => (
                <p key={text} className="mt-2 text-sm leading-relaxed text-neutral-600">
                  {text}
                </p>
              ))}
              {section.bullets && (
                <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-neutral-600">
                  {section.bullets.map((text) => (
                    <li key={text}>{text}</li>
                  ))}
                </ul>
              )}
            </section>
          ))}
        </div>

        <div className="mt-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-sm text-neutral-500">
          <Link href="/privacy" className="font-semibold text-neutral-900 underline">
            Privacy Policy
          </Link>
          <Link href="/terms" className="font-semibold text-neutral-900 underline">
            Terms of Use
          </Link>
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        </div>
      </div>
    </main>
  );
}
