---
name: terminology-condominio-not-cluster
description: Copy de emails dice "condominio", nunca "clúster" — vocabulario unificado de producto (2026-08-08)
metadata:
  type: project
---

En el texto que lee el destinatario de un correo se dice **"condominio"**, nunca
"clúster" (plural "condominios"). Ambos son masculinos, así que artículos y
adjetivos no cambian al reemplazar. Español de México (tú, no voseo).

**Why:** unificación de vocabulario de cara al usuario en todo el producto
(landing, manual `/docs`, UI del frontend y mensajes del backend se hicieron
antes; los emails se alinearon el 2026-08-08). "Clúster" es jerga interna del
dominio/tenancy, no lenguaje de residente.

**How to apply:** al escribir o editar cualquier `.tsx` de `frontend/emails/`,
usá "condominio" en copy, en `previewText` y en las notas de pie. NO toques
identificadores, nombres de componente, props no-texto ni placeholders Blade —
`clusterName` sigue llamándose `clusterName` (ver `resident-invitation.tsx`,
cuyo fallback ya era "tu condominio"). Chequeo rápido antes de compilar:
`grep -rn "clúster" emails --include="*.tsx"` debe dar cero.

Barrido del 2026-08-08 (10 ocurrencias en 9 archivos, todas en copy de pie de
página salvo el `previewText` de `amenity-reservation-created`):
`payment-accepted`, `payment-rejected`, `payment-due-reminder`, `payment-receipt`,
`payment-registered-direct`, `amenity-reservation-created` (×2),
`amenity-reservation-payment-confirmed`, `amenity-reservation-payment-rejected`,
`amenity-reservation-surcharge-added`. `announcement-published` ya decía
"condominio" desde antes.

Ojo con el wrap del JSX: varias de esas frases están partidas en dos líneas y
"condominio" es 3 caracteres más largo que "clúster" — releé la frase completa
y respetá `printWidth: 100` de `.prettierrc`. Nota: `payment-receipt.tsx` NO
está formateado con Prettier desde antes (usa comillas dobles en JSX); no lo
reformatees "de paso", infla el diff.

Ver [[pipeline-copy-emails-scoping]] para acotar el diff de blades después de
`pnpm copy-emails`.
