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
  // Primeros pasos
  dashboard: '/docs/empezar/como-se-organiza',
  onboarding: '/docs/empezar/configuracion-inicial',

  // Estructura del organización
  unit: '/docs/estructura/unidades',
  'my-unit': '/docs/estructura/residentes',
  'unit-user': '/docs/estructura/residentes',
  'unit-relationship': '/docs/estructura/residentes',
  tower: '/docs/estructura/torres-y-secciones',
  section: '/docs/estructura/torres-y-secciones',
  'unit-type': '/docs/estructura/tipos-y-estados-de-unidad',
  'unit-status': '/docs/estructura/tipos-y-estados-de-unidad',
  'unit-feature': '/docs/estructura/caracteristicas-de-unidad',
  vehicle: '/docs/estructura/vehiculos',
  'user-profile': '/docs/estructura/perfil-de-residente',

  // Cobranza
  'charge-concept': '/docs/cobranza/conceptos-de-cobro',
  'charge-concept-category': '/docs/cobranza/conceptos-de-cobro',
  'charge-policy': '/docs/cobranza/programacion-de-cobros',
  'charge-batch': '/docs/cobranza/cobros-programados',
  charge: '/docs/cobranza/cargos',
  payment: '/docs/cobranza/pagos',
  statement: '/docs/cobranza/estado-de-cuenta',
  delinquency: '/docs/cobranza/morosidad',
  import: '/docs/cobranza/importacion-de-pagos',

  // Tesorería
  'bank-account': '/docs/tesoreria/cuentas-bancarias',
  'bank-account-purpose': '/docs/tesoreria/cuentas-bancarias',
  expense: '/docs/tesoreria/egresos',
  'expense-category': '/docs/tesoreria/categorias-de-egreso',
  provider: '/docs/tesoreria/proveedores',
  'provider-type': '/docs/tesoreria/proveedores',
  'provider-service-type': '/docs/tesoreria/proveedores',

  // Mesa directiva y personal
  'board-period': '/docs/mesa-y-personal/periodos-de-mesa',
  'board-member': '/docs/mesa-y-personal/cargos-de-mesa',
  collaborator: '/docs/mesa-y-personal/colaboradores',
  'employee-position': '/docs/mesa-y-personal/colaboradores',
  'pay-frequency': '/docs/mesa-y-personal/colaboradores',
  'employee-shift': '/docs/mesa-y-personal/colaboradores',

  // Comunicación
  announcement: '/docs/comunicacion/avisos',
  'announcement-type': '/docs/comunicacion/avisos',
  'chat-channel': '/docs/comunicacion/chat-comunitario',
  notification: '/docs/comunicacion/preferencias-de-notificacion',
  'notification-preferences': '/docs/comunicacion/preferencias-de-notificacion',

  // Control de accesos
  access: '/docs/accesos/caseta',
  visitor: '/docs/accesos/qr-de-visita',
  'visitor-motive': '/docs/accesos/qr-de-visita',
  'access-log': '/docs/accesos/bitacora-de-accesos',
  blacklist: '/docs/accesos/lista-negra',
  'my-qr': '/docs/accesos/mi-qr-y-mi-bitacora',
  'my-access-log': '/docs/accesos/mi-qr-y-mi-bitacora',

  // Paquetería
  package: '/docs/paqueteria/registrar-paquetes',
  'package-carrier': '/docs/paqueteria/registrar-paquetes',
  'package-type': '/docs/paqueteria/registrar-paquetes',
  'my-packages': '/docs/paqueteria/mis-paquetes',

  // Amenidades
  amenity: '/docs/amenidades/dar-de-alta-una-amenidad',
  amenities: '/docs/amenidades/reservar-como-residente',
  'my-reservations': '/docs/amenidades/reservar-como-residente',
  'amenity-reservation': '/docs/amenidades/administrar-reservaciones',
  'amenity-occupancy': '/docs/amenidades/calendarios',

  // Proyectos comunitarios
  'community-project': '/docs/proyectos/crear-un-proyecto',
  'community-project-category': '/docs/proyectos/crear-un-proyecto',
  'project-contribution': '/docs/proyectos/aportaciones',

  // Incidencias
  complaint: '/docs/incidencias/registrar-una-incidencia',
  'complaint-category': '/docs/incidencias/categorias-y-prioridades',
  'complaint-priority': '/docs/incidencias/categorias-y-prioridades',

  // Reportes y tableros
  reports: '/docs/reportes/catalogo-de-reportes',
  'scheduled-reports': '/docs/reportes/reportes-programados',
  tableros: '/docs/reportes/tableros',
  audit: '/docs/reportes/catalogo-de-reportes',

  // Configuración y cuenta
  configuration: '/docs/configuracion/general',
  company: '/docs/configuracion/general',
  'organization-documents': '/docs/cuenta/documentos-del-organización',
  subscription: '/docs/cuenta/cambiar-de-plan',
  'select-plan': '/docs/cuenta/planes-y-precios',
  'billing-package': '/docs/cuenta/planes-y-precios',

  // Roles
  role: '/docs/roles/como-funcionan',
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
