import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { useNavSections } from '@/shared/hooks/useNavSections'
import { Search } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

interface PaletteEntry {
  title: string
  url: string
  section?: string
  icon?: React.ComponentType<{ className?: string }>
}

function flattenSections(sections: ReturnType<typeof useNavSections>['sections']): PaletteEntry[] {
  const entries: PaletteEntry[] = []
  for (const section of sections) {
    for (const item of section.items) {
      if (item.items?.length) {
        for (const sub of item.items) {
          entries.push({ title: sub.title, url: sub.url, section: item.title, icon: sub.icon })
        }
      } else if (item.url && item.url !== '#') {
        entries.push({ title: item.title, url: item.url, section: section.label, icon: item.icon })
      }
    }
  }
  // Dedup por url (un módulo puede vivir en dos sistemas)
  const seen = new Set<string>()
  return entries.filter((e) => (seen.has(e.url) ? false : (seen.add(e.url), true)))
}

function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

interface CommandPaletteProps {
  open: boolean
  onOpenChange: (_open: boolean) => void
}

/**
 * Paleta de comandos (⌘K) — navegación rápida a los módulos visibles
 * para el usuario (mismo filtrado de permisos que el sidebar).
 */
export function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
  const { sections } = useNavSections()
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const listRef = useRef<HTMLDivElement>(null)

  const entries = useMemo(() => flattenSections(sections), [sections])

  const results = useMemo(() => {
    const q = normalize(query.trim())
    if (!q) return entries
    return entries.filter(
      (e) => normalize(e.title).includes(q) || (e.section && normalize(e.section).includes(q)),
    )
  }, [entries, query])

  const handleOpenChange = (nextOpen: boolean) => {
    if (!nextOpen) {
      setQuery('')
      setActiveIndex(0)
    }
    onOpenChange(nextOpen)
  }

  const goTo = (entry: PaletteEntry) => {
    onOpenChange(false)
    navigate(entry.url)
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, results.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter' && results[activeIndex]) {
      e.preventDefault()
      goTo(results[activeIndex])
    }
  }

  useEffect(() => {
    const active = listRef.current?.querySelector('[data-active="true"]')
    active?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex])

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="top-[20%] max-w-lg translate-y-0 gap-0 p-0"
        aria-describedby={undefined}
        showCloseButton={false}
      >
        <DialogTitle className="sr-only">Buscar módulo</DialogTitle>
        <div className="flex items-center gap-2.5 border-b border-[var(--line-2)] px-4">
          <Search className="size-4 flex-none text-[var(--ink-4)]" aria-hidden />
          <input
            autoFocus
            value={query}
            onChange={(e) => {
              setQuery(e.target.value)
              setActiveIndex(0)
            }}
            onKeyDown={handleKeyDown}
            placeholder="Buscar módulo o pantalla…"
            className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-[var(--ink-4)]"
            aria-label="Buscar módulo o pantalla"
          />
          <kbd className="rounded-[5px] border border-border px-1.5 py-0.5 text-[10.5px] text-[var(--ink-4)]">
            esc
          </kbd>
        </div>
        <div ref={listRef} className="max-h-[320px] overflow-y-auto p-1.5">
          {results.length === 0 ? (
            <p className="px-3 py-6 text-center text-[13px] text-[var(--ink-3)]">
              Sin resultados para “{query}”.
            </p>
          ) : (
            results.map((entry, index) => {
              const Icon = entry.icon
              return (
                <button
                  key={entry.url}
                  type="button"
                  data-active={index === activeIndex}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => goTo(entry)}
                  className="flex w-full items-center gap-2.5 rounded-[9px] px-3 py-2.5 text-left text-[13.5px] text-[var(--ink-2)] transition-colors data-[active=true]:bg-[var(--surface-2)] data-[active=true]:text-foreground"
                >
                  {Icon ? <Icon className="size-4 flex-none text-[var(--ink-3)]" /> : null}
                  <span className="font-medium">{entry.title}</span>
                  {entry.section && (
                    <span className="ml-auto text-[11.5px] text-[var(--ink-4)]">{entry.section}</span>
                  )}
                </button>
              )
            })
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
