# Diseño — Tableros contables (Dashboards) para Kivo7

- **Fecha:** 2026-06-16
- **Estado:** Diseño aprobado (pendiente revisión final del usuario)
- **Autor:** sesión "tableros"
- **Alcance acordado con el usuario:** 5 tableros, rutas separadas en el menú, audiencia Admin/Tesorero del clúster, librería Recharts + wrapper `chart` de shadcn, construir los 5 en un mismo esfuerzo (en work-units commiteables).

---

## 1. Contexto y problema

El "dashboard" actual (`/dashboard`) es en realidad un panel de tesorero (`frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx`):
4 KPIs, pagos recientes, cuentas bancarias y dos "gráficas" simuladas con barras CSS (`<i style="width:%">`). No hay gráficas reales.

**Hallazgo crítico:** `stack.md` declara "Recharts 2.15.x", pero **NO está instalado** (`frontend/package.json` no tiene Recharts ni ninguna librería de gráficas; no existe `frontend/src/components/ui/chart.tsx`). La documentación miente respecto a la realidad. Hay que elegir e instalar la librería.

**Buena noticia:** los módulos contables ya existen en disco con modelos y migraciones reales: **Collections** (cargos, pagos, FIFO, morosidad), **Treasury** (cuentas, egresos), **Projects** (proyectos comunitarios), **Providers**. Los tableros tendrán datos reales detrás.

## 2. Objetivo

Entregar 5 tableros contables, cada uno como ruta propia en el sidebar, para el Admin/Tesorero del clúster, con gráficas reales, temáticas (heredan el tema shadcn/Tailwind y el dark mode), accesibles y alimentadas por endpoints de agregación nuevos en el backend.

## 3. Decisión de librería de gráficas

**Recharts montado en el componente `chart` de shadcn.**

Razones:
- **MIT (gratis)**, declarativo, nativo de React — encaja con el patrón de componentes del proyecto.
- El wrapper de shadcn conecta las gráficas a las **CSS variables del tema** (Tailwind v4 + shadcn ya presentes) → heredan paleta y dark mode sin manejar colores a mano.
- Trae **accesibilidad** (tooltips, focus, leyendas) — cumple la regla de WCAG del proyecto.
- Es la intención original de `stack.md`.

Descartadas: **Tremor** (impone su propio design system, choca con shadcn), **Chart.js** (canvas, no idiomático en React), **Nivo/visx** (más potentes pero mucho más trabajo del necesario).

> **Nota React 19:** Recharts 2.x funciona con React 19 pero puede requerir `--legacy-peer-deps` o usar **Recharts 3.x** (mejor soporte React 19). La versión exacta se fija en la fase de implementación; el wrapper de shadcn es compatible con ambas.

## 4. Arquitectura

### 4.1 Frontend — fundación de gráficas (compartida, se construye primero)

- Instalar `recharts` y añadir el componente `frontend/src/components/ui/chart.tsx` de shadcn (`ChartContainer`, `ChartTooltip`, `ChartTooltipContent`, `ChartLegend`).
- Crear wrappers temáticos delgados en `frontend/src/shared/components/charts/`:
  - `LineBarComboChart` — barras + línea (ingresos/egresos + neto).
  - `GroupedBarChart` — barras agrupadas / aging.
  - `DonutChart` — distribución por categoría/estado.
  - `AreaTrendChart` — evolución de saldo / tendencia.
- Todos reciben datos ya agregados y formatean dinero con `formatMinorUnits` (de `frontend/src/shared/utils/money.ts`) en ejes y tooltips.

### 4.2 Frontend — estructura por tablero

Cada tablero es una página read-only (no CRUD). Sigue el patrón de `TreasurerHome`:
`useQuery` (TanStack) + un `Resource` con método custom (vía `createCrudApi`/`resourceApi`), **no** `useBaseList`.

```
frontend/src/modules/dashboards/<nombre>/
  <Nombre>Dashboard.tsx        # página, compone KPIs + gráficas
  services/<Nombre>Resource.ts # GET al endpoint de agregación
  schema.ts                    # interfaces del payload (tipadas, sin any)
```

Reutiliza: `KpiTile` (`treasurerPanel/components/KpiTile.tsx`), `ResponsiveDataTable` (top deudores, listas), `PageHeader`, `StatusPill`, `Callout`, utilidades `displayFormatDate`, `formatMinorUnits`. UI 100% en español.

### 4.3 Frontend — rutas y permisos

Cada tablero se monta en `App.tsx` con `PermissionRoute permissionEntity="<entity>"`. Grupo de menú: **"Tableros"**.

### 4.4 Backend — endpoints de agregación

Cada tablero consume un endpoint nuevo que devuelve agregados en **minor units** (centavos), respetando tenancy (`initializeTenancy`) y permisos. Formato JSON consistente (no `useBaseList`, son agregaciones). Toda la lógica de agregación vive en un **Service** por módulo; el controller solo orquesta (SRP, patrón Orion + Service del proyecto).

### 4.5 Registro de módulos (regla 3 de CLAUDE.md)

Los 5 tableros aparecen en el sidebar ⇒ deben registrarse como módulos. Por cada uno:
`modules.csv`, `permissions.csv`, `module_system.csv`, `SystemTableSeeder` (entity, ruta, icono, visibilidad), `RolePermissionTableSeeder`, ruta en `App.tsx`. Luego `php artisan db:seed --class=ResetPermissionTableSeeder`.

**Permisos:** se crean 5 entidades nuevas de tipo reporte/lectura:
`report_pnl`, `report_collections`, `report_treasury`, `report_expenses`, `report_projects`.
Son tableros read-only ⇒ solo `read` (y opcionalmente `report`) tiene sentido funcionalmente. **Decisión a confirmar en SDD-design:** seguir la convención de 6 filas por consistencia del seeder, o registrar solo `read`. `account.owner`/`company.admin` los heredan automáticamente vía `whereNotIn`; a Treasurer/President/Oversight se les otorga explícitamente el board que les corresponde en `RolePermissionTableSeeder`.

### 4.6 Iconos

Cada módulo requiere icono (regla 3). Usar de `frontend/src/shared/utils/iconMap.ts` (ej. `TrendingUp`, `AlertTriangle`, `Wallet`, `Receipt`, `Target`).

## 5. Especificación por tablero

> Todos los montos en minor units. `label` = etiqueta legible (ej. "Jun 2026").

### 5.1 Estado de resultados — `/tableros/resultados` (`report_pnl`, icono `TrendingUp`)
- **KPIs:** ingresos 12m, egresos 12m, neto, margen %.
- **Gráfica:** `LineBarComboChart` — barras ingresos vs egresos por mes (12) + línea de neto.
- **Endpoint:** `GET /treasury/income-expense-series?months=12`
  ```json
  { "series": [{ "year": 2026, "month": 6, "label": "Jun 2026",
      "income_minor": 0, "expense_minor": 0, "net_minor": 0 }],
    "totals": { "income_minor": 0, "expense_minor": 0, "net_minor": 0 } }
  ```

### 5.2 Cobranza / Morosidad — `/tableros/cobranza` (`report_collections`, icono `AlertTriangle`) ⭐
- **KPIs:** cartera vencida total, tasa de cobro del mes %, unidades morosas, antigüedad promedio (días).
- **Gráficas:** `GroupedBarChart` aging (corriente / 1-30 / 31-60 / 61-90 / 90+); `DonutChart` por `charge_status`; tabla **top deudores** (`ResponsiveDataTable`).
- **Endpoint:** `GET /collections/aging`
  ```json
  { "aging": { "current_minor": 0, "d1_30_minor": 0, "d31_60_minor": 0,
      "d61_90_minor": 0, "d90_plus_minor": 0 },
    "total_outstanding_minor": 0, "units_with_debt": 0, "collection_rate_pct": 0,
    "by_status": [{ "status_key": "overdue", "label": "Vencido", "count": 0, "amount_minor": 0 }],
    "top_debtors": [{ "unit_id": "uuid", "unit_label": "Torre A-101",
      "outstanding_minor": 0, "oldest_due_date": "2026-01-15" }] }
  ```
  (Reutiliza lógica/relaciones de `PaymentController::delinquency`.)

### 5.3 Tesorería / Flujo de caja — `/tableros/tesoreria` (`report_treasury`, icono `Wallet`)
- **KPIs:** saldo total, # cuentas, entradas del mes, salidas del mes.
- **Gráficas:** `AreaTrendChart` evolución de saldo (12m); `GroupedBarChart` entradas vs salidas por mes; lista de cuentas con saldo.
- **Endpoint:** `GET /treasury/cashflow-series?months=12`
  ```json
  { "accounts": [{ "id": "uuid", "name": "Caja", "current_balance_minor": 0 }],
    "total_balance_minor": 0,
    "series": [{ "label": "Jun 2026", "inflow_minor": 0, "outflow_minor": 0,
      "running_balance_minor": 0 }] }
  ```

### 5.4 Gastos — `/tableros/gastos` (`report_expenses`, icono `Receipt`)
- **KPIs:** gasto del mes, gasto del año, # proveedores con gasto, % recurrente.
- **Gráficas:** `DonutChart` por categoría; `GroupedBarChart` top proveedores; `AreaTrendChart` tendencia mensual.
- **Endpoint:** `GET /treasury/expenses-breakdown?months=12`
  ```json
  { "by_category": [{ "category_id": "uuid", "label": "Mantenimiento",
      "amount_minor": 0, "pct": 0 }],
    "by_provider": [{ "provider_id": "uuid", "name": "X", "amount_minor": 0 }],
    "monthly": [{ "label": "Jun 2026", "amount_minor": 0 }],
    "total_minor": 0, "recurring_minor": 0, "one_off_minor": 0 }
  ```

### 5.5 Proyectos comunitarios — `/tableros/proyectos` (`report_projects`, icono `Target`)
- **KPIs:** proyectos activos, meta total, recaudado total, % avance global.
- **Gráficas:** `GroupedBarChart` recaudado vs meta por proyecto; `DonutChart` por `community_project_status`.
- **Endpoint:** `GET /projects/funding-summary`
  ```json
  { "projects": [{ "id": "uuid", "title": "Barda perimetral", "goal_minor": 0,
      "raised_minor": 0, "pct": 0, "status_key": "active", "status_label": "Activo" }],
    "totals": { "active_count": 0, "goal_minor": 0, "raised_minor": 0, "pct": 0 },
    "by_status": [{ "status_key": "active", "label": "Activo", "count": 0 }] }
  ```

## 6. Estados de UI (obligatorio en cada tablero)
- **Loading:** `boneyard-js` (`<Skeleton loading={isLoading}>`), nunca `animate-pulse`.
- **Empty:** mensaje claro cuando no hay datos en el período (ej. clúster recién creado).
- **Error:** toast con `toast.error(...)` de `@/shared/hooks/useToast` (sileo) + estado de error inline.
- **Permisos:** panel/cuenta sensible oculto si falta el permiso (`usePermissions`).
- **Responsive:** mobile/tablet/desktop; gráficas con `ResponsiveContainer`; tablas vía `ResponsiveDataTable`.

## 7. Testing
- **Backend:** test de feature por endpoint de agregación con datos sembrados (verifica montos en minor units, scope por tenant, gate de permiso). Pest/PHPUnit según `backend/CLAUDE.md`.
- **Frontend:** test de los schemas Zod/interfaces y de cada `Resource` (mapea payload), y smoke test de render de cada dashboard con datos mock (Vitest + Testing Library).

## 8. Orden de construcción (work-units, aunque se entreguen los 5)
1. **Fundación frontend de gráficas** (instalar Recharts + `chart.tsx` + 4 wrappers temáticos).
2. **Backend:** endpoints de agregación (Services + controllers + rutas + tests) — Treasury (resultados, flujo, gastos), Collections (aging), Projects (funding).
3. **Registro de los 5 módulos** (seeders + iconos + permisos + `ResetPermissionTableSeeder`).
4. **Frontend de cada tablero** (página + Resource + schema + rutas en `App.tsx`).
5. **Revisión** (`/review-stack`: laravel-architecture, react-frontend, integration, tenancy, database).

## 9. Supuestos y decisiones por confirmar
- Permisos read-only: 6 filas por convención vs solo `read` (se cierra en SDD-design).
- Reutilizar relaciones existentes de Collections/Treasury para las agregaciones (evitar N+1; usar `selectRaw`/`groupBy` con scope de tenant).
- Ventana por defecto: 12 meses (parametrizable por query).
- Zona horaria del tenant (`tenant.timezone`) para agrupar por mes.

## 10. Fuera de alcance (YAGNI por ahora)
- Tablero cross-tenant de Super Admin (suscripciones/planes) — es otro tablero con datos de Tenant/SubscriptionPlan; se hará aparte si se pide.
- Vista de residente.
- Exportación a PDF/Excel de los tableros (el módulo Reports lo cubrirá).
- Presupuesto vs real (no hay datos de presupuesto hoy).
- Filtros avanzados / comparativas año contra año (iteración futura).
