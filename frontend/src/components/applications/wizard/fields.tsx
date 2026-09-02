import {
  cloneElement,
  isValidElement,
  useId,
  useRef,
  useState,
  type ChangeEvent,
  type DragEvent,
  type ReactElement,
  type ReactNode,
} from "react";
import { CheckCircleIcon, UploadIcon, XIcon } from "@/components/icons";

const inputBaseClass =
  "w-full rounded-md border px-3 py-2.5 text-sm transition-colors focus:outline-none";
const inputNormalClass =
  "border-neutral-200 text-neutral-800 placeholder:text-neutral-400 focus:border-red-500 focus:ring-1 focus:ring-red-500/20";
const inputErrorClass =
  "border-red-500 bg-red-50/20 text-neutral-900 placeholder:text-red-300 focus:border-red-600 focus:ring-1 focus:ring-red-600";
const labelClass = "mb-1.5 block text-sm font-semibold text-neutral-800";

interface A11yProps {
  id?: string;
  "aria-invalid"?: boolean;
  "aria-describedby"?: string;
}

export function Field({
  label,
  required,
  hint,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: ReactNode;
}) {
  const id = useId();
  const errorId = `${id}-error`;

  const child = isValidElement(children)
    ? cloneElement(children as ReactElement<A11yProps>, {
        id,
        "aria-invalid": !!error,
        "aria-describedby": error ? errorId : undefined,
      })
    : children;

  return (
    <div>
      <label htmlFor={id} className={labelClass}>
        {label} {required && <span className="font-bold text-red-600">*</span>}
      </label>
      {child}
      {error ? (
        <p id={errorId} className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-red-600">
          <span className="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-red-600" />
          {error}
        </p>
      ) : (
        hint && <p className="mt-1 text-xs text-neutral-400">{hint}</p>
      )}
    </div>
  );
}

export function TextInput({
  value,
  onChange,
  placeholder,
  type = "text",
  hasError,
  id,
  ...a11y
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  type?: string;
  hasError?: boolean;
} & A11yProps) {
  return (
    <input
      id={id}
      type={type}
      className={`${inputBaseClass} ${hasError ? inputErrorClass : inputNormalClass}`}
      placeholder={placeholder}
      value={value}
      onChange={(e: ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
      {...a11y}
    />
  );
}

export function SelectInput({
  value,
  onChange,
  options,
  placeholder,
  hasError,
  id,
  ...a11y
}: {
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
  placeholder?: string;
  hasError?: boolean;
} & A11yProps) {
  return (
    <select
      id={id}
      className={`${inputBaseClass} ${hasError ? inputErrorClass : inputNormalClass}`}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      {...a11y}
    >
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

export function FileInput({
  value,
  onChange,
  accept = "image/*,.pdf",
  hasError,
  id,
  ...a11y
}: {
  value: File | null;
  onChange: (file: File | null) => void;
  accept?: string;
  hasError?: boolean;
} & A11yProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);

  function pick(files: FileList | null) {
    const file = files?.[0];
    if (file) onChange(file);
  }

  if (value) {
    return (
      <div
        id={id}
        {...a11y}
        className="flex items-center justify-between gap-3 rounded-md border border-neutral-200 bg-neutral-50 px-4 py-3"
      >
        <div className="flex min-w-0 items-center gap-2">
          <CheckCircleIcon className="h-4 w-4 shrink-0 text-green-600" />
          <span className="truncate text-sm font-medium text-neutral-700">{value.name}</span>
          <span className="shrink-0 text-xs text-neutral-400">({(value.size / 1024).toFixed(0)} KB)</span>
        </div>
        <button
          type="button"
          onClick={() => onChange(null)}
          className="shrink-0 rounded-full p-1 text-neutral-400 hover:bg-neutral-200 hover:text-neutral-700"
          aria-label="Remove file"
        >
          <XIcon className="h-4 w-4" />
        </button>
      </div>
    );
  }

  return (
    <div
      id={id}
      {...a11y}
      onClick={() => inputRef.current?.click()}
      onDragOver={(e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setDragOver(true);
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setDragOver(false);
        pick(e.dataTransfer.files);
      }}
      className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border border-dashed py-8 text-sm transition ${
        hasError
          ? "border-red-500 bg-red-50/30 text-red-600"
          : dragOver
          ? "border-red-400 bg-red-50 text-red-500"
          : "border-neutral-300 text-neutral-400 hover:border-red-300 hover:bg-red-50/40"
      }`}
    >
      <UploadIcon className={`h-5 w-5 ${hasError ? "text-red-500" : ""}`} />
      <span className={hasError ? "font-medium text-red-600" : ""}>Browse files or drag &amp; drop</span>
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        className="hidden"
        onChange={(e: ChangeEvent<HTMLInputElement>) => pick(e.target.files)}
      />
    </div>
  );
}

export function RadioGroup({
  value,
  onChange,
  options,
  hasError,
  id,
  ...a11y
}: {
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
  hasError?: boolean;
} & A11yProps) {
  return (
    <div
      id={id}
      role="radiogroup"
      {...a11y}
      className={`flex items-center gap-5 pt-1 rounded-md px-2 py-1 ${hasError ? "bg-red-50/40 border border-red-300" : ""}`}
    >
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
  hasError,
  id,
  ...a11y
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  hasError?: boolean;
} & A11yProps) {
  return (
    <textarea
      id={id}
      rows={3}
      className={`${inputBaseClass} ${hasError ? inputErrorClass : inputNormalClass}`}
      placeholder={placeholder}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      {...a11y}
    />
  );
}
