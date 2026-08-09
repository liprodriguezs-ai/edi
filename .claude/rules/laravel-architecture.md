---
description: Laravel backend architecture, middleware, multi-tenancy, packages, and security standards
globs: "**/*.php"
---
# Laravel Architecture — Complemento al skill laravel-backend-architecture

> Este archivo es dueño de cuatro cosas concretas: la tabla de alias de middleware, la convención de migraciones (todas corren en un único `php artisan migrate`; el tenant-scope se marca con `BaseModel::columns($table, true)`, no por carpeta), la referencia de paquetes instalados, y el patrón de seeders de catálogo con constantes UUID.
>
> CANONICAL para modelos/controllers/relaciones/migraciones/permisos: laravel-backend-architecture y stancl-tenancy skills.

---

## 1. Estructura general del proyecto

```
backend/
├── app/                    # Aplicación principal (Auth, User, Middleware, Http, Services)
├── core/                   # Paquete qch/core: BaseModel, BaseMedia, Controller base,
│   └── src/                #   middleware (permission), comandos Artisan, stubs de módulos
├── Modules/                # Módulos de negocio (nwidart/laravel-modules)
│   └── {ModuleName}/       # Ver skill laravel-backend-architecture para estructura interna
├── config/                 # Configuración global; módulos pueden tener config/config.php propio
├── database/
│   └── migrations/         # Migraciones de la app raíz (roles, permisos, suscripciones, etc.)
├── routes/
│   └── api.php             # Rutas globales (onboarding, auth, reports, throttle)
├── bootstrap/
│   └── app.php             # Alias de middleware (ej. initializeTenancy)
├── tests/                  # Tests Feature y Unit globales
└── modules_statuses.json   # Registro de módulos activos/inactivos
```

Las rutas por recurso se registran desde `Modules/{Module}/routes/api.php`. Las migraciones de cada módulo viven dentro del módulo y corren JUNTO con las de la app raíz en un único `php artisan migrate` (single-database; no hay pase `tenants:migrate`). Que una tabla sea tenant-scoped lo define `BaseModel::columns($table, true)`, no la carpeta. Canonical: stancl-tenancy.

---

## 2. Referencia de Middleware

| Middleware              | Propósito                                                         |
|-------------------------|-------------------------------------------------------------------|
| `auth:sanctum`          | Autenticación API vía Laravel Sanctum                             |
| `initializeTenancy`     | Fija el tenant actual desde el usuario (`tenancy()->initialize()`) — SOLO setea el scope (`TenantScope`), NO cambia de conexión/BD (single-database) |
| `permission`            | Verificación de permisos (provisto por qch/core)                  |
| `onboardingCompleted`   | Bloquea rutas si el usuario no completó onboarding                |
| `throttle:api`          | Rate limiting general para API                                    |

---

## 3. Multi-tenancy (stancl/tenancy)

> Canonical: see `.agents/skills/stancl-tenancy/SKILL.md` (this rule lives there to avoid duplication).

---

## 4. Convenciones adicionales (no cubiertas en laravel-backend-architecture)

### 4.1 Registro de módulos
Al crear un módulo nuevo, **siempre** agregarlo a `modules_statuses.json` con valor `true` para activarlo.

### 4.2 Patrón de catálogos
Los modelos catálogo (estados, tipos) definen **constantes UUID** en la clase:

```php
class InteractionStatus extends BaseModel
{
    const PENDING = 'uuid-del-estado-pending';
    const COMPLETED = 'uuid-del-estado-completed';
    // ...
}
```

En el seeder se hace **upsert** usando esas constantes como `id`, para que los registros sean estables y referenciables por código.

### 4.3 Soft deletes en vez de is_active
> Canonical: see `.agents/skills/laravel-backend-architecture/SKILL.md` (this rule lives there to avoid duplication).

### 4.4 Variables de entorno
Usar `config('key')` en el código. **NUNCA** usar `env()` fuera de archivos de configuración (`config/*.php`).

### 4.5 Respuestas de Orion
Orion devuelve su propio formato de respuesta (data, meta, etc.). **No** usar un `RestResponseTrait` genérico en controladores Orion. Para rutas custom (no recurso), devolver JSON consistente: `response()->json([...])`.

### 4.6 Consistencia en Form Requests
Revisar los requests **hermanos** del módulo antes de crear uno nuevo. Si el módulo usa estilo **array** (`['required', 'string', 'max:255']`), mantener array. Si usa **string** (`'required|string|max:255'`), mantener string. Consistencia por módulo.

---

## 5. Paquetes extendidos — Referencia de uso

### owen-it/laravel-auditing
- BaseModel implementa `Auditable` y usa el trait `\OwenIt\Auditing\Auditable`.
- La tabla de auditoría (`audits`) vive una sola vez en la BD compartida. GlobalObserver excluye la tabla `audits` del estampado de columnas de auditoría.

### laravel/cashier
- Suscripciones Stripe. Módulos involucrados: `CheckoutSubscription`, `SubscriptionPlan`.
- Tablas `subscriptions`, `subscription_items` en migraciones centrales.

### maatwebsite/excel
- Import/export de datos (ej. colaboradores).

### barryvdh/laravel-dompdf
- Generación de PDFs.

### phpoffice/phpword
- Documentos Word con plantillas y variables (usado en DocumentTemplate).

### resend/resend-laravel
- Envío de emails vía Resend.

### kreait/firebase-php
- Integración con Firebase.

### qch/core (detalle extendido)
- **BaseModel** (`core/src/Models/BaseModel.php`): HasUuids, SoftDeletes, Auditable, WithJoin. Los modelos NO cachean por defecto.
- **CentralCatalogModel** (`core/src/Models/CentralCatalogModel.php`): extends BaseModel + `Cachable` (mike-bronner/laravel-model-caching). Úsala para catálogos CENTRALES (sin `tenant_id`): una única entrada de caché compartida por todos los tenants. Gateada por `config('laravel-model-caching.enabled')` (default: `false`). Requiere Redis.
- **TenantCatalogModel** (`core/src/Models/TenantCatalogModel.php`): extends BaseModel + `BelongsToTenant` + `Cachable`. Úsala para catálogos TENANT-SCOPED. El constructor fija `$cachePrefix = 'tenant-{id}'` cuando hay tenancy inicializada. Este prefijo participa en la clave de caché Y en los tags de invalidación → un write en tenant A sólo flushea la caché de A, sin tocar la de B.
- BaseModel::columns() — firma y comportamiento canónicos en laravel-backend-architecture skill (§2.1).
- **BaseMedia** (`core/src/Models/BaseMedia.php`): Extiende Spatie Media. UUID como PK, `$keyType = 'string'`, `$incrementing = false`. `bootHasUuid` es no-op.
- **Comandos**: `module:make-migration`, `module:make-model`, `module:make-service`, `module:make-request` (stubs en `core/src/Commands/stubs`).

---

## 6. Seguridad y buenas prácticas

- **Autorización**: Usar políticas (Policies) y gates cuando se necesite autorización por recurso.
- **No exponer datos sensibles** en respuestas de API. Usar `$hidden` en modelos.
- **Rate limiting**: `throttle:api` como middleware global. Límites específicos para auth, reset password, etc. en `routes/api.php`.
