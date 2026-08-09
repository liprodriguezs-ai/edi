# Hallazgos de QA — refactor billing (2026-07-20)

Formato: ID | severidad | dónde | qué pasa | cómo reproducir

---

## F-01 | MEDIA | backend `BillingCheckoutRequest` / `MultiItemSubscriptionService::buildCheckout`
**El checkout no valida que las unidades declaradas quepan en el plan elegido.**
`POST /api/billing/checkout {"plan_key":"premium","interval":"month","units":450}` → **HTTP 200** con
sesión de Stripe válida. Premium cubre 201–300; 450 debería caer en `a_medida` → 422 `contact_sales`.
Consecuencia: el cliente paga un plan que no le alcanza y después `UnitLimitService` lo bloquea al
intentar dar de alta la unidad 301. Es fuga de ingreso y mala experiencia post-pago.
La UI ya lo previene (card Contáctanos), pero la API queda abierta a clientes de API y a estados viejos
de caché del catálogo.
Fix propuesto: si `units` viene y supera `max_billable_units` → 422 `contact_sales`; si cae dentro del
rango de pago pero por encima del `suggested_unit_max` del plan elegido → 422 indicando el plan correcto.

---

## F-02 | BAJA | `PlanModuleMatrixSeeder` — pivote del módulo `base`
**Amenidades no está en lo que concede el plan base, pero el copy la promete.**
El pivote `billable_module_system_module` de `base` lista 11 módulos de plataforma (Cargos, Avisos,
Control de Accesos, Egresos, Proveedores, Cuentas Bancarias y el clúster completo de Paquetería) pero
NO incluye `Amenidades` ni `Reservaciones`, que sí existen en la plataforma (permission_entity
`amenity` / `amenity_reservation`, 4 tablas). Venía así porque el módulo vendible `amenities` que
colapsamos tenía el pivote VACÍO.
Impacto real bajo: `moduleEnabled` es opt-out (sólo bloquea con una fila `enabled=false`), así que
hoy Amenidades queda accesible igual, y al vencer la suscripción `EnsureSubscriptionActive` corta
toda la API de todos modos. Pero es una inconsistencia: al revocar el derecho, Amenidades no se
apaga explícitamente como sí lo hacen los otros 11.
Fix propuesto: agregar `Amenidades` y `Reservaciones` al pivote de `base` en el seeder.

---

# RONDA 4 — super-admin y vías sin Stripe (verde salvo lo de abajo)

VERDE comprobado: wizard 4 modos sin checkboxes de módulos; a_medida permitido en cortesía y 422
contact_sales en tarjeta/manual; panel colapsado a nivel plan; pago manual extiende el ciclo, escribe
el ledger y reenciende los 11 módulos; cambio de método de pago verificado CONTRA STRIPE REAL en sus
dos modos; swap preferencial OK con su guard; y la exclusión crítica del cron confirmada (un
entitlement `source=stripe` vencido hace 2 días quedó intacto, ni apareció en la salida del comando).

## F-03 | ALTA | `TenantEntitlementController::revoke()`
**Revocar es estructuralmente imposible después del colapso.**
El guard rechaza revocar el módulo base cuando `activeBaseCount($tenant) <= 1`. Con un solo
`billable_module` (y es base) más el índice único (tenant_id, billable_module_id), un tenant tiene
como máximo UNA entitlement; si está activa el contador vale exactamente 1 → el guard dispara SIEMPRE.
Repro: panel de clústeres → Facturación → "Revocar cortesía" →
`POST /api/tenant/{id}/entitlement/revoke {"module_key":"base"}` → 422 "No se puede revocar el módulo
base: dejaría al clúster sin un plan base activo." La entitlement sigue viva.
Consecuencia: una cortesía otorgada por error no se puede quitar por ninguna vía de producto.
Agravante: el 422 NO muestra toast; el botón parece no hacer nada.
Fix: el guard perdió sentido con un solo módulo. Revocar debe permitirse (dejando al clúster sin
entitlement activo = bloqueado, que es justamente lo que el operador quiere). Y mostrar el error.

## F-04 | MEDIA | `EntitlementsSection` — desplegable de swap preferencial
Lista TODOS los planes menos el actual, pero `PreferentialSwapController` exige `is_public=false`,
así que las 5 opciones públicas devuelven 422 sin excepción. Debe listar sólo variantes ocultas.

## F-05 | BAJA | `TenantEntitlementController::resolvePlanOrFail()`
`grant-free` con `plan_key: "a_medida"` explícito → 422 "Plan inválido o inactivo", porque llama
`planByKey()` sin `includeContactSales: true` (el ProvisioningService sí se actualizó, este no).
Impacto acotado: la UI no manda `plan_key` y la vía implícita funciona.

## F-06 | BAJA | `tenant.status` no se actualiza al otorgar cortesía
Un clúster provisionado en modo tarjeta queda `pendiente_pago`; al darle cortesía la entitlement
queda activa y los módulos encendidos, pero el status sigue en `pendiente_pago` y la lista lo muestra
así. Inofensivo (is_blocked se decide por entitlements), pero engaña al operador.

## F-07 | BAJA | Copy obsoleto que aún habla de módulos vendibles
- Wizard/cortesía: "Otorga acceso gratuito y permanente a los paquetes que elijas" (ya no se elige).
- Modal de facturación, subtítulo: "…y controla los módulos contratados".
- Pago manual: "cubre todos los módulos contratados" / "no tiene módulos de pago por cobrar".

## F-08 | BAJA | "Siguiente" no se deshabilita con a_medida + pago manual
La advertencia se muestra pero el botón queda habilitado; la defensa es el 422 del backend, que
(ver F-03) puede no mostrarse al operador.

## F-09 | BAJA | Deriva contrato vs JSON real
El contrato documenta los módulos del catálogo como `key`; la API devuelve `module_key`. Los campos
nuevos están todos correctos y verificados.

## Residuo de QA en la base dev (limpiar antes de producción)
8 tenants `qa-r4-*`; `qa-r4-trial` tiene su entitlement FORZADA a `source=stripe` con
`stripe_subscription_id='sub_qa_r4_fake'` (dato de laboratorio, no legítimo); usuario
`qa-r4-owner@example.test` con 2 suscripciones Stripe test canceladas; plan oculto
`qa_r4_preferencial` con 2 precios reales en Stripe test.

---

# RONDA 2 — ciclo de vida del dueño (checkout REAL cobrado en Stripe test)

Método: registro real → checkout real → pago real con 4242 (factura MX$950 pagada, sub_1TvAK2F4...).
NO hay `stripe listen` ni túnel en este entorno, así que los eventos se trajeron REALES desde la API de
Stripe, firmados con HMAC usando el secret del backend y POSTeados al endpoint real. Lo único simulado
es el transporte.

VERDE: /subscription muestra el plan (bug histórico #14 arreglado en su causa); upgrade con preview;
downgrade bloqueado por el guard de unidades (422 UNIT_LIMIT_EXCEEDED con plan sugerido);
cancelar (cancel_at_period_end, sin prorrateo, acceso intacto); reactivar; portal devuelve URL válida;
y los 4 endpoints de módulos borrados dan 404 en POST/DELETE/PUT.

## F-10 | BLOQUEANTE | `StripeWebhookListener::handleCheckoutCompleted`
**Un checkout REAL de Stripe no otorga entitlements: el dueño paga y queda en "Sin plan activo".**
El handler lee `$payload['data']['object']['metadata']` = metadata de la SESIÓN. Pero
`MultiItemSubscriptionService::buildCheckout` la setea con `newSubscription(...)->withMetadata([...])`,
y Cashier eso lo manda a `subscription_data.metadata`, o sea a la SUSCRIPCIÓN.
Comprobado contra Stripe real: `session.metadata = []` mientras
`sub.metadata = {tenant_id, plan_key, interval, ...}`.
Log: `checkout.session.completed: tenant not resolvable from metadata {"metadata":[]}`.
`invoice.payment_succeeded` NO rescata: exige que YA exista un entitlement stripe con ese
subscription_id, así que sólo sirve para renovaciones.
Por qué los tests no lo cazan: fabrican el payload con la metadata a nivel sesión.
Repro: registrar → checkout → pagar con 4242 → entregar el `checkout.session.completed` real →
`GET /config/subscription` sigue con `multi_item: null` y el trial intacto.
Fix: resolver el tenant desde la metadata de la SUSCRIPCIÓN (recuperarla con
`Cashier::stripe()->subscriptions->retrieve($session->subscription)`), o setear la metadata también
a nivel sesión, o ambas con fallback. Y corregir los tests para que reflejen el payload real.
NOTA: es PRE-EXISTENTE, no lo introdujo este refactor. Pero bloquea el lanzamiento.

## F-11 | ALTA | `ConfigurationController::changeSubscriptionInterval`
**Cambiar de intervalo no escribe el entitlement; la UI queda mostrando datos contradictorios.**
Con suscripción anual, `POST /config/subscription/change-interval {"interval":"month"}` → 200 y Stripe
queda correcto en `month 65000`, pero `GET /config/subscription` sigue devolviendo
`{interval:"year", total:741000}` y la tarjeta muestra "$7,410 /año" junto a "Próximo cobro ago 20,
2026" — se contradice a sí misma. Se corrige sólo cuando llega `customer.subscription.updated`; si el
webhook no llega (ver F-10) la divergencia es PERMANENTE.
Asimétrico: `change-billing-plan` SÍ actualiza el entitlement en la misma request.

## F-12 | MEDIA | preview de cambio de plan — `amount_due` induce a error
Devuelve el total de la PRÓXIMA factura (MX$24,509.98) cuando el impacto inmediato del cambio son
~MX$6,839.98 de prorrateo. Presentado como "monto a pagar" hace creer que se cobrarán 24 mil pesos ya.

## F-13 | MEDIA | portal de Stripe branded como OTRO negocio
El dueño hace clic en "Portal de facturación" y aterriza en un portal que dice **"Womenindex"** y
"Return to New Business". Funciona, pero rompe la confianza en pleno flujo de pago.
Es configuración de la cuenta de Stripe, NO código. Hay que arreglarlo antes de lanzar.

## F-14 | BAJA | `previewSubscriptionChange` conserva change_types muertos
Sigue validando `change_type` en `['add_addon','remove_addon','change_interval']`
(ConfigurationController.php:902). Inerte, pero contradice el contrato del refactor.

## F-15 | OBSERVACIÓN NO REPRODUCIDA | posible auto-checkout en `/select-plan?success=true`
Dos veces una pestaña parada en esa URL navegó SOLA a una sesión NUEVA de Stripe Checkout, sin clic.
No se reprodujo limpio; puede ser artefacto del navegador de automatización. Si es real es serio
(riesgo de doble cobro). Verificar contra el punto 5 de la ronda 5.

---

# RONDA 5 — adversarial, aislamiento y regresión

VERDE: aislamiento entre clústeres (X-Tenant-Id cruzado no filtra; rutas super-admin 403);
manipulación de precio IMPOSIBLE (el servidor recalcula: montos/moneda/price_id del cliente ignorados,
verificado contra el amount_total real en Stripe); idempotencia del webhook incluso borrando el
marcador de caché (1 entitlement, expires_at congelado, sin duplicar módulos/sistemas);
rate limiting 60/min con 429 en catálogo y quote; 18 payloads hostiles sin un solo 500 ni fuga;
menú y bloqueo por vencimiento correctos (403 SUBSCRIPTION_INACTIVE, con /config/subscription abierto
para poder pagar).

## F-16 | CRÍTICO | doble cobro — `BillingCheckoutController` guard `already_subscribed`
El guard decide con `$owner->subscription('default')`, que lee la tabla LOCAL `subscriptions`, poblada
SÓLO por webhooks. Encadenado con F-10, el dueño que ya pagó no tiene fila local y puede pagar otra vez.
Repro real (dinero de test cobrado dos veces):
 1. checkout basico → pago con 4242 → sub_1TvAUg... activa, factura 45000 pagada
 2. `SELECT count(*) FROM subscriptions WHERE user_id=<B>` → 0
 3. checkout idéntico → 200 con NUEVA sesión (debería ser 409)
 4. pago la segunda → sub_1TvAXB... activa, otra factura 45000
 Final: 2 suscripciones activas, MX$900 por un plan de MX$450.
AGRAVANTE INDEPENDIENTE: aun con el estado sincronizado, 5 llamadas CONCURRENTES devolvieron 5×200 —
el guard es lectura-y-actúa sin lock, no es atómico.
Fix: (a) resolver F-10 para que la fila local exista; (b) consultar Stripe (no sólo la tabla local)
antes de crear la sesión; (c) lock atómico por owner alrededor del guard.

## F-17 | ALTA | checkout acepta planes OCULTOS → descuento auto-servido
`MultiItemSubscriptionService.php:61` llama `quoteByPlan(...)` SIN `publicOnly: true`, mientras el
endpoint público sí lo pasa. Todas las demás vías filtran bien (quote 422, catálogo no lo lista,
preview de cambio de plan 422) — sólo el checkout no.
Repro: `POST /billing/checkout {"plan_key":"qa_r4_preferencial","interval":"month"}` → 200, sesión con
amount_total = 30000 mxn contra los 45000 del `basico` público de la misma banda = **-33% de ingreso**
con sólo adivinar o filtrar una clave. El swap preferencial está diseñado como exclusivo de
super-admin; esta ruta lo elude.
Fix: pasar `publicOnly: true` en el checkout (con excepción explícita para las vías de super-admin).

## F-18 | ALTA | un NO-dueño con rol `administrator` administra la facturación
El rol sembrado `administrator` trae `manage.subscription`, y con él un no-dueño logra:
 - `GET /config/subscription/portal` → 200 con URL viva del portal (cambiar tarjeta, cancelar)
 - `POST /config/subscription/change-billing-plan` → 200, cambió de verdad el plan del clúster
 - cancel/resume → 404 de negocio (la autorización NUNCA se evalúa)
Contradice de frente el gate `not_account_owner` de `/billing/checkout`.
DECISIÓN DEL DUEÑO: o se alinea checkout con `manage.subscription`, o se agrega gate de owner a
portal/cancel/resume/change-billing-plan. (El código lo documenta como intencional, pero es incoherente.)

## F-19 | MEDIA | copy de backend que sigue vendiendo módulos
Visible desde el API público: "La cotización debe incluir el módulo base" (PlanPricingService),
"El módulo base es obligatorio" (BillingCheckoutRequest), "Ningún módulo seleccionado tiene un precio
facturable" (MultiItemSubscriptionService), varios en EntitlementService/ConfigurationController.
Plantilla que ve el tenant (NotificationTemplateSeeder): "La prueba de {module} venció. Inclúyela en el
cobro del clúster".
**Descripción del producto EN STRIPE (visible en el checkout hosteado)**: "Cobranza, avisos y control de
accesos (módulo base obligatorio)" — desactualizada, no menciona paquetería ni amenidades.

## F-20 | MEDIA | superficies de módulo vivas en la UI
`ManualPaymentSection.tsx:124-163` desglosa "Módulos contratados"/"Módulos de cortesía" fila por fila
con precio propio (debería colapsar a una línea). Copy: TenantBillingModal.tsx:35, useTenantBilling.ts:60
("Módulo activado gratis"), BillingPackageList.tsx:90 ("matriz de precios por módulo").
`PlanPriceMatrix.tsx:341` mantiene la grilla editable precio × módulo × intervalo.

## F-21 | BAJA | código muerto en `checkoutSubscription/schema.ts`
Sin importador fuera de su archivo: planModulePriceSchema/IPlanModulePrice, planModuleSchema/IPlanModule,
planQuoteLineSchema/IPlanQuoteLine (las lines[] se parsean pero nunca se pintan), multiItemModuleSchema.

## F-22 | BAJA | la card Contáctanos interactiva depende 100% del API
`Pricing.astro:703-704` oculta el bloque estático apenas responde el catálogo. Si el API devuelve planes
pero ninguno con `is_contact_sales`, el tier de >300 casas DESAPARECE (el camino interactivo no
sintetiza fallback). Hoy no ocurre.

## F-23 | BAJA | 403 sin código de negocio y con stack trace
Las rutas `/config/subscription/*` y `/tenant/{id}/billing` responden `{"message":"Unauthorized action.",
"exception":...,"trace":[...]}` sin campo `code`. El front no puede distinguir el motivo.
El trace es ruido de APP_DEBUG (aceptable en dev, NO en producción).

## Nota: M-1 de ronda 5 CONFIRMA F-02 (Amenidades vendida pero no habilitada), de forma independiente.

---

# RONDA 3 — bordes, fallos y venta consultiva (10/10 VERDE + 6 hallazgos)

VERDE comprobado EN NAVEGADOR: >300 unidades → card Contáctanos sin precio ni botón Contratar;
"A medida" elegible a mano en la grilla con el mismo resultado; prueba vencida → 403
SUBSCRIPTION_INACTIVE en toda la API con el camino de pago vivo (checkout devuelve URL de Stripe
estando bloqueado) y la SPA rebotando al paywall sin errores; past_due conserva acceso;
subscription.deleted bloquea; downgrade bloqueado con el botón deshabilitado en el diálogo;
unidad 26 sobre plan de 25 → 422 UNIT_LIMIT_EXCEEDED; importación masiva frenada en validate Y en
execute (defensa en profundidad, 0 unidades creadas); plan oculto/inactivo → 422 sin filtrar que existe;
y `a_medida` SIN tope real: se crearon 401 unidades sin que el guard frenara.
planForUnits confirmado: 300→premium, 301→a_medida, 1000→a_medida (ya no hace clamp).

## F-24 | MEDIA | `PlanPricingService::buildPlanCatalog()` (~:386-410)
**El catálogo público publica planes RETIRADOS que después no se pueden cotizar.**
Filtra por `is_public` pero sólo consulta `is_active` cuando el plan NO tiene precios
(`if ($isContactSales && ! $plan->is_active) continue;`). Un plan con `is_public=true`,
`is_active=false` y precios activos SALE en `/billing/plan-catalog` con precio completo, mientras
`plan-quote` lo rechaza con 422 "Plan inválido".
Impacto: la única forma de retirar un plan (bajar `is_active`) lo deja visible y muerto en la landing
y en /select-plan, y el catálogo se cachea 300s.

## F-25 | MEDIA | `ConfigurationController::show()` (:509-522, :591-597)
**Un clúster que paga fuera de Stripe ve "Sin plan activo".**
`current_plan` está hardcodeado a `null` (:514) y todo se delega en `presentMultiItemSubscription()`,
que arranca con `activeStripeEntitlements()` y devuelve null si no hay filas de Stripe.
Repro: tenant con entitlement `source=manual` activo sobre Esencial → `GET /config/subscription`
responde `{"current_plan":null,"multi_item":null,"is_multi_item":false}` y la pantalla muestra
"Sin plan activo. Aún no tienes un plan asignado."
Impacto: TODO cliente de pago manual o cortesía queda invitado a contratar de nuevo por tarjeta.

## F-26 | MEDIA | `SubscriptionStateService` (:100-120)
**La morosidad de Stripe es invisible para el dueño.** Decide sólo por entitlement activo y nunca lee
`tenant.status`, así que durante `past_due` `/auth/me` responde `{"status":"active"}` sin discriminador.
El dato existe en `/config/subscription` pero no viaja en la sesión → no hay forma barata de pintar
"tu pago falló, actualiza tu tarjeta" ANTES del bloqueo duro. Brecha de producto, no bug.

## F-27 | BAJA/MEDIA | estado huérfano si un entitlement de Stripe vence sin webhook `deleted`
La API bloquea bien (el estado se calcula en vivo), pero `tenant.status` se queda en `activo` y
`module_tenant.enabled` en 11 indefinidamente: el cron excluye Stripe a propósito y nada más recalcula.
No es fuga de acceso, es higiene: cualquier lectura de `tenant.status` (paneles, reportes) miente.
Aplica si Stripe termina el dunning en `unpaid` sin cancelar, o si se pierde el webhook.

## F-28 | BAJA | "A medida" en el selector de CAMBIO de plan del dueño
El combo lo lista; al elegirlo el preview da 422 "El plan seleccionado no está disponible." con un
botón "Reintentar" que nunca va a funcionar. Debe mostrar la card de contacto o no listarse.

## F-29 | BAJA | copy del guard en la importación ejecutada
Al saltarse el preview, cada fila falla con "Error inesperado al procesar fila: Tu plan Básico permite
hasta 25 unidades...". Es un bloqueo de negocio esperado presentado como error inesperado; hay que
quitar el prefijo para `UnitLimitExceededException`.

## Aviso operativo
El navegador de Playwright es COMPARTIDO entre agentes de QA en paralelo: las pestañas se pisan.
Para QA de UI en paralelo hay que abrir un browser context aislado.
