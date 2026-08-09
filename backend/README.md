# EDI / Residia — Backend (API)

API multi-tenant de **EDI** (Residia): plataforma SaaS para administrar
organizaciónes residenciales (fraccionamientos y organizaciones) en México — cobranza,
tesorería, mesa directiva, accesos, proyectos comunitarios y más.

## Stack

- **Laravel 13** · **PHP 8.4** (constraint `^8.2`)
- **PostgreSQL 16+** (base única compartida; aislamiento multi-tenant por fila vía `tenant_id` + `TenantScope`)
- **Redis** (caché, colas, sesiones)
- **Laravel Octane** (Swoole/RoadRunner)
- **Sanctum** (auth por token + cookie httpOnly), **Spatie Permission** (roles/permisos UUID, guard `api`)
- **Stancl Tenancy** (single-database), **Nwidart Modules** (módulos en `Modules/`), **Tailflow Orion** (REST autogenerado)
- **Cashier/Stripe** (suscripciones), **Spatie Media Library**, **Laravel Auditing**, **cknow/laravel-money** (dinero en minor units)

## Requisitos

- PHP 8.4 con extensiones: `pdo_pgsql`, `redis`, `bcmath`, `gd`, `intl`
- Composer 2
- PostgreSQL 16+
- Redis

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configurá en .env: conexión DB (DB_*), Redis (REDIS_*), Stripe (STRIPE_SECRET),
# Firebase (FIREBASE_CREDENTIALS), y el resto de servicios.
php artisan migrate
php artisan db:seed                 # catálogos centrales + permisos
php artisan serve                   # API en http://localhost:8000
```

### Variables de entorno clave (`.env`)

| Variable | Para qué |
|---|---|
| `APP_KEY` | Generada con `php artisan key:generate` |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Caché/colas/sesiones |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | `redis` en prod; `array`/`sync`/`array` en tests |
| `STRIPE_SECRET`, `STRIPE_KEY` | Suscripciones (Cashier). Resuelto vía `config('services.stripe.secret')` |
| `PLAN_DEFAULT` | Slug del plan por defecto para el guard de suscripción |
| `FIREBASE_CREDENTIALS` | Credenciales server para push (FCM) |
| `RESEND_KEY` / `POSTMARK_TOKEN` | Envío de email |

> NUNCA uses `env()` fuera de `config/*.php` — el código lee siempre por `config()`.

## Arquitectura

- **Módulos** en `Modules/` (Nwidart). Cada uno: `app/` (Controllers, Models, Services, Requests), `database/` (migraciones, seeders), `routes/api.php`.
- **Multi-tenancy**: UNA base compartida; cada tabla tenant-scoped lleva `tenant_id` + trait `BelongsToTenant`. El tenant se resuelve del usuario autenticado (`InitializeTenancyFromUser`); super-admin manda `X-Tenant-Id`. NO hay BD por tenant.
- **Migraciones**: todas corren en un único `php artisan migrate`. Toda columna lleva `->comment()` (diccionario de datos — ver laravel-backend-architecture skill).
- **Dinero**: SIEMPRE `bigInteger` en minor units (centavos) + cast `MoneyIntegerCast`. NUNCA `float`/`decimal`.
- **Catálogos**: FSM de estados y selectores de comportamiento = CENTRALES (`CatalogSeeder`); etiquetas administrables = tenant-scoped (`TenantCatalogSeeder`).

## Tests

```bash
php artisan test                    # suite PHPUnit
php artisan test --parallel
php artisan test tests/Feature/Collections   # un módulo
```

## Calidad

```bash
vendor/bin/pint                     # formato (Laravel Pint)
```

## Comandos útiles

```bash
php artisan db:seed --class=ResetPermissionTableSeeder   # rehidratar matriz de permisos
php artisan collections:refresh-statuses                 # cron: transiciones de estado de cargos
php artisan tenants:seed                                 # siembra por tenant (TenantCreated)
```
