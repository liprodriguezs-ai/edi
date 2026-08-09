---
name: laravel-backend-architecture
description: Backend architecture patterns, Laravel/Orion conventions, and module structure for a multi-tenant PHP application. Use when creating or modifying models, controllers, migrations, services, or any backend code.
tags: [php, laravel, orion, backend, multi-tenant, postgresql, sanctum]
metadata:
  author: Rodriguez
  version: "1.1"
---

## 1. ÁRBOL DE ARCHIVOS BACKEND — Módulo Document (Ejemplo)

```
backend/Modules/Document/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── DocumentController.php
│   │   │   ├── DocumentCollaboratorController.php
│   │   │   └── DocumentTypeController.php
│   │   └── Requests/
│   │       ├── DocumentCreateRequest.php
│   │       └── DocumentUpdateRequest.php
│   ├── Models/
│   │   ├── Document.php
│   │   ├── DocumentCollaborator.php
│   │   └── DocumentType.php
│   ├── Services/
│   │   ├── DocumentService.php
│   │   └── DocumentCollaboratorService.php
│   ├── Repositories/
│   │   └── DocumentRepository.php
│   ├── Providers/
│   │   ├── DocumentServiceProvider.php
│   │   ├── EventServiceProvider.php
│   │   └── RouteServiceProvider.php
│   ├── Listeners/
│   ├── Observers/
│   └── Rules/
├── config/
│   └── config.php
├── database/
│   ├── factories/
│   ├── migrations/
│   │   ├── 2026_03_01_194008_create_document_type_table.php
│   │   ├── 2026_03_02_194008_create_document_table.php
│   │   └── 2026_03_02_194009_create_document_collaborator_table.php
│   └── seeders/
│       └── DocumentDatabaseSeeder.php
├── routes/
│   ├── api.php
│   └── web.php
├── resources/
│   ├── assets/
│   └── views/
├── tests/
│   ├── Feature/
│   └── Unit/
├── module.json
├── composer.json
├── package.json
└── vite.config.js
```

---

## 2. BACKEND: ARQUITECTURA Y PATRONES

### 2.1 Core Package

El core se encuentra en `backend/core/src/` y provee las clases base:

**BaseModel** (`core/src/Models/BaseModel.php`):
- UUID como primary key (trait `HasUuids`)
- SoftDeletes automático
- Auditable (owen-it/laravel-auditing)
- Tracking de usuario: `user_created_id`, `user_updated_id`, `user_deleted_id`, `user_restored_id`
- Método estático `columns(Blueprint $table, bool $withTenant = false)` para migraciones. Con `$withTenant = true` agrega la columna `tenant_id` (NOT NULL + FK a `tenant` + index) — usalo en las tablas de módulos TENANT. Con `false` (default) no la agrega — para tablas CENTRALES. El sistema es single-database: el aislamiento por tenant es por la columna `tenant_id` + el trait `BelongsToTenant`. Canonical: skill stancl-tenancy.

**Controller** (`core/src/Http/Controllers/Controller.php`):
- Extiende `Orion\Http\Controllers\Controller`
- Usa `TraitController` (mapeo de permisos, paginación, sortable)
- Usa `AppliesCentralUserSearch` (búsqueda sobre el modelo central `User`, que vive en la MISMA BD pero con su propio `UserTenantScope`; no es cross-database — single-database)
- Hook `afterSearch()` que inyecta `total_records_database`

**TraitController** (`core/src/Http/Controllers/TraitController.php`):
- `resourceAbilityMap()`: Mapea acciones CRUD a abilities (`read.document`, `create.document`, etc.)
- `limit()`: 15 items por defecto
- `maxLimit()`: 100 items máximo
- `authorize()`: Verifica `tokenCan($ability)` o wildcard `*`

### 2.2 Crear un Módulo Nuevo

**SIEMPRE usar el comando de nwidart/laravel-modules:**



```bash
php artisan module:make {ModuleName}
```

Esto genera toda la estructura automáticamente. Luego crear archivos adicionales con:

```bash
php artisan module:make-model {ModelName} {ModuleName}
php artisan module:make-controller {ControllerName} {ModuleName}
php artisan module:make-migration create_{table_name}_table {ModuleName}
php artisan module:make-service {ServiceName} {ModuleName}
php artisan module:make-request {RequestName} {ModuleName}
```

### 2.3 Convenciones de Modelos

**Tablas en SINGULAR:**
```php
// CORRECTO
protected $table = 'document';
protected $table = 'product_category';
protected $table = 'collaborator';

// INCORRECTO
protected $table = 'documents';
protected $table = 'product_categories';
```

**Naming de relaciones (el nombre del método = clave del include/JSON, SIN conversión automática):**
```php
// belongsTo / single / FK-backed → snake_case (coincide con la columna y la clave del JSON)
public function workflow_stage(): BelongsTo
{
    return $this->belongsTo(WorkflowStage::class); // columna workflow_stage_id
}

public function ticket_status(): BelongsTo
{
    return $this->belongsTo(TicketStatus::class);
}

// colección (hasMany / morphMany) → plural
public function messages(): HasMany
{
    return $this->hasMany(TicketMessage::class);
}

public function media(): MorphMany
{
    return $this->morphMany($this->getMediaModel(), 'model')->orderBy('created_at', 'desc');
}
```

Eloquent NO transforma el nombre del método: el include de Orion y la clave del JSON serializado son EXACTAMENTE el nombre del método. Por eso las relaciones single se escriben en snake_case (`workflow_stage`, `ticket_status`, `assigned_user`) — así la clave del payload sale snake_case como las columnas. El mismo string debe whitelistearse en `includes()` del controller.

### 2.4 Ejemplo Completo: Modelo

```php
<?php

namespace Modules\Document\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Core\Models\BaseModel;
use App\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class Document extends BaseModel
{
    use HasFactory;

    protected $table = 'document';

    protected $fillable = [
        'name',
        'description',
        'required',
        'vigency',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany($this->getMediaModel(), 'model')->orderBy('created_at', 'desc');
    }
}
```

### 2.5 Ejemplo Completo: Migración

**Las tablas SIEMPRE en singular. Usar `BaseModel::columns()` para los campos base. TODA columna propia DEBE llevar `->comment(...)` (diccionario de datos — ver regla 13):**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document', function (Blueprint $table) {
            // comment de TABLA: SIEMPRE dentro del closure con $table->comment().
            // OJO: Schema::create() devuelve void — NUNCA encadenar ->comment() al create.
            $table->comment('Catálogo de tipos de documento del expediente');
            \App\Core\Models\BaseModel::columns($table, false);
            $table->string('name')->comment('Nombre visible del documento');
            $table->string('description')->nullable()->comment('Descripción libre opcional');
            $table->boolean('required')->default(false)->comment('Si el documento es obligatorio en el expediente');
            $table->boolean('vigency')->default(true)->comment('Si el tipo de documento sigue vigente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document');
    }
};
```

`BaseModel::columns()` ya agrega (con sus propios comments): `id` (UUID), `created_at`, `updated_at`, `deleted_at`, `user_created_id`, `user_updated_id`, `user_deleted_id`, `user_restored_id`. NO re-comentes esas; comentá solo las columnas propias del módulo.

### 2.6 Ejemplo Completo: Controller

**Controller simple (CRUD automático con Orion):**

```php
<?php

namespace Modules\Document\Http\Controllers;

use Modules\Document\Models\Document;
use App\Core\Http\Controllers\Controller;

class DocumentController extends Controller
{
    protected $model = Document::class;

    protected $keyForPermission = 'document';

    public function includes(): array
    {
        return ['type'];
    }

    public function sortableBy(): array
    {
        return ['name', 'description', 'type.name'];
    }

    public function searchableBy(): array
    {
        return ['name', 'description', 'type.name'];
    }
}
```

**Controller con lógica custom (cuando necesitas override):**

```php
<?php

namespace Modules\Document\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Document\Models\DocumentCollaborator;
use Modules\Document\Services\DocumentCollaboratorService;
use Orion\Http\Requests\Request;
use App\Core\Http\Controllers\Controller;

class DocumentCollaboratorController extends Controller
{
    protected $model = DocumentCollaborator::class;

    protected $keyForPermission = 'document_collaborator';

    public function includes(): array
    {
        return ['document', 'collaborator', 'collaborator.user'];
    }

    public function filterableBy(): array
    {
        return ['collaborator_id', 'document_id'];
    }

    /**
     * Override performStore cuando necesitas lógica de negocio
     * (media uploads, relaciones complejas, etc.)
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $created = app(DocumentCollaboratorService::class)->create($request->all());
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        app(DocumentCollaboratorService::class)->update($entity, $request->all());
    }
}
```

**Cuándo usar Services vs Controller directo:**
- **Controller directo**: CRUD simple sin lógica extra (80% de los casos)
- **Service**: Cuando hay uploads de archivos, creación de múltiples entidades, lógica de negocio compleja, o transformaciones de datos

### 2.7 Rutas con Orion

```php
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function () {
    \Orion\Facades\Orion::resource('document', \Modules\Document\Http\Controllers\DocumentController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    \Orion\Facades\Orion::resource('document_type', \Modules\Document\Http\Controllers\DocumentTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
```

Orion genera automáticamente: `GET /document` (index), `POST /document` (store), `GET /document/{id}` (show), `PUT /document/{id}` (update), `DELETE /document/{id}` (destroy), `POST /document/search` (search con filtros), `POST /document/{id}/restore` (restore).

### 2.8 Sistema de Permisos (Backend)

El mapeo de permisos se hace automáticamente en `TraitController`:

| Acción HTTP | Ability generada |
|---|---|
| index / show | `read.{keyForPermission}` |
| store | `create.{keyForPermission}` |
| update | `update.{keyForPermission}` |
| delete | `delete.{keyForPermission}` |
| restore | `restore.{keyForPermission}` |

Se verifica con `$user->tokenCan('read.document')`. El wildcard `*` otorga todos los permisos.

### 2.9 Tablas pivote (many-to-many) con id UUID

Las tablas pivote NO llaman `BaseModel::columns()`. Declaran su propio `id` UUID con un DEFAULT explícito a nivel BD; sin él, `attach()`/`sync()` fallan porque Eloquent no provee el id.

**El bug real (lo que NO hay que hacer)** — así se creó `module_system` y por eso fallaba:
```php
$table->uuid('id')->primary(); // ❌ sin DEFAULT → attach()/sync() explotan
```

**El fix — DEFAULT a nivel BD:**
```php
Schema::create('module_system', function (Blueprint $table) {
    if (env('DB_CONNECTION') === 'pgsql') {
        $table->uuid('id')->primary()->unique()->default(DB::raw('gen_random_uuid()'));
    } else {
        $table->uuid('id')->primary()->unique()->default(DB::raw('(UUID())'));
    }
    $table->foreignUuid('module_id')->cascadeOnDelete();
    $table->foreignUuid('system_id')->cascadeOnDelete();
    $table->unique(['module_id', 'system_id']);
    $table->timestamps();
});
```

Consistencia: el `BaseModel::columns()` del core usa `uuid_generate_v4()` para Postgres, mientras que varios pivotes del repo usan `gen_random_uuid()`. Ambas funcionan; USá `uuid_generate_v4()` para consistencia con el core. Los pivotes omiten `softDeletes()` y las columnas de auditoría.

### 2.10 Acciones custom (endpoints no-CRUD)

Las acciones de dominio que NO son CRUD se registran como líneas `Route::post` independientes, FUERA de `Orion::resource(...)`, y se implementan como métodos del controller que devuelven `JsonResponse` con `{ message, data }`:

```php
// routes/api.php
Route::post('deal/move', [DealController::class, 'moveDeal'])
    ->middleware(['auth:sanctum', 'initializeTenancy']);
```

```php
public function moveDeal(Request $request, DealService $dealService): JsonResponse
{
    $validated = $request->validate([...]); // validación inline para acciones custom
    try {
        $updated = $dealService->moveDeal(...);
        return response()->json(['message' => 'Movido exitosamente', 'data' => $updated]);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}
```

- En acciones custom usá el `Illuminate\Http\Request` normal; en los override de Orion (`performStore`/`performUpdate`) usá `Orion\Http\Requests\Request`.
- **NUNCA nombres un método del controller `dispatch()`** — colisiona con el `dispatch()` del `BaseController` de Orion. Usá verbos explícitos: `sendCampaign()`, `markDone()`, `reopen()`, `moveDeal()`. La URL puede seguir siendo `/dispatch` mientras el método se llame distinto.

### 2.11 Búsqueda filtrada: POST `/{resource}/search`

Para consultas filtradas/ordenadas/buscadas usá `POST /{resource}/search` con body JSON (`{ filters, sort, search, includes }`):

- `GET /{resource}` → index sin filtros.
- `GET /{resource}/{id}` → show.
- **NUNCA `GET /{resource}/search`** → Laravel hace match con la ruta show y trata `"search"` como el `{id}` (UUID inválido) → **error 500**.

El valor de búsqueda se lee como `$request->get('search')['value']`. El hook base `afterSearch()` inyecta `total_records_database` (conteo total sin filtros) en los metadatos de la respuesta — se hereda del core Controller, no lo re-implementes por módulo.

### 2.12 FormRequests: pareja Create/Update + invocación manual

Cada recurso escribible tiene DOS FormRequests: `{Model}CreateRequest` y `{Model}UpdateRequest` en `app/Http/Requests/`. El Create usa `required`; el Update usa `sometimes` para los mismos campos. `authorize()` SIEMPRE devuelve `true` — la autorización la maneja el middleware de ruta + el `$keyForPermission` del controller, no el request.

Los controllers Orion NO tipan FormRequests en la firma (reciben `Orion\Http\Requests\Request`). La validación se dispara EXPLÍCITAMENTE dentro del hook, antes de llenar la entidad:

```php
protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
{
    app(DealCreateRequest::class)->validated();
    $entity->fill($attributes);
    $entity->save();
    // ... lógica de negocio delegada al Service
}
```

Las acciones custom (no-CRUD) NO usan FormRequest: validan inline con `$request->validate([...])`.

Mantené el estilo de reglas de validación (array vs string) CONSISTENTE dentro de cada módulo.

### 2.13 GlobalObserver: auditoría automática

NUNCA asignes a mano `user_created_id`/`user_updated_id`/`user_deleted_id`/`user_restored_id` en Controllers o Services. El `GlobalObserver` (adjunto vía `#[ObservedBy]`) estampa ÚNICAMENTE esas columnas de auditoría en `creating`/`updating`/`deleted`/`restored` leyendo `auth()->user()`. El `tenant_id` NO lo estampa el observer: lo estampa el trait `BelongsToTenant` (ver skill stancl-tenancy). El código de negocio solo escribe campos de dominio.

Adjuntá observers con el atributo PHP 8 `#[ObservedBy([...])]` en la clase, no en un provider. Cuando un modelo necesita además un observer de dominio (p.ej. history), poné el observer de dominio PRIMERO y `GlobalObserver::class` AL FINAL, para que el estampado de actor (auditoría) ocurra después del hook de dominio:

```php
#[ObservedBy([HistoryTicketObserver::class, GlobalObserver::class])]
class Ticket extends BaseModel { ... }
```

---

## 3. REGLAS ESTRICTAS (Backend)

1. **Controllers SIEMPRE extienden `App\Core\Http\Controllers\Controller`** (la base Orion del sistema, NO `Orion\Http\Controllers\Controller` directo) y definen `$model` y `$keyForPermission`.

2. **NUNCA hacer `DB::` directo.** Usar Eloquent y relaciones.

3. **Tablas de migración en SINGULAR:**
   ```php
   // CORRECTO
   Schema::create('document', ...);
   Schema::create('product_category', ...);

   // INCORRECTO
   Schema::create('documents', ...);
   Schema::create('product_categories', ...);
   ```

4. **Naming de relaciones — la verdad precisa (NO hay conversión automática):**
   Eloquent NO convierte el nombre del método: `$model->relacion`, la clave en el JSON serializado y el string del include de Orion son EXACTAMENTE el nombre del método. Por eso este proyecto escribe deliberadamente:
   - Relaciones single / FK-backed (`belongsTo`) en **snake_case**: `ticket_status()`, `workflow_stage()`, `assigned_user()`, `from_stage()`, `user_created()`.
   - Relaciones de colección en **plural**: `messages()`, `assignments()`, `documents()`, `media()`, `history()`.
   - `morphOne` single en snake_case singular: `current_media()`.

   REGLA: nombrá el método de la relación EXACTAMENTE como querés que se lea la clave del include/JSON — no confíes en ninguna conversión camelCase→snake_case. Ese mismo string debe whitelistearse en `includes()` del controller (ver punto 8).

5. **Tabla pivote dentro del mismo módulo:** si tenés un modelo `Booking` y otro `BookingStatus` en el MISMO módulo, la relación `belongsTo` hacia el status se nombra corta y en snake_case (`status()`), coincidiendo con la columna `status_id`.

6. **Todos los `id` de migraciones y modelos son UUID.** Los modelos usan el trait `HasUuids` (vía `BaseModel`); las migraciones obtienen el id UUID con DEFAULT a nivel BD vía `BaseModel::columns()`.

7. **Las reglas de negocio se declaran en la clase Service de cada módulo,** no en el Controller. El Controller solo orquesta (override de `performStore`/`performUpdate` que delega al Service).

8. **Las APIs se construyen con `tailflow/laravel-orion`**; para lógica custom se hace override de los métodos del lifecycle de Orion (`performStore`, `performUpdate`), nunca se reimplementa index/show/store/update/destroy a mano. Whitelisteá en `includes()`/`sortableBy()`/`searchableBy()`/`filterableBy()` cualquier campo/relación que uses, o Orion devuelve 422.

9. **SIEMPRE usar los comandos de `nwidart/laravel-modules`** para generar código de módulos.

10. **SIEMPRE usar `BaseModel::columns($table)` en migraciones** para los campos base (id UUID, timestamps, softDeletes, columnas de auditoría).

11. **No reintroduzcas código que el usuario ya eliminó,** salvo que lo pida explícitamente.

12. **Cambios de esquema = migración ALTER nueva, NUNCA editar la migración `create` original.** El historial es append-only por módulo. Creá un archivo timestamped `add_{col}_to_{table}_table` (o `alter_...`) con `Schema::table(...)`, posicioná con `->after('col')` y SIEMPRE provee un `down()` que revierte (`dropColumn`).

13. **DICCIONARIO DE DATOS — `->comment(...)` OBLIGATORIO en TODA migración (HARD).** Cada columna propia que declares (en `create` o en `alter`) DEBE terminar con `->comment('...')` describiendo para qué sirve el campo (unidad si es dinero: "minor units (cents)"; relación si es FK: "FK to {tabla}"; semántica si es flag/estado). Además, al crear una tabla agregá un comment a la TABLA **dentro del closure**: `$table->comment('qué representa la entidad');` como primera línea. **NUNCA encadenes `->comment()` al `Schema::create(...)`** — ese método devuelve `void` y truena con "Call to a member function comment() on null". NO comentes las columnas base de `BaseModel::columns()` (ya vienen comentadas) — solo las propias. Las columnas FK y de comportamiento (`requires_floor`, `is_billable`, `grants_role`, `key`) SIEMPRE llevan comment. Una migración con columnas propias sin `->comment()` es un error de revisión.

14. **NUNCA uses `enum` nativo de PHP (ni cast de enum de Laravel) para status/type.** Definí los valores como constantes de clase + un array `STATUSES`/`TYPES` para validar/iterar. Obligatorio porque la `BaseTenant` de Stancl no serializa enums nativos, y mantiene la convención uniforme en todos los modelos.

15. **NUNCA uses `Spatie\...\Media` directamente; SIEMPRE usá `App\Models\Media`** (tiene `HasUuids` para claves UUID).

16. **NUNCA uses columnas `is_active` / flags de estado booleano para borrado; usá `SoftDeletes`** (ya provisto por `BaseModel`).

17. **Dinero cruza la frontera del API SOLO como objeto Money serializado `{amount, currency, formatted}` (NUNCA como entero crudo en minor units).** Los modelos con columnas de dinero usan `App\Core\Casts\TenantMoneyCast` (NUNCA `MoneyIntegerCast::class.':MXN'` — la moneda se resuelve por tenant en cada llamada, jamás hardcodeada). Los agregados (sumas, promedios, cachés) se computan y se ALMACENAN en cache como enteros planos en minor units bajo sus claves internas; la conversión a Money ocurre SOLO al armar la respuesta del endpoint, vía `App\Core\Support\TenantMoney::make(int $minorUnits): Money`. Para strings de notificación/PDF/recibo usá `TenantMoney::format(int $minorUnits): string` — NUNCA reconstruyas a mano `'$'.number_format($raw/100,2).' MXN'` ni ningún otro literal hardcodeado a MXN. La moneda resuelta sale de `tenant()->currency` (fallback seguro a `config('money.defaultCurrency')` si no es válida o no hay tenancy inicializada), nunca de un literal en el código. Excepción deliberada: el billing de plataforma (`SubscriptionPlan`, `TenantBillingController`) sigue serializando enteros en MXN — no participa de este patrón.

---

## 4. GUÍA RÁPIDA: CREAR UN MÓDULO NUEVO

### Backend (5 pasos):

```bash
# 1. Crear módulo
php artisan module:make Product

# 2. Crear modelo
php artisan module:make-model Product Product

# 3. Crear migración
php artisan module:make-migration create_product_table Product

# 4. Crear controller
php artisan module:make-controller ProductController Product

# 5. Definir rutas en Modules/Product/routes/api.php
```

Luego editar:
- **Model**: agregar `$table`, `$fillable`, relaciones, `#[ObservedBy]`, extender `BaseModel`
- **Migration**: usar `BaseModel::columns()`, agregar campos propios
- **Controller**: definir `$model`, `$keyForPermission`, `includes()`, `sortableBy()`, `searchableBy()`
- **Routes**: registrar con `Orion::resource()`

> Para los pasos de registro (CSV de módulos/permisos + seeders + ruta) ver CLAUDE.md sección 3.
