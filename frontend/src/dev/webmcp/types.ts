/**
 * Tipos del API nativo WebMCP de Chrome (`navigator.modelContext`).
 *
 * El contrato fue verificado contra Chrome 149 con el flag
 * `chrome://flags/#enable-webmcp-testing` habilitado:
 *  - `registerTool(descriptor)` → registra una tool (devuelve void).
 *  - `getTools()` → **Promise**<RegisteredTool[]> (es asíncrono).
 *  - `executeTool(registeredTool, JSON.stringify(args))` → el 1er arg es un
 *    RegisteredTool (no el nombre) y el 2do son los args como **JSON string**.
 *  - El handler `execute(args)` recibe los args YA parseados y debe devolver
 *    `{ content: [{ type: 'text', text }] }` (shape de contenido MCP).
 *
 * Es infraestructura SOLO de desarrollo: este módulo nunca entra al bundle de
 * producción (ver el guard `import.meta.env.DEV` en `main.tsx`).
 */

export interface WebMcpToolResult {
  content: Array<{ type: 'text'; text: string }>
}

export interface WebMcpToolDescriptor {
  name: string
  description: string
  inputSchema: Record<string, unknown>
  execute: (args: Record<string, unknown>) => Promise<WebMcpToolResult>
}

/** Handle que devuelve `getTools()`; se pasa tal cual a `executeTool`. */
export interface RegisteredTool {
  name: string
  description?: string
  inputSchema?: Record<string, unknown>
}

export interface ModelContext {
  registerTool: (descriptor: WebMcpToolDescriptor) => unknown
  getTools: () => Promise<RegisteredTool[]>
  executeTool: (tool: RegisteredTool, args: string) => Promise<WebMcpToolResult>
  ontoolchange?: ((this: ModelContext, ev: Event) => unknown) | null
}

declare global {
  interface Navigator {
    /** Presente solo con el flag nativo de Chrome 149 o el polyfill @mcp-b/global. */
    modelContext?: ModelContext
  }

  interface Window {
    /** Marcador de auto-anuncio para que un agente detecte el provider de un vistazo. */
    __KIVO_WEBMCP__?: {
      enabled: boolean
      brand: string
      version: string
      registeredAt: string
      tools: string[]
    }
  }
}
