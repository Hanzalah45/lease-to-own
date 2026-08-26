# Outdoor Fix — Backend (Laravel API)

Milestone 1 scope: environment setup, database schema, role-based authentication
for customer / admin / super admin accounts, and the base REST API structure. No
lease/EPO pricing logic, risk scoring, payments, or third-party integrations
are implemented yet — those land in later milestones per the project plan.

## Stack

- Laravel 12, PHP 8.2
- SQLite for local dev (swap `DB_CONNECTION` to `mysql`/`pgsql` for staging/prod)
- Laravel Sanctum for token-based API auth (Bearer tokens, consumed by the
  Next.js frontend)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The seeder creates four test accounts (all password `password`):

| Role | Email | Notes |
|---|---|---|
| Super admin | `superadmin@outdoorfix.test` | The client — unrestricted |
| Admin | `admin@outdoorfix.test` | No restriction rows — full access |
| Admin (restricted) | `restricted.admin@outdoorfix.test` | Limited to equipment tracking + payment tracking, to demo the restriction path |
| Customer | `customer@outdoorfix.test` | |

## Roles

Three account types, stored on `users.role`: `customer`, `admin`, `super_admin`.

- **Customer** — the renter. Has a `customer_profiles` row (identity, address,
  landlord info, employment, bank verification). Self-registers via
  `POST /api/auth/register`.
- **Admin** — Outdoor Fix staff. **Full access by default** — an admin can do
  everything a customer can do on that customer's behalf, and view/edit all
  customer data. Never self-registered; created only by a super admin via
  `POST /api/admin/admin-users`.
- **Super admin** — the client. Unrestricted access to everything, plus the
  only role that can create/remove Admin accounts and restrict one. Seeded,
  not created through any endpoint.

`admin_permissions` is an **opt-in restriction list**, not a grant list: an
admin with zero rows there has full access; once a super admin adds rows for
that admin, they're limited to exactly those areas (`application_review`,
`risk_assessment`, `contract_generation`, `equipment_tracking`,
`payment_tracking`). See `User::hasAdminPermission()`.

Two middleware aliases enforce this:

- `role:customer|admin|super_admin` — gates by account type
- `permission:application_review|risk_assessment|...` — gates by admin
  access (super admin always passes; an unrestricted admin always passes; a
  restricted admin needs the specific permission listed)

See `app/Http/Middleware/EnsureUserHasRole.php` and
`EnsureAdminHasPermission.php`.

## API structure

Controllers are namespaced by who they serve, under
`app/Http/Controllers/Api/{Auth,Customer,Admin}`. Routes live in
`routes/api.php`, grouped the same way. Resource controllers for
applications, lease agreements, equipment, risk profiles, contracts, and
payments are scaffolded with route-model binding but empty method bodies —
business logic is intentionally deferred to their respective milestones
(Lease & Ownership Engine, Risk Assessment Engine, etc.) in the project plan.

Auth endpoints are fully implemented:

- `POST /api/auth/register` — customer self-registration
- `POST /api/auth/login` — returns a Sanctum bearer token
- `POST /api/auth/logout` — revokes the current token
- `GET /api/auth/me` — current user + profile + permissions

`POST/PUT /api/admin/admin-users` (super admin only) creates/updates Admin
accounts and their restriction list.

## Data model

See `database/migrations` for the full schema. Entities map directly to the
project plan's "Key data entities" table: Customer profile, Application,
Lease Agreement, Equipment Unit (+ service records), Risk Profile (+ red
flags), GPS Event (Phase 2, unused for now), Contract, Payment, and Admin
permissions (restriction list).

## Not yet built (flagged in the reference doc, not this milestone)

- Email/in-app notifications for every other listed trigger event (status
  changes, payments, etc.) — only the "admin account created" email exists
  so far (`app/Mail/AdminAccountCreatedMail.php`). Provider is confirmed —
  **Amazon SES** — but not wired up yet: needs `composer require aws/aws-sdk-php`,
  the client's AWS IAM access key/secret + region in `.env` (see
  `AWS_ACCESS_KEY_ID` etc.), and `MAIL_MAILER` switched from `log` to `ses`.
  Until then, every outgoing email lands in `storage/logs/laravel.log`
  instead of a real inbox.
- Admin password reset flow.
- Activity/audit log for admin edits to customer data.
- 2FA for admin/super-admin logins (deferred to Phase 2).
