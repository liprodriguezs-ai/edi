# Project Conventions

## Naming Conventions

### Frontend
> Frontend activo: React (React 19 + Vite + Tailwind v4 + shadcn).
- **Components**: PascalCase (`ClientForm`, `ClientList`, `ClientDetail`)
- **Component files**: PascalCase (`ClientForm.tsx`, `ClientList.tsx`)
- **Modules**: camelCase directories (`client/`, `collaborator/`, `historyTicket/`)
- **Stores**: camelCase + "Store" (`useClientStore`, `useTicketStore`)
- **Hooks**: "use" + PascalCase (`useClientList`, `useBaseCrud`)
- **Services/Resources**: PascalCase + "Resource" (`ClientResource`, `TicketResource`)
- **Schemas**: camelCase + "FormSchema" (`clientFormSchema`)
- **Interfaces**: "I" + PascalCase (`IClient`, `ITicket`, `IEntity`)
- **Zod inferred types**: PascalCase + "FormValues" (`ClientFormValues`)
- **Enums**: "E" + PascalCase (`ERoleUserSlug`)

### Backend
- **Models**: PascalCase singular (`Client`, `Ticket`, `WorkShift`)
- **Controllers**: `{Model}Controller` (`ClientController`)
- **Services**: `{Model}Service` (`ClientService`)
- **Requests**: `{Model}{Action}Request` (`ClientCreateRequest`, `ClientUpdateRequest`)
- **Tables**: snake_case SINGULAR (`document`, `work_shift`, `ticket_status`). Canonical: laravel-backend-architecture skill.
- **Columns**: snake_case (`user_id`, `company_id`, `created_at`)
- **Model relationships**: belongsTo/single -> snake_case (el nombre del método = key del include/JSON, sin auto-conversión); colecciones -> plural. Canonical: laravel-backend-architecture skill.
- **Nwidart Modules**: PascalCase (`Client/`, `Ticket/`, `WorkShift/`)

## Architecture Decisions

### Zustand with EntityStore pattern (NgRx-like)
- **Decision**: Use Zustand with `createEntityStore` factory that generates standardized state
- **Reason**: Less boilerplate than Redux, but with predictable NgRx-like structure (entities, selectId, pagination, isLoading, etc.)
- **Pattern**: Each module uses `createEntityStore<T>('StoreName')` providing: entities, selectId, currentEntity, pagination, CRUD methods

### TanStack Query for server-state
- **Decision**: TanStack React Query for server data caching and synchronization
- **Reason**: Separate server-state (cache, refetch, invalidation) from client-state (Zustand)

### Orion for REST API
- **Decision**: Use Tailflow Orion for auto-generated REST endpoints
- **Reason**: Eliminates CRUD controller boilerplate. One controller + one route line generates index/store/show/update/destroy
- **Pattern**: Controllers extend `QCH\Core\Http\Controllers\Controller`, define `$model`, `includes()`, `searchableBy()` (mecanismo canónico en laravel-backend-architecture skill)

### Multi-tenancy with Stancl
- **Decision**: SINGLE shared database with Stancl Tenancy (single-database mode)
- **Reason**: One PostgreSQL DB for ALL tenants; isolation is row-level via a `tenant_id` column on every tenant-scoped table + the global `TenantScope` (from `BelongsToTenant`). No per-tenant databases, no connection switching.
- **Pattern**: Tenant resolved per request from the authenticated user (`InitializeTenancyFromUser`). `tenancy()->initialize()` only sets the scope — it does NOT switch databases. Super-admin sends `X-Tenant-Id` for cross-tenant. Mecanismo canónico en stancl-tenancy skill.

### Sanctum + Firebase Authentication
- **Decision**: Laravel Sanctum (tokens + httpOnly cookies) + Firebase for push notifications
- **Reason**: Sanctum for stateless API auth, Firebase for real-time notifications
- **Pattern**: Login returns token in memory + httpOnly cookie for refresh. Auto-refresh on 401

### UUID as primary keys
- **Decision**: All tables use UUID as primary key
- **Reason**: Security (IDs can't be enumerated), multi-tenant compatibility, merge-safe
- **Pattern**: `HasUuids` trait on all models, `$table->uuid('id')->primary()` in migrations

### Universal Soft Deletes
- **Decision**: All models use SoftDeletes
- **Reason**: Auditing, data recovery, referential integrity
- **Pattern**: `SoftDeletes` trait + tracking columns (`user_deleted_id`, `deleted_at`)

## Project Patterns

### Frontend CRUD Flow (5 layers)
> Frontend activo: React (React 19 + Vite + Tailwind v4 + shadcn).
1. **Schema** (Zod): Defines validation + types (`clientFormSchema`, `IClient`, `ClientFormValues`)
2. **Resource** (Axios): `createCrudApi<T, V>({ basePath })` generates list/search/show/create/update/remove
3. **Store** (Zustand): `createEntityStore<T>('Name')` generates standardized state
4. **Hook**: `useBaseList<T>({ store, resource })` for lists, `useBaseCrud<T, V>()` for forms
5. **Component**: List/Form/Detail consume hooks, use React Hook Form + Controller

### Backend CRUD Flow (Orion)
1. **Model**: Defines fillable, casts, relationships, scopes
2. **FormRequest**: Validation in `ClientCreateRequest` / `ClientUpdateRequest`
3. **Service**: Business logic delegated from controller (`performStore`, `performUpdate`)
4. **Controller**: Extends `QCH\Core\Http\Controllers\Controller` (la base Orion del proyecto, no Orion\...\Controller directo), defines `$model`, `includes()`, `searchableBy()`
5. **Route**: `Orion::resource('client', ClientController::class)->withSoftDeletes()`

### BaseModel columns
> BaseModel::columns($table, $withTenant=false) agrega id(UUID), timestamps, soft delete y columnas de auditoría. Para tablas TENANT pasá `true` (agrega `tenant_id` NOT NULL + FK + index) y scopeá los unique por tenant: `unique(['tenant_id', 'col'])`. Canonical: laravel-backend-architecture §2.1 + stancl-tenancy skill.

### API Client Pattern (Frontend)
- Axios instance with interceptors for:
  - Bearer token in headers
  - X-Tenant-Id for tenant context
  - Auto-refresh on 401
  - Error extraction (`ApiError` with message + validation errors)
- Automatic FormData for uploads

### Permission Model
- Spatie Permission with roles: `admin.super`, `account.owner`, `admin.rh`, `collaborator`, `affiliate`, `company.admin`
- Controllers define `$keyForPermission` that maps to automatic CRUD permissions
- Frontend: `usePermissions` hook + `PermissionRoute` guard

### Route Guards (Frontend)
- `ProtectedRoute`: Requires authentication
- `PublicRoute`: Only without authentication (login, register)
- `SubscriptionGuard`: Requires active subscription
- `PermissionRoute`: Requires specific permission

### Observer Pattern (Backend)
GlobalObserver (auditoría) -> laravel-backend-architecture skill; UserTenantScope (tenant) -> stancl-tenancy skill.

## Anti-patterns

> Full anti-patterns list is in `CLAUDE.md` (section 4). This is the authoritative source.
