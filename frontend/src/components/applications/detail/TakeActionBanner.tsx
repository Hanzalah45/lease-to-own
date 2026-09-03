export function TakeActionBanner({
  title = "Take Action",
  description = "Approve to continue, decline with a reason, or request more info from the customer.",
  primaryLabel,
  onPrimary,
  onDecline,
  onRequestInfo,
  disabled,
}: {
  title?: string;
  description?: string;
  primaryLabel?: string;
  onPrimary?: () => void;
  onDecline: () => void;
  onRequestInfo?: () => void;
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
      <div className="flex shrink-0 flex-wrap gap-2">
        {primaryLabel && onPrimary && (
          <button
            onClick={onPrimary}
            disabled={disabled}
            className="font-heading flex items-center gap-1 rounded-md bg-green-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            ✓ {primaryLabel}
          </button>
        )}
        {onRequestInfo && (
          <button
            onClick={onRequestInfo}
            disabled={disabled}
            className="font-heading flex items-center gap-1 rounded-md border border-amber-300 bg-white px-3.5 py-2 text-xs font-bold text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40"
          >
            ? Request Info
          </button>
        )}
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
