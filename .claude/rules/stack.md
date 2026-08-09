# Project Stack

## Frontend

> STATUS: React (React 19 + Vite + Tailwind v4 + shadcn) is the ACTIVE frontend stack, in `frontend/`. This is the go-forward frontend.

| Technology | Version | Purpose |
|-----------|---------|---------|
| React | 19.2.x | UI framework |
| TypeScript | 5.9.x | Static typing |
| Vite | 8.x (beta) | Bundler + dev server |
| Tailwind CSS | 4.2.x | Utility-first CSS (via @tailwindcss/vite) |
| React Router DOM | 7.13.x | Client-side routing |
| Zustand | 5.0.x | State management (NgRx-like pattern with devtools) |
| TanStack React Query | 5.90.x | Server-state caching and synchronization |
| React Hook Form | 7.71.x | Form management |
| Zod | 4.3.x | Schema validation |
| Axios | 1.13.x | HTTP client with interceptors (auth, tenant, refresh) |
| shadcn/ui (Radix UI) | latest | Base UI components (30+ components) |
| Recharts | 3.8.x (installed via `shadcn add chart`; React 19 compatible; strict-peer-dependencies=false in frontend/.npmrc) | Charts and graphs — used via shadcn chart.tsx atom + 4 typed wrappers in shared/components/charts/ |
| React Select | 5.10.x | Advanced selects |
| DND Kit | latest | Drag & drop |
| Firebase | 12.11.x | Authentication and push notifications |
| React Email | 5.2.x | Email templates authored in `frontend/emails/*.tsx`, COMPILED to Laravel Blade via `pnpm copy-emails` → `backend/Modules/Email/resources/views/*.blade.php`. Never hand-write the Blade. See CLAUDE.md strict rule 10 |
| pnpm | latest | Package manager (workspaces) |

## Backend

| Technology | Version | Purpose |
|-----------|---------|---------|
| Laravel | 13.x | API framework |
| PHP | 8.4.1 | Backend language (Composer constraint `^8.2`) |
| Laravel Octane | 2.13.x | Performance (Swoole/RoadRunner) |
| Laravel Sanctum | 4.x | API authentication (tokens + httpOnly cookies) |
| Stancl Tenancy | 3.10.x | Multi-tenancy (SINGLE shared DB; row-level isolation by `tenant_id` + `TenantScope` — NO per-tenant DBs) |
| Nwidart Modules | 12.x | Modular architecture |
| Tailflow Orion | latest | Automatic REST API generator |
| Spatie Permission | 6.17.x | Roles and permissions |
| Spatie Media Library | 11.12.x | File/media management |
| Spatie Sluggable | 3.7.x | URL-friendly slugs |
| Laravel Scout | 10.13.x | Full-text search |
| Laravel Cashier | 16.3.x | Stripe subscriptions |
| Maatwebsite Excel | 3.1.x | Excel import/export |
| Laravel Auditing | 14.x | Audit trails |
| Laravel Dompdf | 3.1.x | PDF generation |
| Firebase PHP | 7.18.x | Firebase integration (push notifications) |
| cknow/laravel-money | latest | Money handling — bigInteger minor units + cast `Cknow\Money\Casts\MoneyIntegerCast`. NEVER use decimal/float for money |

## Database

| Technology | Version | Purpose |
|-----------|---------|---------|
| PostgreSQL | 16+ | Primary database (single shared DB for ALL tenants; tenant isolation is row-level via `tenant_id`) |
| SQLite | - | Fallback / testing |
| Redis | - | Cache, queues, sessions |

## Infrastructure

| Technology | Purpose |
|-----------|---------|
| Cloudflare Pages | Frontend deploy (via wrangler) |
| Laravel Queue (Redis) | Async jobs and notifications |
| Stancl Tenancy Bootstrappers | Cache + Queue only (DatabaseTenancyBootstrapper REMOVED — single-DB, no connection switching) |

## Development Tools

| Tool | Purpose |
|------|---------|
| Vite | Bundler + HMR + API proxy |
| ESLint | Linter (v9.39.x) |
| Prettier | Formatter (v3.8.x) |
| Husky + lint-staged | Pre-commit hooks |
| Vite Plugin Compression | Gzip for production |
| Vite React Inspector | Debug component hierarchy |
| Laravel SQL Logger | SQL query debugging |
| Composer Merge Plugin | Per-module composer.json merge |
