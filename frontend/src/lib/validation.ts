/**
 * Shared field-level validation rules, used by every form in the dashboard
 * (register, login, admin users, customers) so the same field is validated
 * the same way everywhere. Length limits are chosen deliberately per field
 * — mirroring either the backend's real `max:`/`min:` rule where one exists,
 * or a genuine real-world reference (RFC, a major API's own field limit,
 * NIST guidance) rather than reusing Laravel's VARCHAR(255) DB default,
 * which is a storage artifact, not a UX decision. Character-class and
 * complexity rules are a client-side-only tightening on top of that: they
 * only ever reject a subset of what the backend already accepts, so they
 * can never cause a client/server mismatch.
 */

// RFC 5321 4.5.3.1.3: 254 is the actual maximum length of an email address.
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export const EMAIL_MAX = 254;

// Any Unicode letter (\p{L}) — not just Latin+Western-European accents —
// plus spaces, apostrophes, hyphens, periods. Covers "O'Brien", "Jean-Luc",
// but also "José García", "Владимир", "陈明", "محمد": real customer names
// this business will see, none of which the backend itself restricts by
// character set (CustomerController only checks `string, max:255`) — a
// Latin-only pattern here would reject legitimate people the API accepts.
export const NAME_PATTERN = /^[\p{L}][\p{L}\s'.-]*$/u;
export const NAME_MIN = 2;
// Salesforce's own "Last Name" field cap — generous for long hyphenated or
// multi-part names, tight enough to reject pasted-in nonsense.
export const NAME_MAX = 80;
// Real municipality names never come close to this (the longest official
// US city names top out around 20-30 characters) — 50 leaves comfortable
// room without inviting nonsense input.
export const CITY_MAX = 50;

// Digits plus standard phone punctuation only — no letters.
export const PHONE_PATTERN = /^[0-9+()\-.\s]+$/;
export const PHONE_DIGITS_MIN = 10;
// E.164 caps an international number at 15 digits; 20 leaves room for
// formatting characters (spaces, dashes, parens, a leading +).
export const PHONE_MAX = 20;

export const STATE_PATTERN = /^[A-Z]{2}$/;
export const ZIP_PATTERN = /^\d{5}(-\d{4})?$/;

// A full US street line with a unit/suite ("1234 West Cottonwood Creek
// Blvd, Apt 456") rarely runs past 60-70 characters — 100 is comfortable
// headroom without inviting a pasted paragraph.
export const STREET_MIN = 5;
export const STREET_MAX = 100;

// No backend rule exists for this one at all (internal_notes is an
// unbounded TEXT column) — 1000 is a UX-only ceiling so an admin can't
// accidentally paste a whole document into a notes box, while still fitting
// several genuine sentences of context.
export const NOTES_MAX = 1000;

export const PASSWORD_MIN = 8; // NIST 800-63B's recommended floor
// bcrypt (Laravel's Hash::make default) only hashes the first 72 bytes of
// input — anything typed past that is silently ignored by the server, so
// capping input here isn't cosmetic, it prevents a password that "looks"
// different from being treated as identical by the backend.
export const PASSWORD_MAX = 72;
const PASSWORD_HAS_LETTER = /[A-Za-z]/;
const PASSWORD_HAS_NUMBER = /[0-9]/;

export function digitsOnly(value: string): string {
  return value.replace(/\D/g, "");
}

export function isoDateDaysAgo(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

export function validateName(value: string, label = "Name", max: number = NAME_MAX): string | undefined {
  const v = value.trim();
  if (!v) return `${label} is required.`;
  if (v.length < NAME_MIN) return `${label} must be at least ${NAME_MIN} characters.`;
  if (v.length > max) return `${label} must be ${max} characters or fewer.`;
  if (!NAME_PATTERN.test(v)) return `${label} can only contain letters, spaces, hyphens, and apostrophes.`;
  return undefined;
}

export function validateEmail(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "Email is required.";
  if (v.length > EMAIL_MAX) return `Email must be ${EMAIL_MAX} characters or fewer.`;
  if (!EMAIL_PATTERN.test(v)) return "Enter a valid email address.";
  return undefined;
}

export function validatePhone(value: string, required: boolean): string | undefined {
  const v = value.trim();
  if (!v) return required ? "Phone number is required." : undefined;
  if (v.length > PHONE_MAX) return `Phone must be ${PHONE_MAX} characters or fewer.`;
  if (!PHONE_PATTERN.test(v)) return "Phone can only contain digits (no letters or symbols other than + ( ) -).";
  if (digitsOnly(v).length < PHONE_DIGITS_MIN) return `Enter a valid phone number (at least ${PHONE_DIGITS_MIN} digits).`;
  return undefined;
}

export function validatePassword(value: string, required: boolean): string | undefined {
  if (!value) return required ? "Password is required." : undefined;
  if (value.length < PASSWORD_MIN) return `Password must be at least ${PASSWORD_MIN} characters.`;
  if (value.length > PASSWORD_MAX) return `Password must be ${PASSWORD_MAX} characters or fewer.`;
  if (!PASSWORD_HAS_LETTER.test(value) || !PASSWORD_HAS_NUMBER.test(value)) {
    return "Password must include at least one letter and one number.";
  }
  return undefined;
}

export function validateCity(value: string): string | undefined {
  return validateName(value, "City", CITY_MAX);
}

export function validateState(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "State is required.";
  if (!STATE_PATTERN.test(v)) return "Use the 2-letter state abbreviation (e.g. TX).";
  return undefined;
}

export function validateZip(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "Zip code is required.";
  if (!ZIP_PATTERN.test(v)) return "Enter a valid zip code (12345 or 12345-6789).";
  return undefined;
}

export function validateStreet(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "Street address is required.";
  if (v.length < STREET_MIN) return `Street address must be at least ${STREET_MIN} characters.`;
  if (v.length > STREET_MAX) return `Street address must be ${STREET_MAX} characters or fewer.`;
  return undefined;
}

export function validateNotes(value: string): string | undefined {
  if (value.length > NOTES_MAX) return `Internal notes must be ${NOTES_MAX} characters or fewer.`;
  return undefined;
}

export function validateDob(value: string): string | undefined {
  if (!value) return "Date of birth is required.";
  if (value > isoDateDaysAgo(0)) return "Date of birth cannot be in the future.";
  if (value < isoDateDaysAgo(365 * 120)) return "Enter a valid date of birth.";
  return undefined;
}
