/**
 * Catálogo de tools WebMCP que EDI expone en desarrollo.
 *
 * Empezamos con tools READ-ONLY y seguras. Las tools que muten datos del organización
 * se agregan después, con gating por rol, y SIEMPRE corren en el contexto de
 * sesión autenticada de la página (heredan Sanctum + tenant scope + permisos).
 */

import { sessionStore } from '@/shared/auth/sessionStore'
import { APP_CONFIG } from '@/shared/config/appConfig'
import type { WebMcpToolDescriptor, WebMcpToolResult } from '@/dev/webmcp/types'

/** Serializa cualquier payload al shape de contenido que espera el API nativo. */
function textResult(payload: unknown): WebMcpToolResult {
  return { content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }] }
}

const EMPTY_INPUT = { type: 'object', properties: {}, additionalProperties: false } as const

/** Devuelve el usuario, roles, tenant y estado de sesión actuales (read-only). */
const whoamiTool: WebMcpToolDescriptor = {
  name: 'whoami',
  description:
    'Devuelve el usuario, roles, tenant y estado de suscripción de la sesión actual en EDI. Solo lectura.',
  inputSchema: EMPTY_INPUT,
  async execute() {
    const { profile, isAuthenticated, token } = sessionStore.getState()

    if (!profile) {
      return textResult({ authenticated: false, message: 'No hay sesión iniciada en EDI.' })
    }

    return textResult({
      authenticated: isAuthenticated,
      hasToken: Boolean(token),
      user: {
        id: profile.user.id,
        name: profile.user.name ?? null,
        email: profile.user.email,
      },
      roles: profile.roles,
      abilitiesCount: profile.abilities.length,
      tenant: profile.tenant ? { id: profile.tenant.id, name: profile.tenant.name } : null,
      subscription: profile.subscription?.status ?? null,
    })
  },
}

/** Tools de datos (read-only por ahora). Las muta-datos se agregan acá luego. */
export const dataTools: WebMcpToolDescriptor[] = [whoamiTool]

/**
 * Meta-tool de descubrimiento: le permite a un agente preguntarle a la página
 * "¿qué puedo hacer acá?" y recibir el catálogo con descripción + schema.
 * Recibe el array completo (incluyéndose a sí misma) por referencia.
 */
export function buildListCapabilitiesTool(
  allTools: WebMcpToolDescriptor[],
): WebMcpToolDescriptor {
  return {
    name: 'list_capabilities',
    description:
      'Lista las tools WebMCP que EDI expone, con nombre, descripción y schema de entrada. Úsala para descubrir qué acciones están disponibles.',
    inputSchema: EMPTY_INPUT,
    async execute() {
      return textResult({
        brand: APP_CONFIG.brandName,
        version: APP_CONFIG.appVersion,
        toolCount: allTools.length,
        tools: allTools.map((tool) => ({
          name: tool.name,
          description: tool.description,
          inputSchema: tool.inputSchema,
        })),
      })
    },
  }
}
