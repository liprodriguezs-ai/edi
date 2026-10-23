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
  tenant: 'Organizaciones',
  audit: 'Auditoría',
  company: 'Empresas',
  'user-profile': 'Perfil de Usuario',
  reports: 'Reportes',
  notification: 'Notificaciones',
  'notification-preferences': 'Preferencias de notificación',
  subscription: 'Suscripción',
  'billing-package': 'Planes y Precios',
  'select-plan': 'Elegir plan',
  'acceso-denegado': 'Acceso denegado',
  child: 'Niños',
  visit: 'Visitas',
  screening: 'Prueba EDI',
  'corrected-age': 'Edad corregida',
  'follow-up': 'Seguimientos',
  'health-center': 'Centros de salud',
  practitioner: 'Profesionales',
  instrument: 'Instrumento',
  'stimulation-session': 'Sesiones de estimulación',
  'stimulation-group': 'Grupos de estimulación',
  'stimulation-group-type': 'Tipos de grupo',
  'stimulation-dynamic': 'Dinámicas de estimulación',
  'body-zone': 'Zonas corporales',
  'massage-movement': 'Movimientos de masaje',
  'didactic-material': 'Materiales didácticos',
  song: 'Canciones',
  'caregiver-relationship': 'Parentescos',
  'education-level': 'Escolaridades',
  occupation: 'Ocupaciones',
  'referral-specialty': 'Especialidades de referencia',
  'scheduled-reports': 'Reportes programados',
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
        className='hidden h-9 w-[260px] items-center gap-2.5 rounded-none border border-border bg-card px-4 text-[13px] text-[var(--ink-3)] transition-colors hover:border-[var(--ink-4)] md:flex'
        aria-label='Buscar módulo (atajo ⌘K)'
      >
        <Search className='size-3.5 flex-none' aria-hidden />
        Buscar módulo…
        <kbd className='ml-auto rounded-none border border-border px-1.5 py-px text-[10.5px] text-[var(--ink-4)]'>
          ⌘K
        </kbd>
      </button>
      <button
        type='button'
        onClick={() => setPaletteOpen(true)}
        className='grid size-9 place-items-center rounded-none border border-border bg-card text-[var(--ink-2)] md:hidden'
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
