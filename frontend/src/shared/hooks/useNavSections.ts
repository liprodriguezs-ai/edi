import { IconFileDescription, IconQrcode } from '@tabler/icons-react'
import { useMemo } from 'react'

import type { NavItem, NavSection } from '@/shared/hooks/useFilteredNavItems'
import { useFilteredNavSections } from '@/shared/hooks/useFilteredNavItems'
import { useMenu } from '@/shared/hooks/useMenu'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import type { IMenuItem, IMenuSystem } from '@/shared/interfaces/MenuSystem'
import { getIcon } from '@/shared/utils/iconMap'

/**
 * Items fijos que no dependen de sistemas — se muestran por role.
 */
const fixedNavItems: NavItem[] = [
  {
    title: 'Mi expediente',
    url: '/collaborator/me',
    icon: IconFileDescription,
    roles: [ERoleUserSlug.OPERATIONS_STAFF],
  },
  {
    // QR personal rotativo del residente/inquilino para registrar su propio
    // ingreso en la caseta (self-page; no requiere permiso de módulo).
    title: 'Mi QR',
    url: '/my-qr',
    icon: IconQrcode,
    roles: [ERoleUserSlug.RESIDENT, ERoleUserSlug.RENTER],
  },
]

/**
 * Convierte un item de la API (module o group) a NavItem.
 */
function systemItemToNavItem(item: IMenuItem): NavItem | null {
  if (item.type === 'module') {
    if (!item.route) return null
    return {
      title: item.name,
      url: item.route,
      icon: getIcon(item.icon),
      permissionEntity: item.permission_entity ?? undefined,
    }
  }

  const subItems = item.modules
    .filter((mod) => mod.route)
    .map((mod) => ({
      title: mod.name,
      url: mod.route!,
      icon: getIcon(mod.icon),
      permissionEntity: mod.permission_entity ?? undefined,
      group: mod.group ?? undefined,
    }))
    .sort((a, b) => a.title.localeCompare(b.title, 'es'))

  if (subItems.length === 0) return null

  return {
    title: item.label,
    url: '#',
    icon: getIcon(item.icon),
    items: subItems,
  }
}

/**
 * Transforma los sistemas de la API en NavSection[] para el sidebar.
 * Cada sistema se convierte en una sección con label y sus items.
 */
function systemsToSections(systems: IMenuSystem[]): NavSection[] {
  return systems
    .map((system) => ({
      label: system.name,
      items: system.items.map(systemItemToNavItem).filter(Boolean) as NavItem[],
    }))
    .filter((section) => section.items.length > 0)
}

/**
 * Secciones de navegación (menú dinámico /api/menu + items fijos),
 * ya filtradas por permisos/roles del usuario. Las consumen el sidebar
 * y la paleta de comandos (⌘K).
 */
export function useNavSections() {
  const { systems, isLoading } = useMenu()

  const sections = useMemo<NavSection[]>(() => {
    const systemSections = systemsToSections(systems)

    // Dedup: quitar de los sistemas los items que están en fixedNavItems
    const fixedUrls = new Set(fixedNavItems.map((item) => item.url))
    const dedupedSections = systemSections
      .map((section) => ({
        ...section,
        items: section.items
          .map((item) => {
            if (item.items) {
              return { ...item, items: item.items.filter((sub) => !fixedUrls.has(sub.url)) }
            }
            return fixedUrls.has(item.url) ? null : item
          })
          .filter(Boolean) as NavItem[],
      }))
      .filter((section) => section.items.length > 0)

    // Menú continuo: los sistemas se fusionan en una sola sección sin labels
    // ("Fraccionamiento"/"Tesorería"), con los fixed items al inicio. El orden
    // entre sistemas y dentro de cada uno lo da el sort_order del backend.
    const systemItems = dedupedSections.flatMap((section) => section.items)
    return [{ items: [...fixedNavItems, ...systemItems] }]
  }, [systems])

  const filteredSections = useFilteredNavSections(sections)

  return { sections: filteredSections, isLoading }
}
