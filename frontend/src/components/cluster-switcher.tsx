import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { apiClient } from '@/shared/api/apiClient'
import { queryClient } from '@/shared/api/queryClient'
import { sessionStore } from '@/shared/auth/sessionStore'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import { useQuery } from '@tanstack/react-query'
import { Check, ChevronDown } from 'lucide-react'
import { useStore } from 'zustand'

interface TenantOption {
  id: string
  name: string
}

async function fetchTenants(): Promise<TenantOption[]> {
  const { data } = await apiClient.post<{ data: TenantOption[] }>('/tenant/search?limit=100', {
    sort: [{ field: 'name', direction: 'asc' }],
  })
  return data.data
}

function SwitcherShell({
  name,
  logoUrl,
  interactive,
}: {
  name: string
  logoUrl?: string | null
  interactive?: boolean
}) {
  return (
    <span className='flex w-full items-center gap-2.5 rounded-[10px] border border-white/12 bg-white/5 px-3 py-2.5 text-left transition-colors group-hover:bg-white/10'>
      {logoUrl ? (
        <span className='grid size-[30px] flex-none place-items-center overflow-hidden rounded-lg bg-white'>
          <img src={logoUrl} alt='' className='size-full object-contain' />
        </span>
      ) : (
        <span className='grid size-[30px] flex-none place-items-center rounded-lg bg-[var(--green)] text-[13px] font-semibold text-white'>
          {name.charAt(0).toUpperCase()}
        </span>
      )}
      <span className='min-w-0 flex-1 leading-tight'>
        <span className='block text-[9.5px] font-semibold uppercase tracking-[0.16em] text-[var(--nav-lbl)]'>
          Condominio
        </span>
        <b className='block truncate text-[13.5px] font-semibold text-white'>{name}</b>
      </span>
      {interactive && (
        <ChevronDown className='size-3.5 flex-none text-[var(--nav-txt)] opacity-70' />
      )}
    </span>
  )
}

/**
 * Switcher de condominio del sidebar (diseño v3).
 * - Usuarios del condominio: chip informativo con su condominio.
 * - Super admin: selector cross-tenant (manda X-Tenant-Id en cada request).
 */
export function ClusterSwitcher() {
  const profileTenant = useStore(sessionStore, (s) => s.profile?.tenant)
  const isSuperAdmin = useRoles(ERoleUserSlug.SUPER_ADMIN)
  const { tenantId, tenantName, setTenant } = useTenantContextStore()

  const { data: tenants = [] } = useQuery({
    queryKey: ['tenants', 'switcher'],
    queryFn: fetchTenants,
    enabled: Boolean(isSuperAdmin),
    staleTime: 5 * 60 * 1000,
  })

  const currentName =
    (isSuperAdmin ? tenantName : null) ?? profileTenant?.name ?? APP_CONFIG.brandName

  // El logo viene de la sesión, que es la del condominio propio del usuario. Un
  // super admin parado en OTRO condominio no lo tiene, así que ahí cae a la inicial.
  const isViewingOwnCluster = !isSuperAdmin || !tenantId || tenantId === profileTenant?.id
  const currentLogoUrl = isViewingOwnCluster ? profileTenant?.logo_url : null

  if (!isSuperAdmin) {
    return (
      <div className='group px-1.5 pb-2'>
        <SwitcherShell name={currentName} logoUrl={currentLogoUrl} />
      </div>
    )
  }

  const handleSelect = (tenant: TenantOption) => {
    setTenant(tenant.id, tenant.name)
    // El contexto del condominio cambió: todo lo cacheado pertenece al anterior
    void queryClient.invalidateQueries()
  }

  return (
    <div className='px-1.5 pb-2'>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button type='button' className='group w-full' aria-label='Cambiar de condominio'>
            <SwitcherShell name={currentName} logoUrl={currentLogoUrl} interactive />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align='start' className='w-[240px] max-h-[320px] overflow-y-auto'>
          {tenants.map((tenant) => (
            <DropdownMenuItem
              key={tenant.id}
              onSelect={() => handleSelect(tenant)}
              className='flex items-center justify-between'
            >
              <span className='truncate'>{tenant.name}</span>
              {tenant.id === tenantId && <Check className='size-4 text-[var(--green)]' />}
            </DropdownMenuItem>
          ))}
          {tenants.length === 0 && (
            <p className='px-3 py-2 text-[12.5px] text-[var(--ink-3)]'>Sin condominios.</p>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
