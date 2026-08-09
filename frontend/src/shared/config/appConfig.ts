const rawPageSize = import.meta.env.VITE_PAGE_SIZE_DEFAULT ?? '20'
const resolvedPageSize = Number(rawPageSize)

const defaultApiBaseUrl =
  import.meta.env.DEV ? '/api' : (import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8080/api')

export const APP_CONFIG = {
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL ?? defaultApiBaseUrl,
  pageSize: Number.isFinite(resolvedPageSize) && resolvedPageSize > 0 ? resolvedPageSize : 20,
  recaptchaSiteKey: (import.meta.env.VITE_RECAPTCHA_SITE_KEY ?? '') as string,
  /** Nombre de marca del producto. Fuente única — cambialo acá (o vía VITE_BRAND_NAME). */
  brandName: (import.meta.env.VITE_BRAND_NAME ?? 'EDI') as string,
  /** Versión visible de la app (se muestra en el pie del sidebar). */
  appVersion: (import.meta.env.VITE_APP_VERSION ?? '1.0.0') as string,
} as const
