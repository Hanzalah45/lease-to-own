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

/* ----------------------------------------------------------------------------
 * Equipment tracking (Milestone 4)
 *
 * Same principle as above: each rule only ever rejects a subset of what the
 * API already accepts, so the form can never refuse something the backend
 * would have stored. The backend caps most of these at `max:255` (the Laravel
 * string default) — that is a storage artifact, so the real-world shape of
 * the field is used here instead.
 * ------------------------------------------------------------------------- */

// Serial numbers are machine-stamped identifiers: letters, digits and the
// separators manufacturers actually use. Spaces are excluded deliberately —
// the whole module is keyed on this value, and a stray space makes a unit
// impossible to find by search or match against a delivery note.
export const SERIAL_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._/-]*$/;
export const SERIAL_MIN = 3;
// Longest real equipment serials (Deere, Kubota, Scag) run to ~17 characters;
// 50 is generous headroom without allowing a pasted sentence.
export const SERIAL_MAX = 50;

// Model names are free text — they legitimately contain digits, quotes and
// punctuation ('Worldlawn Diamondback 60"'), so only length is constrained.
export const EQUIPMENT_MODEL_MIN = 2;
export const EQUIPMENT_MODEL_MAX = 100;

// Mowers and trailers do not all carry a 17-character road VIN, so this is a
// permissive identifier check rather than an ISO 3779 VIN check: no spaces,
// no punctuation beyond a hyphen.
export const VIN_PATTERN = /^[A-Za-z0-9-]+$/;
export const VIN_MIN = 5;
export const VIN_MAX = 20;

// Phase 2 placeholder. Provider device IDs are opaque tokens — accept the
// characters they actually use, reject anything with whitespace in it.
export const GPS_DEVICE_ID_PATTERN = /^[A-Za-z0-9._:-]+$/;
export const GPS_DEVICE_ID_MIN = 3;
export const GPS_DEVICE_ID_MAX = 64;

// Mirrors EquipmentServiceRecordController's `max:2000` exactly.
export const SERVICE_DESCRIPTION_MIN = 3;
export const SERVICE_DESCRIPTION_MAX = 2000;

// A tracking date before this is a typo (a mistyped year), not a real record.
export const EARLIEST_TRACKING_DATE = "2000-01-01";
// Deliveries can be scheduled ahead, but not by decades — another typo guard.
export const MAX_FUTURE_TRACKING_DAYS = 365 * 5;

export function isoDateDaysAhead(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

export function validateSerialNumber(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "Serial number is required.";
  if (v.length < SERIAL_MIN) return `Serial number must be at least ${SERIAL_MIN} characters.`;
  if (v.length > SERIAL_MAX) return `Serial number must be ${SERIAL_MAX} characters or fewer.`;
  if (!SERIAL_PATTERN.test(v)) {
    return "Serial number can only contain letters, digits and - . _ / (no spaces).";
  }
  return undefined;
}

export function validateEquipmentModel(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "Model is required.";
  if (v.length < EQUIPMENT_MODEL_MIN) return `Model must be at least ${EQUIPMENT_MODEL_MIN} characters.`;
  if (v.length > EQUIPMENT_MODEL_MAX) return `Model must be ${EQUIPMENT_MODEL_MAX} characters or fewer.`;
  return undefined;
}

export function validateVin(value: string): string | undefined {
  const v = value.trim();
  if (!v) return undefined; // optional
  if (v.length < VIN_MIN) return `VIN must be at least ${VIN_MIN} characters.`;
  if (v.length > VIN_MAX) return `VIN must be ${VIN_MAX} characters or fewer.`;
  if (!VIN_PATTERN.test(v)) return "VIN can only contain letters, digits and hyphens (no spaces).";
  return undefined;
}

export function validateGpsDeviceId(value: string): string | undefined {
  const v = value.trim();
  if (!v) return undefined; // optional — Phase 2 field
  if (v.length < GPS_DEVICE_ID_MIN) return `Device ID must be at least ${GPS_DEVICE_ID_MIN} characters.`;
  if (v.length > GPS_DEVICE_ID_MAX) return `Device ID must be ${GPS_DEVICE_ID_MAX} characters or fewer.`;
  if (!GPS_DEVICE_ID_PATTERN.test(v)) {
    return "Device ID can only contain letters, digits and - . _ : (no spaces).";
  }
  return undefined;
}

export function validateConditionNotes(value: string): string | undefined {
  if (value.length > NOTES_MAX) return `Condition notes must be ${NOTES_MAX} characters or fewer.`;
  return undefined;
}

export function validateServiceDescription(value: string): string | undefined {
  const v = value.trim();
  if (!v) return "Describe what was done.";
  if (v.length < SERVICE_DESCRIPTION_MIN) {
    return `Description must be at least ${SERVICE_DESCRIPTION_MIN} characters.`;
  }
  if (v.length > SERVICE_DESCRIPTION_MAX) {
    return `Description must be ${SERVICE_DESCRIPTION_MAX} characters or fewer.`;
  }
  return undefined;
}

/**
 * Shared sanity check for the equipment date fields. `allowFuture` is off for
 * dates that record something that already happened (a service visit), and on
 * for dates that can legitimately be scheduled ahead (delivery, ownership).
 */
export function validateTrackingDate(
  value: string,
  label: string,
  { required = false, allowFuture = true }: { required?: boolean; allowFuture?: boolean } = {},
): string | undefined {
  if (!value) return required ? `${label} is required.` : undefined;
  if (Number.isNaN(new Date(value).getTime())) return `Enter a valid ${label.toLowerCase()}.`;
  if (value < EARLIEST_TRACKING_DATE) return `${label} looks wrong — check the year.`;
  if (!allowFuture && value > isoDateDaysAgo(0)) return `${label} cannot be in the future.`;
  if (allowFuture && value > isoDateDaysAhead(MAX_FUTURE_TRACKING_DAYS)) {
    return `${label} is too far in the future.`;
  }
  return undefined;
}

/* ----------------------------------------------------------------------------
 * Generic helpers, shared by the inline detail editors
 * ------------------------------------------------------------------------- */

/**
 * Wraps a required-by-default rule so a blank value passes. Use for fields the
 * API stores as nullable — an admin must be able to save a card that has an
 * address line still empty, but a non-empty one still has to be well formed.
 */
export function optional(rule: (value: string) => string | undefined) {
  return (value: string): string | undefined => (value.trim() ? rule(value) : undefined);
}

export function validateIntegerInRange(
  value: string,
  label: string,
  min: number,
  max: number,
): string | undefined {
  const v = value.trim();
  if (!v) return `${label} is required.`;
  if (!/^\d+$/.test(v)) return `${label} must be a whole number.`;
  const n = Number(v);
  if (n < min || n > max) return `${label} must be between ${min} and ${max}.`;
  return undefined;
}

/**
 * Money fields are posted as `numeric, min:0`. Two decimal places is the cap
 * the DB columns actually store (decimal(10,2)) — more would be silently
 * rounded server-side, so reject it here instead of saving something the
 * admin did not type.
 */
export function validateMoney(
  value: string,
  label: string,
  { required = true, max = 99999999.99 }: { required?: boolean; max?: number } = {},
): string | undefined {
  const v = value.trim();
  if (!v) return required ? `${label} is required.` : undefined;
  if (!/^\d+(\.\d{1,2})?$/.test(v)) return `${label} must be an amount like 250 or 250.00.`;
  if (Number(v) > max) return `${label} is too large.`;
  return undefined;
}

// Mirrors ApplicationController's `lease.promo_code => max:60`.
export const PROMO_CODE_MAX = 60;
export const PROMO_CODE_PATTERN = /^[A-Za-z0-9_-]+$/;

export function validatePromoCode(value: string): string | undefined {
  const v = value.trim();
  if (!v) return undefined; // optional
  if (v.length > PROMO_CODE_MAX) return `Promo code must be ${PROMO_CODE_MAX} characters or fewer.`;
  if (!PROMO_CODE_PATTERN.test(v)) return "Promo code can only contain letters, digits, hyphens and underscores.";
  return undefined;
}
