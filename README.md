# Outdoor Fix — Lease to Own Platform

Monorepo for the lease-to-own management portal: [`backend/`](backend) (Laravel
API) and [`frontend/`](frontend) (Next.js).

## Status: Milestone 1 — Setup & architecture

Per the project plan's milestone breakdown, this repo currently covers:

- Environment setup for both apps
- Database schema (see `backend/database/migrations`)
- Role-based authentication for **customer** and **admin**
  accounts, including admin's granular permission set — fully functional
- Base REST API structure, consumed by a matching Next.js route structure

Every other module (lease/EPO pricing engine, risk assessment, equipment
tracking, contracts & e-signature, accounting integration) is scaffolded as
route/model/page structure only, per the project plan's milestone order —
no business logic yet. See each app's own README for details.

## Quick start

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve

# Frontend (separate terminal)
cd frontend
npm install
cp .env.local.example .env.local
npm run dev
```

Seeded super-admin: `admin@outdoorfix.test` / `password`.
