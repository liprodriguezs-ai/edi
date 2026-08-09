# Interface Design System — Kivo7 (Collections / SaaS admin)

> Herramienta de administración de clústeres residenciales. Esto NO es marketing — es software de trabajo.

## Direction & Feel
**Cockpit de control.** Denso pero ordenado, financiero-confiable. El admin configura reglas de cobro y ve los resultados en una sola superficie ("mejor control", no por partes).

## Depth Strategy
Borders + sutiles cambios de superficie. NADA de sombras dramáticas. Cards `rounded-[14px] border bg-card`. Un panel de drill (ej. cargos por unidad) se eleva UN nivel sobre el card del periodo (`bg-[var(--surface-2)]`). Bordes de baja opacidad — se sienten, no gritan (squint test).

## Tokens (definidos en `frontend/src/index.css`)
- **Texto/jerarquía:** `--ink` … `--ink-3` (#888d83 = metadata/secundario; color del back-link).
- **Estado financiero (color MOTIVADO, no decorativo):** `--g` #3f9a5e (ok / pagado / ≥80%), `--y` #c0862b (ámbar / parcial / 40–79%), `--r` #c0533c (vencido / error / <40%).
- **Superficies:** `bg-card`, `bg-[var(--surface-2)]` (elevación del drill).
- **Radio:** `rounded-[14px]` en cards. NUNCA hex crudo en clases — siempre tokens.

## Key Patterns
- **Cockpit master-detail:** config editable a la izquierda + resultados a la derecha; `grid lg:grid-cols-[1fr_1fr]`, apila en móvil. Ref: `ChargePolicyCockpitPage` (regla + lotes + cargos).
- **Form en página (no Sheet) para config densa:** header = back-link (ArrowLeft `--ink-3`) + `h1` + botón submit asociado al form vía `form="id"` (no `onClick` redundante).
- **Filas expandibles (drill):** `aria-expanded`, chevron `rotate`, animación `grid-template-rows: 0fr → 1fr` `duration-300 ease-out` (deceleración, SIN bounce). Panel expandido un nivel de superficie arriba.
- **Skeleton con silueta:** el loading replica la forma real de la fila (chevron + título + pill + barra), no bloques genéricos `bg-muted`.
- **Listas:** `ResponsiveDataTable` (breakpoint lg → card en móvil), headers `scope="col"`, acciones con `aria-label`.
- **Money/fechas:** `tabular-nums` para montos; `displayFormatDate`. Nunca `new Date()` para formateo. Conversión pesos↔minor units en build*Payload; % ↔ basis points para recargos.
- **Forms:** RHF + Zod (schema.ts = dueño de tipos). Errores 422 inline vía `useBaseCrud onError → setError` (limitación actual del hook: solo en CREATE).

## Don'ts
- Hex crudo en clases → usar `--g/--y/--r/--ink-3`.
- `<Sheet>` para pantallas densas de configuración → usar página/cockpit. Sheet SÍ para forms chicos (Concepto = identidad) y pagos (`RegisterPaymentSheet`).
- Sombras dramáticas, múltiples acentos, hues distintos por superficie (mismo hue, solo cambia luminosidad).
- Toast de success manual en forms → ya lo emite `useBaseCrud` (no duplicar).
