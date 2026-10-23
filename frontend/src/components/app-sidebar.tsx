import * as React from 'react'

import { BrandWordmark } from '@/components/auth/BrandWordmark'
import { OrganizationSwitcher } from '@/components/organization-switcher'
import { NavMain } from '@/components/nav-main'
import { NavSkeleton } from '@/components/nav-skeleton'
import { NavUser } from '@/components/nav-user'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
} from '@/components/ui/sidebar'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { useCloseMobileSidebar } from '@/shared/hooks/useCloseMobileSidebar'
import { useNavSections } from '@/shared/hooks/useNavSections'
import { useCommandPalette } from '@/shared/store/commandPaletteStore'
import { Skeleton } from 'boneyard-js/react'
import { Search } from 'lucide-react'

export function AppSidebar({ ...props }: React.ComponentProps<typeof Sidebar>) {
  const { sections, isLoading } = useNavSections()
  const openPalette = useCommandPalette((s) => s.setOpen)
  const closeMobileSidebar = useCloseMobileSidebar()

  // En móvil el sidebar es un Sheet a pantalla completa: hay que cerrarlo antes
  // de abrir la paleta, si no la paleta queda peleando con el menú encima.
  const handleOpenPalette = () => {
    closeMobileSidebar()
    openPalette(true)
  }

  return (
    <Sidebar collapsible='icon' {...props}>
      <SidebarHeader>
        <div className='flex items-center px-1.5 py-2'>
          {/* Wordmark completo cuando está expandido; solo el badge en modo ícono */}
          <BrandWordmark tone='light' className='group-data-[collapsible=icon]:hidden' />
          <span
            aria-hidden
            className='hidden size-8 flex-none place-items-center rounded-none bg-white group-data-[collapsible=icon]:grid'
          >
            <img src='/icons/edi-mark.svg' alt='' className='h-6 w-auto' />
          </span>
        </div>

        {/* Acceso a la paleta de comandos (⌘K) */}
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton tooltip='Buscar módulo (⌘K)' onClick={handleOpenPalette}>
              <Search />
              <span>Buscar módulo…</span>
              <kbd className='ml-auto rounded-none border border-border px-1.5 py-px text-[10px] text-[var(--ink-4)] group-data-[collapsible=icon]:hidden'>
                ⌘K
              </kbd>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>

        {/* El selector de organización no aplica en modo ícono: se oculta al colapsar */}
        <div className='group-data-[collapsible=icon]:hidden'>
          <OrganizationSwitcher />
        </div>
      </SidebarHeader>

      <SidebarContent>
        <Skeleton name='sidebar-menu' loading={isLoading} fallback={<NavSkeleton />}>
          <NavMain sections={sections} />
        </Skeleton>
      </SidebarContent>

      <SidebarFooter>
        <NavUser />
        <p className='px-2 pb-1 text-[11px] text-[var(--ink-4)] group-data-[collapsible=icon]:hidden'>
          {APP_CONFIG.brandName} · v{APP_CONFIG.appVersion}
        </p>
      </SidebarFooter>

      <SidebarRail />
    </Sidebar>
  )
}
