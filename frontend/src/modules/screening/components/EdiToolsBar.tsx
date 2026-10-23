import { Button } from '@/components/ui/button'

interface EdiTool {
  label: string
  file: string
}

/**
 * The legacy "herramientas" of the Prueba EDI (S3): the four láminas the child is
 * shown during the test and the application manual. Static assets served from
 * `public/edi/`, each opened in a new tab so the capture in progress is kept.
 */
const EDI_TOOLS: readonly EdiTool[] = [
  { label: 'Lámina A', file: 'lamina-a.jpeg' },
  { label: 'Lámina B', file: 'lamina-b.jpeg' },
  { label: 'Lámina C', file: 'lamina-c.jpeg' },
  { label: 'Lámina D', file: 'lamina-d.jpeg' },
  { label: 'Manual', file: 'manual-edi-2a-edicion.pdf' },
]

export function EdiToolsBar() {
  return (
    <nav aria-label="Herramientas de la Prueba EDI" className="flex flex-wrap gap-2">
      {EDI_TOOLS.map((tool) => (
        <Button key={tool.file} asChild variant="outline" size="sm">
          <a href={`/edi/${tool.file}`} target="_blank" rel="noopener noreferrer">
            {tool.label}
          </a>
        </Button>
      ))}
    </nav>
  )
}
