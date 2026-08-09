import { CommandPalette } from '@/components/command-palette'
import { DocsHelpButton } from '@/components/docs-help-button'
import { NotificationBell } from '@/components/notification-bell'
import { Separator } from '@/components/ui/separator'
import { SidebarTrigger } from '@/components/ui/sidebar'
import { OnboardingProgressPill } from '@/modules/onboarding/components/OnboardingProgressPill'
import { sessionStore } from '@/shared/auth/sessionStore'
import { useCommandPalette } from '@/shared/store/commandPaletteStore'
import { Search } from 'lucide-react'
import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { useStore } from 'zustand'

const SECTION_LABELS: Record<string, string> = {
  dashboard: 'Inicio',
  onboarding: 'Configuración inicial',
  user: 'Usuarios',
  role: 'Roles',
  tenant: 'Tenants',
  audit: 'Auditoría',
  company: 'Empresas',
  collaborator: 'Personal',
  tower: 'Torres',
  section: 'Secciones',
  'unit-feature': 'Características de Unidad',
  'user-profile': 'Perfil de Residente',
  unit: 'Unidades',
  'unit-user': 'Residentes por Unidad',
  vehicle: 'Vehículos',
  'board-period': 'Mesa Directiva',
  'board-member': 'Miembros de Mesa',
  'bank-account': 'Cuentas Bancarias',
  'expense-category': 'Categorías de Egreso',
  'charge-concept': 'Conceptos de Cobro',
  'charge-policy': 'Programación de Cobros',
  'charge-batch': 'Cobros',
  charge: 'Cargos',
  payment: 'Pagos',
  statement: 'Estado de Cuenta',
  delinquency: 'Morosidad',
  expense: 'Egresos',
  provider: 'Proveedores',
  'community-project': 'Proyectos Comunitarios',
  'project-contribution': 'Aportaciones',
  announcement: 'Avisos',
  'chat-channel': 'Chat Comunitario',
  access: 'Control de Accesos',
  visitor: 'Visitantes',
  'access-log': 'Bitácora de Accesos',
  blacklist: 'Lista Negra',
  reports: 'Reportes',
  'scheduled-reports': 'Reportes Programados',
  configuration: 'Configuración',
  notification: 'Notificaciones',
  email: 'Correos',
  // Catálogos
  'unit-type': 'Tipos de Unidad',
  'unit-status': 'Estados de Unidad',
  'unit-relationship': 'Relaciones de Unidad',
  'employee-position': 'Puestos',
  'pay-frequency': 'Frecuencias de Pago',
  'employee-shift': 'Turnos',
  'bank-account-purpose': 'Propósitos de Cuenta',
  'charge-concept-category': 'Categorías de Concepto',
  'community-project-category': 'Categorías de Proyecto',
  'provider-type': 'Tipos de Proveedor',
  'provider-service-type': 'Tipos de Servicio',
  'visitor-motive': 'Motivos de Visita',
  'announcement-type': 'Tipos de Aviso',
  amenity: 'Amenidades',
  amenities: 'Amenidades',
  'amenity-reservation': 'Reservaciones',
  'my-reservations': 'Mis Reservaciones',
  'amenity-occupancy': 'Calendario de Ocupación',
}

function currentSection(pathname: string): string {
  const seg = pathname.split('/').filter(Boolean)[0] ?? 'dashboard'
  return SECTION_LABELS[seg] ?? seg.charAt(0).toUpperCase() + seg.slice(1)
}

export function SiteHeader() {
  const { pathname } = useLocation()
  const tenantName = useStore(sessionStore, (s) => s.profile?.tenant?.name)
  const paletteOpen = useCommandPalette((s) => s.open)
  const setPaletteOpen = useCommandPalette((s) => s.setOpen)
  const togglePalette = useCommandPalette((s) => s.toggle)

  // Atajo global ⌘K / Ctrl+K
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        togglePalette()
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [togglePalette])

  return (
    <header className='flex h-(--header-height) shrink-0 items-center gap-2 border-b border-border bg-muted/90 px-3 backdrop-blur sm:px-4 lg:px-6 pt-[env(safe-area-inset-top)]'>
      <SidebarTrigger className='-ml-1' />
      <Separator orientation='vertical' className='mx-1 data-[orientation=vertical]:h-4' />
      <nav
        className='flex items-center gap-2 text-[13px] text-[var(--ink-3)]'
        aria-label='Migas de pan'
      >
        {tenantName && (
          <>
            <span className='hidden sm:inline'>{tenantName}</span>
            <span className='hidden text-[var(--ink-4)] sm:inline'>/</span>
          </>
        )}
        <span className='min-w-0 truncate font-semibold text-foreground'>
          {currentSection(pathname)}
        </span>
      </nav>

      <div className='flex-1' />

      <OnboardingProgressPill />

      <button
        type='button'
        onClick={() => setPaletteOpen(true)}
        className='hidden h-9 w-[260px] items-center gap-2.5 rounded-full border border-border bg-card px-4 text-[13px] text-[var(--ink-3)] transition-colors hover:border-[var(--ink-4)] md:flex'
        aria-label='Buscar módulo (atajo ⌘K)'
      >
        <Search className='size-3.5 flex-none' aria-hidden />
        Buscar módulo…
        <kbd className='ml-auto rounded-[5px] border border-border px-1.5 py-px text-[10.5px] text-[var(--ink-4)]'>
          ⌘K
        </kbd>
      </button>
      <button
        type='button'
        onClick={() => setPaletteOpen(true)}
        className='grid size-9 place-items-center rounded-full border border-border bg-card text-[var(--ink-2)] md:hidden'
        aria-label='Buscar módulo'
      >
        <Search className='size-4' aria-hidden />
      </button>

      <DocsHelpButton />

      <NotificationBell />

      <CommandPalette open={paletteOpen} onOpenChange={setPaletteOpen} />
    </header>
  )
}
