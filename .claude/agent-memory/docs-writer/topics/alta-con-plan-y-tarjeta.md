---
name: alta-con-plan-y-tarjeta
description: 2026-08-07 — el alta cambió a plan+tarjeta obligatorios y 30 días de prueba; qué páginas del manual se reescribieron y qué strings quedaron prohibidos
metadata:
  type: project
---

El flujo de alta de suscripción cambió de raíz (cambio openspec
`signup-requires-plan-and-card`). Reescribí el manual para reflejarlo.

**Qué documenta ahora el manual:** te registras → **obligatoriamente** eliges
plan y registras tarjeta en Stripe (sin eso no entras a la app) → **30 días
gratis** → el **día 31 se cobra automático** → si ese cobro falla, el acceso se
pausa el mismo día, **sin días de gracia**, y se reactiva solo al confirmarse el
pago. El plan **A medida** (>300 casas) lo activa a mano el equipo de Kivo7.

**Why:** el manual describía el flujo viejo (14 días, entrada directa, plan
opcional después) y habría mentido en el paso más caro del embudo.

**How to apply:**

- El contrato de textos aprobado es
  `openspec/changes/signup-requires-plan-and-card/copy.md`. Manda sobre la
  redacción: liderar con la ganancia (30 días), la tarjeta en segundo lugar, y
  matar el miedo al cobro sorpresa en la misma frase.
- **Strings PROHIBIDOS en todo `landing/src` y `frontend/src`:** `sin tarjeta`,
  `Sin tarjeta`, `14 días`, `14 dias`. Hay un grep de verificación en copy.md que
  debe devolver vacío — el manual cae dentro de `landing/src`, así que ni
  siquiera para hablar de los condominios heredados se puede escribir el número
  viejo (usa "la duración con la que se registraron").
- Los tres estados bloqueados **se leen distinto a propósito** y sus títulos son
  literales de copy.md §8: *Elige tu plan para empezar* · *Tu prueba terminó* ·
  *No pudimos procesar tu pago*. El no-propietario ve *El acceso está en pausa*.
  Nunca los fundas en un solo mensaje genérico.
- Preferimos **"en pausa"** sobre "bloqueado"/"suspendido": es reversible y el
  texto debe sentirse reversible.

**Página nueva:** `cuenta/acceso-en-pausa.mdx` (sidebar order 4;
`documentos-del-condominio` se corrió a 5). Es la **fuente única** de los estados
bloqueados: crear-cuenta, planes-y-precios, cambiar-de-plan, cancelar-y-reanudar,
configuracion/plan, FAQ y Solución de problemas **enrutan** hacia ella. Sus
anclas son contrato: `#nunca-elegiste-plan`, `#se-acabó-la-prueba`,
`#el-cobro-falló`.

**Corrección de un dato que el manual traía mal desde antes:**
`estructura/limite-de-unidades` afirmaba que durante la prueba el alta de casas
"pasa sin restricción". Falso: `UnitLimitService::resolveBand()` resuelve la
banda desde los `TenantEntitlement` activos, y la prueba (vieja y nueva) ya trae
un plan asociado. Los únicos casos sin tope son **A medida**
(`suggested_unit_max = null`) y un condominio sin plan activo, que de todos modos
no puede entrar.

**HAY DOS TIPOS DE PRUEBA y el manual tiene que distinguirlos** (2026-08-07): la
contratada por Stripe **se convierte sola** (ya tiene plan y tarjeta; el día 31
cobra y el dueño no hace nada) y la **de cortesía** que da el super admin al dar
de alta un condominio a mano **NO se convierte sola** (sin plan ni tarjeta: hay
que contratar antes o el acceso se pausa). Las dos reportan `status='trial'`; las
separa el flag `trial_auto_charges` de
`app/Services/SubscriptionStateService.php:171` (verdadero si hay algún
entitlement `source=stripe`). El banner de `/select-plan` ya ramifica sobre él.
Si el manual describe solo una, la mitad de los lectores hace lo incorrecto.
Consecuencia que hay que decir siempre: la prueba es **una por condominio de
cualquiera de los dos tipos** — quien ya usó la de cortesía y luego contrata es
cobrado de inmediato.

**Dónde ve el usuario cuánto le queda de prueba** (verificado en código el
2026-08-07, ya con la implementación cerrada): son DOS lugares, no uno.

1. **Mi suscripción** (`/subscription`) — `subscriptionPresentation.tsx`: la
   etiqueta **Periodo de prueba** (`STATUS_LABELS.trialing`), y las tarjetas
   **Fin del periodo de prueba** y **Próximo cobro** de `SubscriptionDates`. Es
   el lugar principal y también lo ve el **Administrador**, en solo lectura:
   `SubscriptionPage.tsx:110` gatea con `manage.subscription`, que el rol
   Administrador sí tiene (`$administrator` = todo menos exclusivos de super
   admin).
2. **Pantalla de planes** (`/select-plan`, `OwnerPlanView`) — la cuenta
   regresiva en días, condicionada a `status === 'trial' && trialEndsAt`. Esta
   sí es **exclusiva del Propietario de la cuenta**.

Ojo: el `TrialExpiryBanner` de `modules/user/components/` NO aplica a la prueba
de Stripe — `ConfigurationController::activeTrialEntitlements()` filtra por
`source = SOURCE_TRIAL`, así que solo se pinta para la prueba local heredada y
la de cortesía del super admin.

Títulos ya implementados y citados literales: **Listo, tu prueba empezó**
(éxito) y **Tu prueba está en camino** (confirmación demorada, con **Reintentar**
e **Ir al panel**). El viejo *Pago recibido* ya no existe.

**Rediseño de `/select-plan`: UN SOLO FOCO POR ESTADO** (2026-08-07, tras QA de
navegador con Pedro — el selector de 6 planes salía siempre debajo del mensaje y
sugería una acción equivocada). El armador (`PlanBuilder`) ahora solo se muestra
de entrada cuando elegir plan ES la tarea: `planPickerIsTheTask` =
`blockedReason === 'none' || 'expired' || (trial && !trialAutoCharges)`
(`SelectPlanPage.tsx:466`). Los cuatro estados:

| Estado | Encabezado | Foco | ¿Planes visibles? |
|---|---|---|---|
| Recién registrado | *Elige tu plan para empezar* | armador | sí |
| Prueba de Stripe | *Tu prueba está en marcha* | tarjeta **Tu primer cargo** + fecha + *Te quedan N días de prueba* + enlace **Cambiar de plan** | no |
| Prueba de cortesía | *Elige el plan de tu condominio* | banner azul + armador | sí |
| Cobro fallido | *No pudimos procesar tu pago* | botón **Actualizar mi tarjeta** + enlace **Prefiero cambiar de plan** | no |

Ojo con dos literales que cambiaron: la pantalla de alta se llama **Elige tu plan
para empezar** (no "Elige tu plan"), y en el estado de cobro fallido el
secundario es **Prefiero cambiar de plan** (antes un botón *Cambiar de plan*).
El contador pasó de `Math.ceil` a `Math.round` (`:421`): el primer día de una
prueba de 30 decía "31 días restantes"; ahora dice 30.

Relacionado: [[mapa-pagina-codigo]], [[defectos-encontrados]].
