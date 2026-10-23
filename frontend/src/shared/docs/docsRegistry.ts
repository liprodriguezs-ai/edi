/**
 * Registro ÚNICO de enlaces a la documentación pública (edi.example.com/docs).
 *
 * Por qué existe este archivo en vez de poner el enlace en cada pantalla:
 * un enlace suelto por pantalla se desactualiza en silencio cuando la
 * documentación se reorganiza, y nadie se entera hasta que un usuario cae en un
 * 404. Con un solo mapa, el script `scripts/check-docs-links.mjs` puede
 * verificar que TODA entrada apunte a una página que existe de verdad.
 *
 * Si renombras o mueves una página del manual, corrige la entrada de aquí y
 * corre el script. El hook `Stop` del repo lo ejecuta automáticamente.
 *
 * La clave es el PRIMER segmento de la ruta, igual que `SECTION_LABELS` en
 * `components/site-header.tsx`. Una ruta sin entrada cae a la portada del
 * manual — es el caso de las pantallas de administración de la plataforma
 * (tenants, planes, correos), que a propósito NO se documentan en público.
 */

/** Origen del manual. Configurable por entorno para poder apuntar a un preview. */
const DOCS_ORIGIN: string = import.meta.env.VITE_DOCS_BASE_URL ?? 'https://edi.example.com'

/** Portada del manual — destino de respaldo. */
export const DOCS_HOME = '/docs'

/**
 * Segmento de ruta → página del manual.
 * Varias rutas pueden compartir destino (los catálogos de un módulo apuntan a
 * la página que los explica).
 */
export const DOCS_BY_SECTION: Record<string, string> = {
  // Agenda
  agenda: '/docs/agenda/citas',
  // Expediente del niño y Prueba EDI
  child: '/docs/expediente/nino',
  screening: '/docs/expediente/prueba-edi',
}

/** Primer segmento de una ruta. `/charge-batch/123` → `charge-batch`. */
export function sectionFromPath(pathname: string): string {
  return pathname.split('/').filter(Boolean)[0] ?? 'dashboard'
}

/**
 * URL absoluta del manual para la ruta actual.
 * Siempre devuelve algo: sin entrada, la portada.
 */
export function docsUrlForPath(pathname: string): string {
  const path = DOCS_BY_SECTION[sectionFromPath(pathname)] ?? DOCS_HOME
  return `${DOCS_ORIGIN}${path}`
}

/** `true` si la ruta tiene una página propia (no cae al respaldo). */
export function hasDedicatedDocs(pathname: string): boolean {
  return sectionFromPath(pathname) in DOCS_BY_SECTION
}
