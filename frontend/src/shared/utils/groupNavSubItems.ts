import type { NavSubItem } from '@/shared/hooks/useFilteredNavItems'

const OTHERS_LABEL = 'Otros'

export interface NavSubItemGroupBlock {
  label: string
  items: NavSubItem[]
}

/**
 * Agrupa los subItems de un item colapsable del sidebar (ej. "Catálogos") por el
 * módulo dueño (`group`), con grupos e items ordenados alfabéticamente.
 *
 * Devuelve `null` cuando NO conviene agrupar (0 o 1 valor distinto y no vacío de
 * `group` entre los subItems) — así el caller renderiza la lista plana tal como
 * la recibe (useNavSections ya la entrega ordenada alfabéticamente).
 */
export function groupNavSubItems(subItems: NavSubItem[]): NavSubItemGroupBlock[] | null {
  const groupedItems = subItems.filter(hasGroup)
  const ungroupedItems = subItems.filter((item) => !hasGroup(item))

  const itemsByLabel = new Map<string, NavSubItem[]>()
  for (const item of groupedItems) {
    const label = item.group.trim()
    const bucket = itemsByLabel.get(label)
    if (bucket) {
      bucket.push(item)
    } else {
      itemsByLabel.set(label, [item])
    }
  }

  if (itemsByLabel.size < 2) {
    return null
  }

  // Los items sin `group` van bajo "Otros". Si el backend trae un grupo real
  // llamado "Otros", se fusionan en ese bucket para no producir dos bloques con
  // la misma etiqueta (la key de React se deriva del label).
  const hasRealOthersGroup = itemsByLabel.has(OTHERS_LABEL)
  if (ungroupedItems.length > 0 && hasRealOthersGroup) {
    itemsByLabel.get(OTHERS_LABEL)?.push(...ungroupedItems)
  }

  const blocks: NavSubItemGroupBlock[] = Array.from(itemsByLabel.keys())
    .sort((a, b) => a.localeCompare(b, 'es'))
    .map((label) => ({
      label,
      items: sortByTitle(itemsByLabel.get(label) ?? []),
    }))

  if (ungroupedItems.length > 0 && !hasRealOthersGroup) {
    blocks.push({ label: OTHERS_LABEL, items: sortByTitle(ungroupedItems) })
  }

  return blocks
}

function hasGroup(item: NavSubItem): item is NavSubItem & { group: string } {
  return Boolean(item.group && item.group.trim().length > 0)
}

function sortByTitle(items: NavSubItem[]): NavSubItem[] {
  return [...items].sort((a, b) => a.title.localeCompare(b.title, 'es'))
}
