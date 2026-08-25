# Outdoor Fix — Backend (Laravel API)

Milestone 1 scope: environment setup, database schema, role-based authentication
for customer / admin accounts, and the base REST API structure. No
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

The seeder creates one super-admin with every permission
(`admin@outdoorfix.test` / `password`) and one test customer
(`customer@outdoorfix.test` / `password`).

## Roles

Two account types, stored on `users.role`: `customer`, `admin`.

- **Customer** — the renter. Has a `customer_profiles` row (identity, address,
  landlord info, employment, bank verification). Self-registers via
  `POST /api/auth/register`.
- **Admin** — Outdoor Fix staff. Granular access is controlled by
  `admin_permissions` (`application_review`, `risk_assessment`,
  `contract_generation`, `equipment_tracking`, `payment_tracking`), not by a
  single role flag — an admin can hold any subset. Never self-registered;
  created by an existing admin via `POST /api/admin/admin-users`.

Two middleware aliases enforce this:

- `role:customer|admin` — gates by account type
- `permission:application_review|risk_assessment|...` — gates by admin
  permission (any one of the listed permissions passes)

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

## Data model

See `database/migrations` for the full schema. Entities map directly to the
project plan's "Key data entities" table: Customer profile, Application,
Lease Agreement, Equipment Unit (+ service records), Risk Profile (+ red
flags), GPS Event (Phase 2, unused for now), Contract, Payment, and Admin
permissions.
