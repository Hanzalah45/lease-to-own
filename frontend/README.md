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

Two account types: `customer`, `admin`. `middleware.ts` gates `/customer`,
`/admin` by the `auth_role` cookie set at login/register; the Laravel API
independently re-checks role and admin permission on every request, so this
is a UX convenience, not the security boundary.

Admin permission management (`/admin/admin-users`) is fully wired to
`POST/PUT /api/admin/admin-users` — create admins and assign any subset of
`application_review`, `risk_assessment`, `contract_generation`,
`equipment_tracking`, `payment_tracking`.
