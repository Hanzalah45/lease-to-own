<?php

namespace App\Services;

/**
 * Cross-cutting field-length limits shared by every controller in the app —
 * the backend counterpart to frontend/src/lib/validation.ts. Each constant
 * mirrors that file's own reasoning (an RFC, a well-known system's own field
 * cap, or the physically realistic length of the real-world thing being
 * typed) instead of reusing Laravel's `max:255` VARCHAR default, which is a
 * storage artifact, not a considered limit. ApplicationValidationRules
 * builds on these same constants for the lease-application flow specifically.
 */
class CommonValidationRules
{
    // Mirrors NAME_MIN/NAME_MAX in validation.ts (Salesforce's own "Last
    // Name" field cap for the max; two characters is the shortest real
    // legal name — "Al", "Bo" — for the min).
    public const NAME_MIN = 2;
    public const NAME_MAX = 80;

    // Mirrors EMAIL_MAX in validation.ts — RFC 5321 4.5.3.1.3's actual
    // maximum length for an email address. No real minimum shorter than
    // what the `email` format rule itself already requires (a@b.c).
    public const EMAIL_MAX = 254;

    // Mirrors PHONE_DIGITS_MIN/PHONE_MAX in validation.ts — a US/Canada
    // number is 10 digits; PHONE_DIGITS_MIN's exact digit-count check is a
    // frontend-only nicety, so this is a plain-character floor that a real
    // 10-digit number (formatted or not) always clears. E.164 caps an
    // international number at 15 digits; 20 leaves room for formatting
    // characters.
    public const PHONE_MIN = 10;
    public const PHONE_MAX = 20;

    // Mirrors STREET_MIN/STREET_MAX in validation.ts.
    public const STREET_MIN = 5;
    public const STREET_MAX = 100;

    // Mirrors CITY_MAX in validation.ts — validateCity() there reuses
    // NAME_MIN as its floor (no city-specific minimum is defined), so this
    // does the same.
    public const CITY_MAX = 50;

    // Mirrors PASSWORD_MIN/PASSWORD_MAX in validation.ts — bcrypt only
    // hashes the first 72 bytes of input, so anything longer is silently
    // truncated by Hash::make(), and NIST 800-63B's recommended floor is 8.
    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 72;

    // Laravel's own password-reset token (DatabaseTokenRepository) is a
    // sha256 hex digest — always exactly 64 characters.
    public const RESET_TOKEN_MAX = 64;

    // A free-text search box query — generous headroom for a pasted phrase
    // without accepting a pasted paragraph.
    public const SEARCH_QUERY_MAX = 100;

    // Mirrors EQUIPMENT_MODEL_MIN/MAX, SERIAL_MIN/MAX, VIN_MIN/MAX,
    // GPS_DEVICE_ID_MIN/MAX in validation.ts.
    public const EQUIPMENT_MODEL_MIN = 2;
    public const EQUIPMENT_MODEL_MAX = 100;
    public const SERIAL_MIN = 3;
    public const SERIAL_MAX = 50;
    public const VIN_MIN = 5;
    public const VIN_MAX = 20;
    public const GPS_DEVICE_ID_MIN = 3;
    public const GPS_DEVICE_ID_MAX = 64;

    public static function name(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX];
    }

    /** @param  string|\Illuminate\Validation\Rule|null  $uniqueRule  e.g. 'unique:users,email' or Rule::unique(...)->ignore($id) */
    public static function email($uniqueRule = null, bool $required = true): array
    {
        return array_filter([
            $required ? 'required' : 'sometimes', 'string', 'email', 'max:'.self::EMAIL_MAX, $uniqueRule,
        ]);
    }

    public static function phone(bool $nullable = true): array
    {
        return array_filter([$nullable ? 'nullable' : null, 'string', 'min:'.self::PHONE_MIN, 'max:'.self::PHONE_MAX]);
    }

    public static function password(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'min:'.self::PASSWORD_MIN, 'max:'.self::PASSWORD_MAX];
    }

    public static function street(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:'.self::STREET_MIN, 'max:'.self::STREET_MAX];
    }

    public static function city(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::CITY_MAX];
    }
}
