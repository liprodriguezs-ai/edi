'use client'

import { ChevronRight } from 'lucide-react'
import { Fragment, useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'

import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import {
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
} from '@/components/ui/sidebar'
import type { NavItem, NavSection, NavSubItem } from '@/shared/hooks/useFilteredNavItems'
import { cn } from '@/lib/utils'
import { useCloseMobileSidebar } from '@/shared/hooks/useCloseMobileSidebar'
import { groupNavSubItems } from '@/shared/utils/groupNavSubItems'

/**
 * Una ruta está activa si coincide exacto con la URL del item o es un sub-path
 * suyo (ej. la página de detalle `/unit/:id` mantiene activo el item `/unit`).
 */
function isUrlActive(pathname: string, url: string): boolean {
  if (!url || url === '#') {
    return false
  }
  return pathname === url || pathname.startsWith(`${url}/`)
}

function NavSubItemLink({ subItem, pathname }: { subItem: NavSubItem; pathname: string }) {
  const closeMobileSidebar = useCloseMobileSidebar()

  return (
    <SidebarMenuSubItem>
      <SidebarMenuSubButton asChild isActive={isUrlActive(pathname, subItem.url)}>
        <Link to={subItem.url} onClick={closeMobileSidebar}>
          <span>{subItem.title}</span>
        </Link>
      </SidebarMenuSubButton>
    </SidebarMenuSubItem>
  )
}

/**
 * Etiqueta muda de sub-sección (ej. "RRHH", "Tesorería") entre bloques de módulos
 * dentro de un grupo colapsable (ej. "Catálogos"). No es focusable ni interactiva
 * — es texto plano, así que no rompe la navegación por teclado entre los links.
 */
function NavSubGroupLabel({ label, isFirst }: { label: string; isFirst: boolean }) {
  return (
    <SidebarMenuSubItem className={cn(isFirst ? 'mt-1' : 'mt-3')}>
      <span className='block truncate px-2 pb-1 text-[10px] font-medium tracking-wider text-sidebar-foreground/50 uppercase select-none'>
        {label}
      </span>
    </SidebarMenuSubItem>
  )
}

function NavMenuItem({ item, pathname }: { item: NavItem; pathname: string }) {
  // NavMain re-renderiza en cada cambio de ruta (useLocation); item.items es
  // referencialmente estable entre navegaciones, así que memoizar evita
  // reconstruir Map/Set/sort del agrupamiento en cada una.
  const groupedBlocks = useMemo(
    () => (item.items?.length ? groupNavSubItems(item.items) : null),
    [item.items]
  )
  const closeMobileSidebar = useCloseMobileSidebar()

  if (item.items?.length) {
    const isGroupActive = item.items.some((sub) => isUrlActive(pathname, sub.url))

    return (
      <Collapsible asChild defaultOpen={isGroupActive} className='group/collapsible'>
        <SidebarMenuItem>
          <CollapsibleTrigger asChild>
            <SidebarMenuButton tooltip={item.title} isActive={isGroupActive}>
              {item.icon && <item.icon />}
              <span>{item.title}</span>
              <ChevronRight className='ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90' />
            </SidebarMenuButton>
          </CollapsibleTrigger>
          <CollapsibleContent>
            <SidebarMenuSub>
              {groupedBlocks
                ? groupedBlocks.map((block, blockIdx) => (
                    <Fragment key={block.label}>
                      <NavSubGroupLabel label={block.label} isFirst={blockIdx === 0} />
                      {block.items.map((subItem) => (
                        <NavSubItemLink key={subItem.title} subItem={subItem} pathname={pathname} />
                      ))}
                    </Fragment>
                  ))
                : item.items.map((subItem) => (
                    <NavSubItemLink key={subItem.title} subItem={subItem} pathname={pathname} />
                  ))}
            </SidebarMenuSub>
          </CollapsibleContent>
        </SidebarMenuItem>
      </Collapsible>
    )
  }

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild tooltip={item.title} isActive={isUrlActive(pathname, item.url)}>
        <Link to={item.url} onClick={closeMobileSidebar}>
          {item.icon && <item.icon />}
          <span>{item.title}</span>
        </Link>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}

export function NavMain({ sections }: { sections: NavSection[] }) {
  const { pathname } = useLocation()

  return (
    <>
      {sections.map((section, idx) => (
        <SidebarGroup key={section.label ?? idx}>
          {section.label && <SidebarGroupLabel>{section.label}</SidebarGroupLabel>}
          <SidebarMenu>
            {section.items.map((item) => (
              <NavMenuItem key={item.title} item={item} pathname={pathname} />
            ))}
          </SidebarMenu>
        </SidebarGroup>
      ))}
    </>
  )
}
