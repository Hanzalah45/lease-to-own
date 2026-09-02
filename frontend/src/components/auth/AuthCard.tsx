import Image from "next/image";
import { useState, type ReactNode } from "react";
import { EyeIcon, EyeOffIcon } from "@/components/icons";

export function AuthCard({
  eyebrow,
  title,
  subtitle,
  children,
  footer,
}: {
  eyebrow: string;
  title: string;
  subtitle: string;
  children: ReactNode;
  footer: ReactNode;
}) {
  return (
    <main
      className="flex flex-1 items-center justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl shadow-black/5">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Outdoor Fix" width={159} height={103} className="mb-3 h-20 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">
            {eyebrow}
          </p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">
            {title}
          </h1>
          <p className="mt-1 text-sm text-neutral-500">{subtitle}</p>
        </div>

        {children}

        <div className="mt-6 text-center text-sm text-neutral-500">{footer}</div>
      </div>
    </main>
  );
}

export function AuthField({
  label,
  id,
  type = "text",
  value,
  onChange,
  onBlur,
  required,
  minLength,
  placeholder,
  error,
}: {
  label: string;
  id: string;
  type?: string;
  value: string;
  onChange: (value: string) => void;
  onBlur?: () => void;
  required?: boolean;
  minLength?: number;
  placeholder?: string;
  error?: string | null;
}) {
  const [revealed, setRevealed] = useState(false);
  const isPassword = type === "password";

  return (
    <div className="space-y-1">
      <label htmlFor={id} className="font-heading text-sm font-semibold text-neutral-700">
        {label}
        {required && <span className="ml-0.5 text-red-600">*</span>}
      </label>
      <div className="relative">
        <input
          id={id}
          type={isPassword && revealed ? "text" : type}
          required={required}
          minLength={minLength}
          placeholder={placeholder}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          onBlur={onBlur}
          aria-invalid={!!error}
          aria-describedby={error ? `${id}-error` : undefined}
          className={`w-full rounded-md border px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none focus:ring-1 ${
            isPassword ? "pr-10" : ""
          } ${
            error
              ? "border-red-400 focus:border-red-600 focus:ring-red-600"
              : "border-neutral-300 focus:border-red-600 focus:ring-red-600"
          }`}
        />
        {isPassword && (
          <button
            type="button"
            onClick={() => setRevealed((v) => !v)}
            tabIndex={-1}
            aria-label={revealed ? "Hide password" : "Show password"}
            className="absolute inset-y-0 right-0 flex items-center px-3 text-neutral-400 hover:text-neutral-600"
          >
            {revealed ? <EyeOffIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
          </button>
        )}
      </div>
      {error && (
        <p id={`${id}-error`} className="text-xs font-medium text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

export function AuthSubmitButton({ children, disabled }: { children: ReactNode; disabled?: boolean }) {
  return (
    <button
      type="submit"
      disabled={disabled}
      className="font-heading w-full rounded-md bg-red-600 px-4 py-2.5 text-base font-bold uppercase tracking-wide text-white transition hover:bg-red-700 disabled:opacity-50"
    >
      {children}
    </button>
  );
}
