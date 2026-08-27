export function TakeActionBanner({
  title = "Take Action",
  description = "Approve to continue, or decline with a reason for the record.",
  primaryLabel,
  onPrimary,
  onDecline,
  disabled,
}: {
  title?: string;
  description?: string;
  primaryLabel: string;
  onPrimary: () => void;
  onDecline: () => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
      <div>
        <p className="text-sm font-bold text-amber-800">{title}</p>
        <p className="text-xs text-neutral-500">
          {disabled ? "You don't have permission to act on applications." : description}
        </p>
      </div>
      <div className="flex shrink-0 gap-2">
        <button
          onClick={onPrimary}
          disabled={disabled}
          className="font-heading flex items-center gap-1 rounded-md bg-green-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-40"
        >
          ✓ {primaryLabel}
        </button>
        <button
          onClick={onDecline}
          disabled={disabled}
          className="font-heading flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-3.5 py-2 text-xs font-bold text-red-600 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40"
        >
          ✕ Decline
        </button>
      </div>
    </div>
  );
}
