/**
 * Date-only values (a payment's due_date or paid_date, a lease's start date)
 * are serialized by the API as ISO timestamps at UTC midnight, e.g.
 * "2026-11-01T00:00:00.000000Z". Feeding that to `new Date(...)` and calling
 * toLocaleDateString() shifts it into the browser's time zone, so for a
 * customer in Texas the 1st shows as "10/31". These values are calendar
 * dates, not moments in time: read the YYYY-MM-DD part and build the date
 * from its components so no time zone is ever applied.
 *
 * Real timestamps (created_at, signed_at) should keep using the browser's
 * local time and do NOT go through this.
 */
export function formatDateOnly(value: string | null | undefined, fallback = "—"): string {
  if (!value) return fallback;

  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!match) return fallback;

  const [, year, month, day] = match;
  return new Date(Number(year), Number(month) - 1, Number(day)).toLocaleDateString();
}

/** True when a calendar date (YYYY-MM-DD prefix) is strictly before today's date in the browser's own time zone. */
export function isBeforeToday(value: string | null | undefined): boolean {
  const match = value ? /^(\d{4}-\d{2}-\d{2})/.exec(value) : null;
  if (!match) return false;

  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

  return match[1] < today;
}
