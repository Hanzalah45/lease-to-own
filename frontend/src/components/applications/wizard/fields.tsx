import type { ChangeEvent, ReactNode } from "react";

const inputClass =
  "w-full rounded-md border border-neutral-200 px-3 py-2.5 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none";
const labelClass = "mb-1.5 block text-sm font-semibold text-neutral-800";

export function Field({
  label,
  required,
  hint,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <label className={labelClass}>
        {label} {required && <span className="text-red-600">*</span>}
      </label>
      {children}
      {hint && <p className="mt-1 text-xs text-neutral-400">{hint}</p>}
    </div>
  );
}

export function TextInput({
  value,
  onChange,
  placeholder,
  type = "text",
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  type?: string;
}) {
  return (
    <input
      type={type}
      className={inputClass}
      placeholder={placeholder}
      value={value}
      onChange={(e: ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
    />
  );
}

export function SelectInput({
  value,
  onChange,
  options,
  placeholder,
}: {
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
  placeholder?: string;
}) {
  return (
    <select className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="" disabled>
        {placeholder ?? "Select…"}
      </option>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

export function RadioGroup({
  value,
  onChange,
  options,
}: {
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
}) {
  return (
    <div className="flex items-center gap-5 pt-1">
      {options.map((o) => (
        <label key={o.value} className="flex cursor-pointer items-center gap-2 text-sm text-neutral-800">
          <input
            type="radio"
            checked={value === o.value}
            onChange={() => onChange(o.value)}
            className="h-4 w-4 accent-red-600"
          />
          {o.label}
        </label>
      ))}
    </div>
  );
}

export function TextArea({
  value,
  onChange,
  placeholder,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}) {
  return (
    <textarea
      rows={3}
      className={inputClass}
      placeholder={placeholder}
      value={value}
      onChange={(e) => onChange(e.target.value)}
    />
  );
}
