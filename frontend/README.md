# Outdoor Fix — Frontend (Next.js)

Milestone 1 scope: environment setup and the base structure for the customer
portal and admin dashboard, wired to the Laravel API's role-based auth. Auth
(register/login/logout) and admin permission management are fully
functional; every other module page is an intentional placeholder — business
logic lands in later milestones per the project plan.

## Stack

- Next.js 16 (App Router, Turbopack), React 19, TypeScript
- Tailwind CSS 4

## Setup

```bash
npm install
cp .env.local.example .env.local
npm run dev
```

Requires the backend running at the URL in `NEXT_PUBLIC_API_URL` (defaults
to `http://localhost:8000/api`).

## Structure

```
src/
  app/
    login/, register/        — shared auth pages (functional)
    customer/                — customer portal (role-gated)
    admin/                   — admin dashboard (role-gated)
    middleware.ts            — redirects by auth_role cookie
  components/
    auth/                    — AuthCard, AuthField, AuthSubmitButton
    layout/                  — DashboardShell (sidebar/topbar), PlaceholderSection
  context/AuthContext.tsx    — current user, loading state
  lib/
    api.ts                   — fetch wrapper + ApiError
    auth.ts                  — login/register/logout, token + role cookies
    admin-users.ts           — admin account + permission management
  types/                     — mirrors the backend's Eloquent models
```

## Roles

Three account types: `customer`, `admin`, `super_admin`. `middleware.ts`
gates `/customer` and `/admin` (both `admin` and `super_admin` land in
`/admin`) by the `auth_role` cookie set at login; the Laravel API
independently re-checks role and admin access on every request, so this is a
UX convenience, not the security boundary.

Admins have full access by default. `admin_permissions` is an opt-in
*restriction* list — assigning one or more areas limits that admin to only
those; leaving it empty means full access. `AdminTopNav` only shows the
"Admin users" link to `super_admin`, and `/admin/admin-users` redirects any
other role away client-side (the API blocks it regardless).

Admin management (`/admin/admin-users`, super admin only) is fully wired to
`POST/PUT /api/admin/admin-users` — create admins and optionally restrict
them to any subset of `application_review`, `risk_assessment`,
`contract_generation`, `equipment_tracking`, `payment_tracking`.
