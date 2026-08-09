# Configuración de Tracking de Google — GA4 (gtag + Measurement Protocol) → Google Ads

Esta guía es el paso a paso para que **tú** conectes tus cuentas de Google. El código ya está programado; aquí solo obtienes los IDs/secretos y los pones en las variables de entorno, y vinculas GA4 con Google Ads para que puedas **optimizar y segmentar tus campañas**. Tiempo estimado: **15-20 minutos**.

> **Cómo funciona (resumen):** los eventos del navegador se mandan con **gtag** a **Google Analytics 4 (GA4)**. Los eventos que ocurren solo en el servidor (la **compra confirmada** por el webhook de Stripe, y el registro como respaldo) se mandan con el **Measurement Protocol** (el "API de conversiones" de Google). Luego **vinculas GA4 con Google Ads** y marcas los eventos como conversiones: así Google Ads los usa para optimizar campañas.
>
> **Nota:** NO usamos la Google Ads API directa (requiere OAuth + developer token con aprobación de Google). El camino GA4 → Ads te da la optimización de campañas sin ese trámite. Si en el futuro quieres la Ads API directa, está documentado como "fuera de alcance" en el plan.

---

## Lo que necesitas obtener

| Dato | Dónde se usa | Variable de entorno |
|---|---|---|
| **Measurement ID** (`G-XXXXXXX`) | Navegador (landing + app) | `PUBLIC_GA_ID` (landing), `VITE_GA_ID` (app) |
| **Measurement ID** (`G-XXXXXXX`) | Servidor (backend) | `GA_MEASUREMENT_ID` |
| **API Secret del Measurement Protocol** | Servidor (backend) | `GA_API_SECRET` |

> El **Measurement ID es público**. El **API Secret SÍ es secreto** — solo va en el backend.

---

## Paso 1 — Obtener el Measurement ID de GA4

1. Entra a **[Google Analytics](https://analytics.google.com/)**.
2. Si no tienes una propiedad GA4: **Administrar (Admin)** ⚙️ → **Crear propiedad** → sigue el asistente (tipo de negocio, zona horaria México, moneda MXN).
3. Dentro de la propiedad: **Admin → Flujos de datos (Data Streams) → Web** → selecciona (o crea) tu flujo web con la URL de tu sitio.
4. Copia el **ID de medición (Measurement ID)**, con formato `G-XXXXXXX`.

> El proyecto ya trae un ID por defecto (`G-X9EFEZRG28`). **Si ese es tuyo, ya está.** Si no, reemplázalo en las variables de arriba.

---

## Paso 2 — Crear el API Secret del Measurement Protocol

1. En **Admin → Flujos de datos → (tu flujo web)**.
2. Baja a **Measurement Protocol API secrets** (Secretos de API del Measurement Protocol).
3. Clic en **Crear** → ponle un nombre (ej. `backend-server`) → **Crear**.
4. Copia el **valor del secreto**. Ese va en el backend como `GA_API_SECRET`.

---

## Paso 3 — Poner las variables de entorno

**Backend** (`.env` de producción / Laravel Cloud):
```env
GA_MEASUREMENT_ID=G-XXXXXXX
GA_API_SECRET=tu_api_secret_secreto

# Activa los canales de envío server-side:
TRACKING_CHANNELS=facebook,google_analytics
```

**Landing** (`.env` de producción del sitio Astro):
```env
PUBLIC_GA_ID=G-XXXXXXX
```

**App React** (`.env` de producción del frontend):
```env
VITE_GA_ID=G-XXXXXXX
```

> **CORS:** igual que con Facebook, agrega el origen del landing a `CORS_ALLOWED_ORIGINS` del backend en producción:
> ```env
> CORS_ALLOWED_ORIGINS=https://kivo7.com,https://www.kivo7.com,https://app.kivo7.com
> ```

---

## Paso 4 — Vincular GA4 con Google Ads (esto es lo que "manipula campañas")

1. En GA4: **Admin → Vínculos de productos → Vínculos de Google Ads (Google Ads Links)** → **Vincular** → elige tu cuenta de Google Ads → confirma.
2. En GA4: **Admin → Eventos** (Events) — verás llegar los eventos (`purchase`, `sign_up`, `generate_lead`, etc.). Marca los que te importen como **conversión** (toggle "Marcar como conversión" / "Key event").
3. En **Google Ads**: **Objetivos → Conversiones → + Nueva conversión → Importar → Google Analytics 4** → selecciona los eventos que marcaste. Ahora Google Ads los usa para **optimizar y segmentar campañas** (audiencias, pujas automáticas por conversión, etc.).

> El "matching" mejora si envías `gclid` (el id de clic de Google Ads). El sistema ya lo **captura de la URL** cuando un usuario llega desde un anuncio y lo reenvía a la app y al servidor.

---

## Paso 5 — Verificar que llegan los eventos

**Navegador (gtag):**
1. En GA4: **Admin → DebugView**.
2. Navega tu sitio en producción (acepta el banner de cookies). Verás los eventos en tiempo real en DebugView.
   - *Tip:* para forzar el modo debug, instala la extensión **Google Analytics Debugger** o agrega `?debug_mode=1`.

**Servidor (Measurement Protocol):**
1. La compra confirmada (webhook de Stripe) manda un `purchase` server-side a GA4. Aparece en **DebugView** (si el evento incluye `debug_mode`) y, con unas horas de retraso, en los **informes** de GA4.
2. Si un evento no llega, revisa los **logs del backend**: el canal de GA4 registra un `Log::warning` cuando faltan `GA_MEASUREMENT_ID`/`GA_API_SECRET`, y la respuesta HTTP de Google queda en el registro de auditoría (`tracking_event`). Para validar el formato del payload puedes probarlo tú mismo contra el endpoint de validación de Google: `POST https://www.google-analytics.com/debug/mp/collect?measurement_id=...&api_secret=...` (te devuelve los errores de validación).

---

## Eventos que ya dispara el sistema

| Evento (GA4) | Equivalente Meta | Cuándo | Origen | Parámetros |
|---|---|---|---|---|
| `page_view` | `PageView` | Cada carga del landing | Navegador | — |
| `generate_lead` | `Lead` | Clic en CTA "Empezar gratis" | Navegador | — |
| `start_trial` | `StartTrial` | Inicio de registro / trial | Navegador (+ servidor de respaldo) | — |
| `sign_up` | `CompleteRegistration` | Registro completado en la app | Navegador (+ servidor de respaldo) | — |
| `begin_checkout` | `InitiateCheckout` | Ir al checkout de Stripe | Navegador | `value`, `currency` |
| `purchase` | `Purchase` | Pago confirmado (webhook Stripe) | **Servidor** (Measurement Protocol) | `value`, `currency`, `transaction_id` |

> **Evitar doble conteo:** GA4 no deduplica el Measurement Protocol contra gtag. Por eso los eventos del **navegador** se mandan **solo con gtag**, y el `purchase` (que se confirma en el servidor) se manda **solo por Measurement Protocol**. Ya está resuelto en el código; no lo cambies sin entender esta regla.

---

## Disparar tus propios eventos (opcional)

- **Landing:** `trackEvent('MiEvento', { value, currency: 'MXN' })` desde `src/lib/trackEvent.ts` (mapea a un nombre GA4).
- **App React:** `trackEvent('MiEvento', { ... })` desde `src/shared/analytics/trackEvent.ts`.
- **Servidor:** inyecta `TrackingDispatcher` y despacha un `TrackingEvent` con los canales que quieras. Ver `Modules/Tracking`.

---

## Problemas comunes

| Síntoma | Causa probable |
|---|---|
| No aparece nada en DebugView | Banner de cookies no aceptado, o estás en dev (gtag solo carga en producción) |
| `purchase` no llega server-side | Falta `GA_API_SECRET` o `GA_MEASUREMENT_ID` en el backend, o `TRACKING_CHANNELS` no incluye `google_analytics` |
| Los eventos no se marcan como conversión en Ads | Falta marcarlos como conversión en GA4 y/o importarlos en Google Ads (Paso 4) |
| Conversiones infladas (doble conteo) | Se está mandando el mismo evento por gtag Y por Measurement Protocol — respeta la regla del recuadro de arriba |
| No se atribuye a la campaña de Ads | No se está capturando/reenviando el `gclid`, o GA4 no está vinculado a Ads |
