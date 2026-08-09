/**
 * Base URL for email static assets (logo, banner).
 * - Dev preview: '' so React Email dev server serves /static/*
 * - Blade export (BLADE_OUTPUT=true): Blade variable so Laravel injects URL at send time
 * - Vercel/preview: full origin so images load in preview
 */
export function getEmailAssetsBaseUrl(): string {
  if (process.env.BLADE_OUTPUT === 'true') {
    return '{{ $emailBaseUrl }}'
  }
  if (process.env.VERCEL_URL) {
    return `https://${process.env.VERCEL_URL}`
  }
  return ''
}
