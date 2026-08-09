# AGENTS.md — guía para agentes

Actuá como Senior Software Architect. Esta es la referencia principal del proyecto.

**Producto**: **EDI** — sistema para la **Evaluación del Desarrollo Infantil**, el tamiz
de desarrollo infantil de la Secretaría de Salud de México, más las sesiones de
estimulación temprana asociadas.

> **Mapa de fuentes de verdad**
> - `AGENTS.md` (este archivo) = índice + filosofía + reglas duras.
> - `.agents/skills/laravel-backend-architecture/SKILL.md` = autoridad de backend.
> - `.agents/skills/stancl-tenancy/SKILL.md` = autoridad de multi-tenancy.
> - `.agents/skills/react-frontend-architecture/SKILL.md` = autoridad de frontend.
> - `.project-context/*` = hechos (stack, dominio, módulos, convenciones).
> - `openspec/` = artefactos de SDD.
>
> Regla: no repitas una regla en dos lugares; apuntá a su fuente.

---

## 1. El dominio en una página

La prueba EDI clasifica el desarrollo de un niño de **1 a 71 meses** en un semáforo:
**verde** (desarrollo normal), **amarillo** (rezago) o **rojo** (riesgo de retraso).

- El niño cae en un **grupo de edad** (15 grupos oficiales) según su edad en días.
  Si nació prematuro, la **edad corregida** puede moverlo de grupo.
- Cada grupo tiene **reactivos** agrupados por **eje**: MG (motriz gruesa), MF (motriz
  fina), LE (lenguaje), SO (social), CO (conocimiento), EN (exploración neurológica),
  ALA (señales de alarma) y ALE (señales de alerta).
- Cada reactivo se contesta con una de dos opciones, y cada opción produce un color.
- Los colores por eje se agregan con **umbrales que varían por grupo**, y el resultado
  global se deriva de los ejes más los **factores de riesgo biológico** (7) y las
  señales de alerta.
- Si un eje sale **todo amarillo**, se aplican los reactivos del **grupo anterior**
  (evaluación alterna) para ese eje.

Detalle completo del modelo: `.project-context/domain.md`.

### Tres invariantes que no se negocian

1. **El resultado se calcula SIEMPRE en el servidor.** Nunca se acepta
   `result_color_id`, `situation_id` ni los contadores desde el cliente. El sistema
   legacy los tomaba del DOM y eran falsificables con devtools.
2. **El instrumento es contenido inmutable versionado.** Una prueba aplicada guarda un
   puntero duro a su `instrument_version` y sólo se recomputa con las reglas de ESA
   versión. Una edición nueva es una versión nueva, jamás un `UPDATE`.
3. **Los umbrales viven en datos**, no hardcodeados por grupo: `area_scoring_rule` y
   `global_scoring_rule`.

---

## 2. Stack

Ver `.project-context/stack.md`. En resumen: monorepo plano con
`backend/` (Laravel 13 + Orion, PHP 8.4, PostgreSQL, Stancl single-DB multi-tenancy,
nwidart/laravel-modules), `frontend/` (React 19 + Vite + Tailwind v4 + shadcn +
TanStack Query + Zustand) y `landing/` (Astro + Starlight).

---

## 3. Reglas duras

1. **NUNCA `any` en TypeScript.** Definí interfaces concretas.

2. **UI siempre en español** (de México): textos, placeholders, mensajes de error,
   toasts, etiquetas.

3. **Identificadores SIEMPRE en inglés.** Tablas (singular, snake_case), columnas,
   clases de modelo, métodos de relación, claves de enum, servicios, jobs, eventos.
   El español va SÓLO en texto de UI y en los VALORES de columnas `label`/`name`/
   `description`.

4. **Estados y enums = tablas catálogo, NUNCA PHP Enums**, divididas en:
   - **CENTRALES**: el código ramifica sobre ellas (FSM, selectores de comportamiento).
     `BaseModel::columns($table, false)`, sin `BelongsToTenant`, UUID fijo como
     constante del modelo, sembradas una vez por `CatalogSeeder`.
   - **TENANT-SCOPED**: etiquetas que el admin edita, sin lógica de negocio.
     `BaseModel::columns($table, true)` + `BelongsToTenant`,
     `unique(['tenant_id','name'])`, sembradas por `TenantCatalogSeeder`.

   Regla de decisión: si el backend ramifica sobre la fila → central; si es
   clasificación libre → tenant-scoped.

5. **Toda columna propia de una migración lleva `->comment()`.** Y al crear una tabla,
   comentala **dentro** del closure (`$table->comment('...')` como primera línea).
   NUNCA encadenes `->comment()` a `Schema::create(...)`: devuelve `void` y explota.

6. **Dinero en `bigInteger` de unidades menores** (centavos), cast
   `Cknow\Money\Casts\MoneyIntegerCast`. Nunca `decimal` ni `float`.
   Aplica a suscripciones; el dominio clínico no maneja dinero.

7. **Medidas clínicas en enteros de unidad menor**: gramos, milímetros, décimas.
   Evita el redondeo lossy que tenía el legacy al comparar perímetro cefálico.

8. **Emails: se escriben en React Email, no en Blade.** La fuente es
   `frontend/emails/*.tsx`; el `.blade.php` es SALIDA GENERADA por
   `pnpm copy-emails`. Editar el Blade a mano es un bug: se pierde en la próxima
   compilación.

9. **Registrar módulos nuevos en el menú dinámico.** Checklist en orden:
   `database/seeders/csv/modules.csv` → `permissions.csv` (6 filas) →
   `module_system.csv` → los 5 mapas de `SystemTableSeeder` →
   `RolePermissionTableSeeder` → `AngularController::$mapperSelects` (si el módulo
   trae catálogos) → `useCatalogs.ts::CatalogType` → ruta en `App.tsx` con
   `PermissionRoute`.

   > `$mapperSelects` y `CatalogType` están acoplados por string vía `Str::studly`.
   > Un desajuste **no rompe el build**: deja el dropdown vacío en silencio.

---

## 4. Anti-patrones

- `any` en TypeScript.
- Stores manuales: usá `createEntityStore`.
- `axios` directo: usá `createCrudApi`.
- Interfaces locales que duplican un `schema.ts` existente.
- Envoltorios `IXxxResponse`: los endpoints devuelven ENTIDADES.
- `new Date()` para formatear: usá `displayFormatDate()` de `@/shared/utils/dateHelper`.
- `animate-pulse` para skeletons: usá `boneyard-js` (`<Skeleton name loading>`).
- `useToast()` de shadcn: el toast es `sileo`; importá `{ toast }` de
  `@/shared/hooks/useToast` y usá `toast.success(...)` / `toast.error(...)`.
- Escribir a mano un `.blade.php` de email.
- **Carga eager de catálogos**: nunca llames `useCatalogs` en el scope de una lista o
  en el cuerpo de un modal siempre montado — dispara `POST /selects` al montar.
  Formularios: al abrir el form. Filtros: `useLazyCatalogs` + `onFirstOpen`.

---

## 5. Entorno local

El stack corre en Docker con puertos propios, para convivir con otros proyectos:

| Servicio | Puerto |
|---|---|
| API (backend) | `8090` |
| Frontend (Vite) | `5290` |
| PostgreSQL de desarrollo | `5450` |
| PostgreSQL de tests (tmpfs) | `5451` |

Arranque: `./start.sh` (o `./start.sh --fresh` para recrear la base). `--check` sólo
diagnostica.

> `COMPOSE_PROJECT_NAME=edi` en `backend/.env` **debe** coincidir con `PROJECT_NAME`
> en `start.sh`. Si no coinciden, `sail up` reutiliza los contenedores de OTRO
> proyecto montados sobre SU código, y cualquier `sail artisan` escribe en la base
> equivocada.

### Tests de backend

Se corren con el **PHP del host**, no con `sail` ni `docker exec`:

```bash
# suite completa (paralelo)
DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password \
  php artisan test --parallel --processes=10

# un archivo o carpeta (en serie)
DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password \
  php artisan test --compact tests/Feature/Core
```

Las variables son OBLIGATORIAS: `phpunit.xml` fija `DB_HOST=pgsql.test`, un nombre
de la red de Docker que el host no resuelve. Las variables reales de entorno le ganan
al Dotenv de Laravel.

`backend/.env.testing` (local, no versionado) apunta Redis y Postgres a `127.0.0.1`
por la misma razón.

**Un solo proceso de test a la vez.** `RefreshDatabase` hace `migrate:fresh`; dos
corridas simultáneas se pisan el esquema y dan fallos no deterministas.
`--parallel` sí es seguro (bases separadas por proceso).

Formateo: `./vendor/bin/pint --dirty`.

### Tests de frontend

```bash
cd frontend
pnpm exec tsc -p tsconfig.app.json --noEmit
pnpm exec vitest run
pnpm build
```

---

## 6. Navegador y QA

La herramienta de navegador es **siempre Playwright** (`mcp__playwright__*`). No uses
la extensión de Chrome ni `chrome-devtools-mcp`. Abrí la app por el mismo origen que
la API para que la cookie de refresh de Sanctum matchee.

---

## 7. SDD

El proyecto usa Spec-Driven Development; los artefactos viven en `openspec/`.
Flujo: `sdd-explore` → `sdd-propose` → `sdd-spec` → `sdd-design` → `sdd-tasks` →
`sdd-apply` → `sdd-verify` → `sdd-archive`.

Config y contexto: `openspec/config.yaml`.

---

## 8. Deuda conocida

Cosas que se quitaron al derivar este proyecto de su plantilla y hay que reponer
cuando exista el módulo EDI correspondiente:

- **Sujeto de cuota de facturación.** `UnitLimitService` mide contra
  `QuotaSubjectCounter`, hoy ligado a `NullQuotaSubjectCounter` (devuelve 0, todo
  pasa). Cuando se defina qué se cobra (niños evaluados, tamizajes por período,
  centros), reemplazá el binding en `SubscriptionPlanServiceProvider` — nunca la
  lógica de `UnitLimitService`.
- **`/api/config/subscription`** desapareció con el módulo de configuración. Es el
  endpoint de autogestión de suscripción y hay que reponerlo.
- **Cobertura de billing**: tres tests que ejercitaban los límites de plan
  construían un escenario con unidades. Reponer al definir el sujeto de cuota.
- **Destinatarios de notificaciones por rol.** El motor despacha por rol
  (`DispatchesNotifications::userIdsWithRole`), pero no hay resolutores de dominio.
- **Catálogos tenant-scoped**: `TenantCatalogSeeder::CATALOGS` está vacío a propósito.
- **Whitelist de reportes**: `REPORT_MODEL_MAP` en `qch/core` está vacía; la
  maquinaria de export (filtros, Excel/PDF, async) sigue completa.
