---
name: module-config
description: Use when creating a NEW module/feature for a multi-tenant application that needs per-tenant global configuration (feature toggles, notification flags, retention/grace days, required signature/QR/code, evidence, disclaimers, thresholds, mode selectors), or when the user asks to add/define/parametrize configuration for a system module. Drives an INTERACTIVE flow — confirm the target system module, analyze the requirement, PROPOSE config parameters, confirm each one, ask "¿algún otro?" — then hands the confirmed manifest to the `module-config-scaffolder` agent which writes the backend (Setting + Configuration endpoints + seeded defaults) and the tenant Configuration section registered into `configSections`. Invokable manually as /module-config.
tags: [config, tenant, settings, module, configuration, scaffolding]
metadata:
  author: Rodriguez
  version: "1.0"
---

# Module Config — configuración global por módulo de sistema

Cada módulo funcional de la aplicación necesita su **configuración global por tenant** (como
Deliveries: avisar al llegar, exigir QR/firma, días de retención, etc.). Este skill
NO escribe código: **conduce el diálogo** para definir QUÉ parámetros lleva el módulo
y luego **delega** la escritura al agente `module-config-scaffolder`.

> **Regla de oro:** este skill se dispara SIEMPRE que creás un módulo/feature nuevo
> que pueda tener configuración. Si no estás seguro de si la necesita, preguntá. El
> patrón es barato y consistente; un módulo sin su config es un olvido, no una decisión.

## Cuándo se activa

- Auto: al crear un módulo/feature nuevo (Inventory, Order Fulfillment, Finance, etc.) que
  tenga toggles, avisos, tiempos, requisitos de entrega, textos legales o umbrales.
- Manual: `/module-config` para agregar/ampliar la config de un módulo ya existente.

## El patrón canónico (lo que YA existe — no se reinventa)

La autoridad de cómo se escribe el código está en los skills `laravel-backend-architecture`,
`react-frontend-architecture` y `stancl-tenancy`. El shape de la config sigue el ejemplo de Deliveries:

- **Storage** → tabla genérica tenant-scoped `setting` (`slug` + `value` + `type`).
  Slugs namespaced por módulo: `package.auto_notify_on_arrival`. Lectura:
  `Setting::getValue('package.x')`. NO se crean tablas por módulo.
- **API** → un único `ConfigurationController` (módulo `Configuration`) con un par de
  métodos por módulo `getXSettings`/`updateXSettings` y rutas `GET/PUT config/x-settings`.
  Keys planas en la API ↔ slugs `module.*` internos. Permiso unificado
  `read/update.configuration`. Validación cruzada en el controller.
- **Defaults** → se siembran con `Setting::firstOrCreate` en `TenantCreated` (módulo
  `Billing` lo hace bien; **Deliveries no lo hacía** — el agente SIEMPRE cierra ese gap).
- **Frontend** → `frontend/src/modules/configuration/configSections.tsx` es el **registro
  único**: cada módulo aporta UNA entrada `{ key, label, icon, title, description, render }`
  y `ConfigurationPage` la pinta sola. La sección es un `XConfigSection` autocontenido
  (React Query + `useSectionEdits` + resource a `/config/x-settings`).

## EL FLUJO (lo conducís vos, en el hilo principal)

Hacelo paso a paso. **Después de cada pregunta, PARÁ y esperá la respuesta del usuario.**

### 1. Confirmá el módulo de sistema destino
Preguntá a qué módulo pertenece la config (Inventory, Billing, Order Fulfillment, …).
De ahí derivás el **namespace de slug** en minúscula sin espacios:
`Order Fulfillment → orderfulfillment`, `Customer Support → customersupport`. Confirmalo.

### 2. Analizá el requerimiento y PROPONÉ parámetros
Leé lo que se está construyendo. Contra el **catálogo de arquetipos** (abajo), inferí
parámetros candidatos y presentalos en una tabla:

| key | tipo | default | label (ES) | validación | UI |
|---|---|---|---|---|---|
| `require_qr` | boolean | `true` | Exigir QR en el acceso | — | checkbox |
| `visitor_expiry_hours` | integer | `24` | Horas de validez del QR | `min:1\|max:168` | number |

Cada parámetro debe traer: `key` (snake_case, EN), `type` (boolean/integer/string/text/json),
`default` (NO null — se siembra), `label` y ayuda en español, `validación` sugerida, y `ui`.

### 3. Confirmá uno por uno
El usuario acepta, edita o descarta cada parámetro. No avances con supuestos.

### 4. Repreguntá "¿algún otro parámetro?"
Loop hasta que el usuario diga "listo". Sugerí arquetipos que falten y peguen con el módulo.

### 5. ¿Hace falta lectura accesible a roles no-admin?
Si algún setting afecta el RUNTIME para roles que no entran a Configuración (ej. un rol
operativo que necesita saber si se exige código, como `GET /package/pickup-policy`),
marcá `guard_read_endpoint: true`. Por default es `false`. **Preguntá, no asumas.**

### 6. Cerrá el manifiesto y delegá
Armá el manifiesto (abajo) y delegá al agente `module-config-scaffolder` para que lo
implemente. Pasale el manifiesto completo en el prompt.

## Catálogo de arquetipos (para que las propuestas sean concretas y consistentes)

| Arquetipo | Patrón de key | type | UI | Notas |
|---|---|---|---|---|
| 🔔 Notificación | `auto_notify_on_*` | boolean | checkbox | "avisar cuando…" |
| ✍️ Verificación/entrega | `require_signature`, `require_qr`, `require_code`, `requires_evidence` | boolean | checkbox | como Deliveries |
| ⏱️ Tiempos/retención | `*_after_days`, `grace_days`, `reminder_after_days` | integer | number | min/max + validación cruzada (ej. `reminder < return`) |
| 🔢 Umbrales/límites | `max_*`, `min_*` | integer | number | min/max |
| 🔘 Toggle de feature | `enabled`, `*_enabled` | boolean | checkbox | prende/apaga sub-feature |
| 📝 Texto legal/disclaimer | `*_disclaimer`, `*_terms` | text | textarea | maxlength (ej. 1000) |
| 🎯 Modo/selector | enum simple | string | select | opciones acotadas |

## El manifiesto (contrato skill → agente)

```yaml
module: OrderFulfillment          # módulo de sistema (PascalCase, como en Modules/)
slug_namespace: orderfulfillment  # prefijo de slug en `setting`
settings:
  - key: require_qr
    type: boolean
    default: true
    label: "Exigir QR en el acceso"
    ui: checkbox
  - key: visitor_expiry_hours
    type: integer
    default: 24
    validation: "min:1|max:168"
    label: "Horas de validez del QR"
    ui: number
cross_field_validation:        # opcional; reglas que cruzan dos settings
  - "reminder_after_days < return_after_days"
guard_read_endpoint: false     # true si roles no-admin necesitan leer settings en runtime
```

## Handoff al agente

Lanzá `module-config-scaffolder` (Agent tool) con el manifiesto íntegro y el módulo.
El agente carga `laravel-backend-architecture`/`react-frontend-architecture`/`stancl-tenancy`, escribe el slice completo
(backend `Setting` + `ConfigurationController` + rutas + **siembra de defaults** +
`XConfigSection` + **entrada en `configSections`**) y devuelve un reporte de archivos.

> El agente NO existe en el registry hasta correr `/agents` (o reiniciar). Si recién se
> creó y no aparece como tipo disponible, avisá al usuario que corra `/agents`.

## Después de escribir (recordatorio de review)

Corré en paralelo: `@tenancy-reviewer` (la tabla `setting` es tenant-scoped),
`@integration-reviewer` (contrato back↔front) y `@infra-cost-reviewer`.

## Reglas duras / gotchas

- **Defaults SIEMPRE sembrados** con `Setting::firstOrCreate` en `TenantCreated`
  (preserva ediciones del admin, no pisa). Nunca defaults hardcodeados sueltos en un service.
- **Slug namespacing** obligatorio: `modulo.key`. La API expone keys planas; el mapeo
  plano↔slug vive en el `ConfigurationController`.
- **Gotcha de slug con punto**: en la validación del request, las keys con punto rompen
  la notación de Laravel — usá keys planas en las reglas y leé con `$request->all()`
  (ver cómo lo resolvió Deliveries en `ConfigurationController`).
- **Permiso unificado** `read/update.configuration` — no inventes un permiso por módulo
  para la pantalla de Configuración.
- **UI en español** (labels, ayudas, toasts). Identificadores en inglés (keys, slugs).
