/* eslint-disable no-console -- módulo de diagnóstico dev: el anuncio por consola es intencional */
/**
 * Provider WebMCP dev-only de EDI.
 *
 * Registra el catálogo de tools en `navigator.modelContext` (API nativa de
 * Chrome 149 con el flag `enable-webmcp-testing`, o el polyfill @mcp-b/global),
 * deja un marcador global de auto-anuncio y lo loguea en consola.
 *
 * Se invoca SOLO en desarrollo desde `main.tsx` vía `import()` dinámico detrás
 * de `import.meta.env.DEV`, por lo que jamás entra al bundle de producción.
 */

import { APP_CONFIG } from '@/shared/config/appConfig'
import { buildListCapabilitiesTool, dataTools } from '@/dev/webmcp/tools'
import type { WebMcpToolDescriptor } from '@/dev/webmcp/types'

export async function registerWebMcpProvider(): Promise<void> {
  const mc = navigator.modelContext

  if (!mc || typeof mc.registerTool !== 'function') {
    console.warn(
      '[WebMCP] navigator.modelContext no está disponible. ' +
        'Habilitá chrome://flags/#enable-webmcp-testing (Chrome 149+) o el polyfill. Provider NO registrado.',
    )
    return
  }

  // El array se pasa por referencia a list_capabilities para que se incluya a sí misma.
  const allTools: WebMcpToolDescriptor[] = [...dataTools]
  allTools.push(buildListCapabilitiesTool(allTools))

  for (const tool of allTools) {
    mc.registerTool(tool)
  }

  const toolNames = allTools.map((tool) => tool.name)

  window.__KIVO_WEBMCP__ = {
    enabled: true,
    brand: APP_CONFIG.brandName,
    version: APP_CONFIG.appVersion,
    registeredAt: new Date().toISOString(),
    tools: toolNames,
  }

  console.warn(
    `%c[WebMCP] ${APP_CONFIG.brandName} dev provider activo — ${allTools.length} tools: ${toolNames.join(', ')}`,
    'color:#16a34a;font-weight:bold',
  )
}
