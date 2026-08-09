# Configuración de Tracking de Facebook (Meta) — Pixel + Conversions API

Esta guía es el paso a paso para que **tú** conectes tus cuentas de Meta. El código ya está programado; aquí solo obtienes los IDs/tokens y los pones en las variables de entorno. Tiempo estimado: **10-15 minutos**.

> **Cómo funciona (resumen):** cada evento se manda DOS veces — una desde el navegador (**Pixel**, `fbq`) y otra desde el servidor (**Conversions API / CAPI**). Ambas llevan el mismo `event_id`, así que Meta las **deduplica** y cuenta una sola conversión. El servidor es el que optimiza tus campañas de forma confiable (no lo bloquean adblockers ni Safari/ITP).

---

## Lo que necesitas obtener

| Dato | Dónde se usa | Variable de entorno |
|---|---|---|
| **Pixel ID** | Navegador (landing + app) y servidor | `PUBLIC_META_PIXEL_ID` (landing), `VITE_META_PIXEL_ID` (app), `FACEBOOK_PIXEL_ID` (backend) |
| **Access Token de CAPI** | Servidor (backend) | `FACEBOOK_CAPI_ACCESS_TOKEN` |
| **Test Event Code** (opcional, solo para probar) | Servidor (backend) | `FACEBOOK_TEST_EVENT_CODE` |

> El **Pixel ID es público** (aparece en el navegador, no es secreto). El **Access Token SÍ es secreto** — solo va en el backend, nunca en el frontend.

---

## Paso 1 — Obtener el Pixel ID

1. Entra a **[Meta Events Manager](https://business.facebook.com/events_manager2)**.
2. Si aún no tienes un Pixel (Meta lo llama ahora **"Conjunto de datos" / Dataset**), crea uno: botón **Conectar orígenes de datos → Web → Conectar**.
3. En la lista de la izquierda, selecciona tu Pixel/Dataset. El **ID** (número de ~15 dígitos) aparece debajo del nombre.

> El proyecto ya trae un Pixel ID por defecto en el código (`1012668624876358`). **Si ese es tuyo, ya está.** Si no, reemplázalo con el tuyo en las tres variables de arriba.

---

## Paso 2 — Generar el Access Token de la Conversions API

1. En **Events Manager**, con tu Dataset seleccionado, ve a la pestaña **Configuración** (Settings).
2. Baja hasta la sección **Conversions API** → **Generar token de acceso** (Generate access token).
3. Copia el token (es una cadena larga). **Guárdalo bien: solo se muestra una vez.**
4. Ese valor va en el backend como `FACEBOOK_CAPI_ACCESS_TOKEN`.

> Si no ves "Generar token", pide a tu admin de Business Manager el permiso **"Manage Business"** sobre ese Dataset, o genera el token con un *System User* en Business Settings → Users → System Users (recomendado para producción, porque no se invalida si sales del equipo).

---

## Paso 3 — Poner las variables de entorno

**Backend** (`.env` de producción / Laravel Cloud):
```env
FACEBOOK_PIXEL_ID=1012668624876358
FACEBOOK_CAPI_ACCESS_TOKEN=EAAG...tu_token_secreto
# Solo mientras pruebas (ver Paso 5); quítalo en producción real:
FACEBOOK_TEST_EVENT_CODE=

# Activa los canales de envío server-side:
TRACKING_CHANNELS=facebook,google_analytics
```

**Landing** (`.env` de producción del sitio Astro):
```env
PUBLIC_META_PIXEL_ID=1012668624876358
```

**App React** (`.env` de producción del frontend):
```env
VITE_META_PIXEL_ID=1012668624876358
```

> **CORS:** para que el landing (otro dominio) pueda mandar eventos al backend, agrega su origen a `CORS_ALLOWED_ORIGINS` en el `.env` del backend en producción, por ejemplo:
> ```env
> CORS_ALLOWED_ORIGINS=https://kivo7.com,https://www.kivo7.com,https://app.kivo7.com
> ```

---

## Paso 4 — Consentimiento de cookies (importante)

El Pixel del navegador **NO dispara** hasta que el visitante acepta cookies en el banner (Consent Mode). Esto ya está implementado. En **pruebas**, acepta el banner ("Aceptar") para que los eventos del navegador se manden. La Conversions API del servidor se manda igual, pero para conversiones con datos personales conviene respetar el consentimiento — ya está considerado.

---

## Paso 5 — Verificar que llegan los eventos (Test Events)

1. En **Events Manager** → tu Dataset → pestaña **Probar eventos** (Test Events).
2. Copia el **código de prueba** que aparece ahí (algo como `TEST12345`) y ponlo temporalmente en el backend: `FACEBOOK_TEST_EVENT_CODE=TEST12345`. Redespliega.
3. Navega tu sitio: acepta cookies, haz clic en un CTA, regístrate, simula una compra.
4. En la pantalla **Probar eventos** verás llegar los eventos en tiempo real, marcados como **"Browser"** (Pixel) y **"Server"** (CAPI). Si el `event_id` coincide, verás la etiqueta de **deduplicación** — eso confirma que todo está bien cableado.
5. Cuando termines de probar, **borra** `FACEBOOK_TEST_EVENT_CODE` (déjalo vacío) para producción real.

---

## Eventos que ya dispara el sistema

| Evento (Meta) | Cuándo se dispara | Origen | Parámetros |
|---|---|---|---|
| `PageView` | Cada carga de página del landing | Navegador | — |
| `Lead` | Clic en un CTA "Empezar gratis" del landing | Navegador + Servidor | — |
| `StartTrial` | Al iniciar el registro / otorgar el trial de 14 días | Navegador + Servidor | — |
| `CompleteRegistration` | Al completar el registro en la app | Navegador + Servidor | `email`, `external_id` (id de usuario) |
| `InitiateCheckout` | Al ir al checkout de Stripe | Navegador | `value`, `currency` |
| `Purchase` | Al confirmarse el pago (webhook de Stripe) | Navegador + Servidor | `value`, `currency`, `transaction_id` |

> **Datos personales:** el email/teléfono se envían **hasheados con SHA-256** desde el servidor (nunca en texto plano). Esto mejora el *matching* de Meta sin exponer datos.

---

## Disparar tus propios eventos (opcional)

El motor es genérico. Para un evento nuevo desde el navegador:
- **Landing:** importa `trackEvent` de `src/lib/trackEvent.ts` y llama `trackEvent('MiEvento', { value, currency: 'MXN' })`.
- **App React:** importa `trackEvent` de `src/shared/analytics/trackEvent.ts` y llama igual.

Desde el servidor (Laravel), inyecta `TrackingDispatcher` y despacha un `TrackingEvent`. Ver `Modules/Tracking`.

---

## Problemas comunes

| Síntoma | Causa probable |
|---|---|
| No llega NADA en Test Events | El banner de cookies no fue aceptado, o estás en `localhost`/dev (el Pixel solo carga en producción) |
| Llega "Browser" pero no "Server" | Falta `FACEBOOK_CAPI_ACCESS_TOKEN` en el backend, o `TRACKING_CHANNELS` no incluye `facebook` |
| Llega "Server" pero no "Browser" | El visitante rechazó cookies, o un adblocker bloquea `fbevents.js` (¡justo por eso existe la CAPI!) |
| El POST del landing falla (CORS) | Falta el dominio del landing en `CORS_ALLOWED_ORIGINS` del backend |
| Eventos duplicados en los reportes | El `event_id` no coincide entre navegador y servidor — revisa que el mismo id viaje en ambos |
